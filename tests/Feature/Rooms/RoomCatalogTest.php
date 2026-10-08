<?php

namespace Tests\Feature\Rooms;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class RoomCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
    }

    public function test_guest_and_non_administrators_cannot_read_catalog(): void
    {
        $this->getJson('/api/v1/admin/rooms')->assertUnauthorized();
        $room = Room::create(['code' => 'A-01', 'status' => 'ACTIVE']);
        $this->getJson('/api/v1/admin/rooms/'.$room->id)->assertUnauthorized();
        foreach ([RoleName::DOCENTE, RoleName::ESTUDIANTE] as $role) {
            $this->authenticate($role);
            $this->getJson('/api/v1/admin/rooms')->assertForbidden();
            $this->getJson('/api/v1/admin/rooms/'.$room->id)->assertForbidden();
        }
    }

    public function test_inactive_account_role_and_assignment_are_rejected(): void
    {
        foreach (['account', 'role', 'assignment'] as $case) {
            $user = $this->authenticate();
            if ($case === 'account') {
                $user->update(['status' => 'INACTIVE']);
            } elseif ($case === 'role') {
                $user->roles()->first()->update(['status' => 'INACTIVE']);
            } else {
                $user->roles()->updateExistingPivot($user->roles()->first()->id, ['status' => 'INACTIVE']);
            }
            $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        }
    }

    public function test_empty_catalog_and_missing_detail_follow_contract(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/admin/rooms')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
        $this->getJson('/api/v1/admin/rooms/9999')->assertNotFound();
    }

    public function test_catalog_paginates_both_statuses_in_stable_order(): void
    {
        $this->authenticate();
        for ($i = 1; $i <= 17; $i++) {
            Room::create(['code' => 'A-'.$i, 'name' => 'Aula '.$i, 'status' => $i % 2 ? 'ACTIVE' : 'INACTIVE']);
        }
        $this->getJson('/api/v1/admin/rooms')->assertOk()->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 17)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 15)->assertJsonPath('data.0.code', 'A-1');
        $this->getJson('/api/v1/admin/rooms?page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.from', 16)->assertJsonPath('meta.to', 17);
        $this->getJson('/api/v1/admin/rooms?status=INACTIVE')->assertOk()
            ->assertJsonCount(8, 'data')->assertJsonPath('meta.total', 8);
    }

    public function test_search_matches_code_and_name_without_case_and_escapes_wildcards(): void
    {
        $this->authenticate();
        Room::create(['code' => 'LAB-01', 'name' => 'Auditorio Central', 'status' => 'ACTIVE']);
        Room::create(['code' => 'A%_01', 'name' => null, 'status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/rooms?search=lab')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/rooms?search=AUDITORIO')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/rooms?search=%25_')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'A%_01');
        $this->getJson('/api/v1/admin/rooms?search=missing')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_detail_includes_nullable_fields_and_numeric_capacity(): void
    {
        $this->authenticate();
        $room = Room::create(['code' => 'A-01', 'name' => null, 'capacity' => 50, 'floor' => 'PB', 'status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/rooms/'.$room->id)->assertOk()
            ->assertJsonPath('data.id', $room->id)->assertJsonPath('data.capacity', 50)
            ->assertJsonPath('data.name', null)->assertJsonPath('data.description', null)
            ->assertJsonPath('data.status', 'INACTIVE')->assertJsonPath('data.availability', 'UNKNOWN')
            ->assertJsonMissingPath('data.current_exam');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/admin/rooms?status=OCCUPIED&page=0&per_page=100')
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'page', 'per_page']);
    }

    public function test_first_access_and_replaced_session_are_rejected(): void
    {
        $user = $this->authenticate();
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/admin/rooms')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'active_session_id' => 'another-session'])->save();
        $this->getJson('/api/v1/admin/rooms')->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }

    private function authenticate(RoleName $name = RoleName::ADMINISTRADOR): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => $name->value], ['status' => 'ACTIVE']);
        $role->update(['status' => 'ACTIVE']);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $user->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($user);
        $this->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());

        return $user;
    }
}
