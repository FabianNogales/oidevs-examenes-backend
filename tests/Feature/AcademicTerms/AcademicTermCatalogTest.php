<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\AcademicTerm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AcademicTermCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
    }

    public function test_all_routes_reject_guests_and_non_administrators(): void
    {
        $term = $this->term();
        foreach ($this->paths($term->id) as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach (['DOCENTE', 'ESTUDIANTE'] as $role) {
            $this->authenticate($role);
            foreach ($this->paths($term->id) as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
    }

    public function test_inactive_account_role_or_assignment_returns_403_on_each_route(): void
    {
        $term = $this->term();
        foreach (['account', 'role', 'assignment'] as $case) {
            $user = $this->authenticate();
            $role = $user->roles()->first();
            if ($case === 'account') {
                $user->update(['status' => 'INACTIVE']);
            } elseif ($case === 'role') {
                $role->update(['status' => 'INACTIVE']);
            } else {
                $user->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']);
            }
            foreach ($this->paths($term->id) as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
    }

    public function test_first_access_and_replaced_session_preserve_existing_codes(): void
    {
        $user = $this->authenticate();
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/admin/academic-terms')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'active_session_id' => 'other-session'])->save();
        $this->getJson('/api/v1/admin/academic-terms')->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }

    public function test_empty_listing_and_active_null_follow_contract(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/admin/academic-terms?page=99')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
        $this->getJson('/api/v1/admin/academic-terms/active')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_detail_is_date_only_and_unknown_id_is_404(): void
    {
        $this->authenticate();
        $term = $this->term();
        $this->getJson('/api/v1/admin/academic-terms/'.$term->id)->assertOk()->assertExactJson(['data' => [
            'id' => $term->id, 'name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'INACTIVE',
        ]]);
        $this->getJson('/api/v1/admin/academic-terms/99999')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/admin/academic-terms/abc')->assertNotFound();
        $this->getJson('/api/v1/admin/academic-terms/0')->assertNotFound();
    }

    public function test_order_pagination_and_out_of_range_adjustment(): void
    {
        $this->authenticate();
        for ($i = 1; $i <= 17; $i++) {
            $this->term(['name' => 'Periodo '.$i, 'start_date' => sprintf('%04d-01-01', 2020 + $i), 'end_date' => sprintf('%04d-06-30', 2020 + $i)]);
        }
        $this->getJson('/api/v1/admin/academic-terms')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('data.0.name', 'Periodo 17');
        $this->getJson('/api/v1/admin/academic-terms?page=99&per_page=10')->assertOk()->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.from', 11)->assertJsonPath('meta.to', 17);
    }

    public function test_filters_combine_and_wildcards_are_literal(): void
    {
        $this->authenticate();
        $this->term(['name' => 'Gestión A%_! 2027', 'status' => 'INACTIVE']);
        $this->term(['name' => 'Gestión normal', 'status' => 'ACTIVE']);
        $this->getJson('/api/v1/admin/academic-terms?search=GEST&status=INACTIVE')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/academic-terms?search='.urlencode('%_!'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/academic-terms/active?status=INACTIVE&search=missing')->assertOk()->assertJsonPath('data.name', 'Gestión normal');
    }

    public function test_multiple_legacy_active_periods_are_reported_without_picking_one(): void
    {
        $this->authenticate();
        $this->term(['status' => 'ACTIVE']);
        $this->term(['name' => 'II/2027', 'status' => 'ACTIVE']);
        $this->getJson('/api/v1/admin/academic-terms/active')->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_TERM_STATE_INCONSISTENT');
        $this->assertSame(2, AcademicTerm::where('status', 'ACTIVE')->count());
    }

    public function test_invalid_filters_have_spanish_field_errors_and_code(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/admin/academic-terms?page=0&per_page=101&status=ACTIVO&search='.str_repeat('a', 151))
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors(['page', 'per_page', 'status', 'search']);
    }

    private function term(array $values = []): AcademicTerm
    {
        return AcademicTerm::create(array_replace(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'INACTIVE'], $values));
    }

    private function paths(int $id): array
    {
        return ['/api/v1/admin/academic-terms', '/api/v1/admin/academic-terms/active', '/api/v1/admin/academic-terms/'.$id];
    }

    private function authenticate(string $name = 'ADMINISTRADOR'): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
        $role->update(['status' => 'ACTIVE']);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $user->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($user)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());

        return $user;
    }
}
