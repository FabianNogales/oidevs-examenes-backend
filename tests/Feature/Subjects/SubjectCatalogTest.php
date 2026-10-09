<?php

namespace Tests\Feature\Subjects;

use App\Enums\RoleName;
use App\Models\Career;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class SubjectCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
    }

    public function test_all_read_endpoints_require_active_administrator(): void
    {
        $subject = $this->subject();
        $paths = ['/api/v1/admin/subjects', '/api/v1/admin/subjects/careers', '/api/v1/admin/subjects/'.$subject->id];
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach ([RoleName::DOCENTE, RoleName::ESTUDIANTE] as $role) {
            $this->authenticate($role);
            foreach ($paths as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
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
            foreach ($paths as $path) {
                $this->getJson($path)->assertForbidden();
            }
        }
    }

    public function test_first_access_and_replaced_sessions_are_rejected(): void
    {
        $user = $this->authenticate();
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/admin/subjects')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'active_session_id' => 'other-session'])->save();
        $this->getJson('/api/v1/admin/subjects')->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }

    public function test_empty_catalog_clamps_page_and_missing_detail_returns_404(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/admin/subjects?page=99')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
        $this->getJson('/api/v1/admin/subjects/99999')->assertNotFound();
        $this->getJson('/api/v1/admin/subjects/not-an-id')->assertNotFound();
    }

    public function test_pagination_is_stable_and_adjusts_to_last_page(): void
    {
        $this->authenticate();
        for ($i = 1; $i <= 17; $i++) {
            $this->subject('MAT-'.$i);
        }
        $this->getJson('/api/v1/admin/subjects')->assertOk()->assertJsonCount(15, 'data')
            ->assertJsonPath('data.0.code', 'MAT-1')->assertJsonPath('meta.total', 17);
        $this->getJson('/api/v1/admin/subjects?page=99')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.from', 16)->assertJsonPath('meta.to', 17);
        $this->getJson('/api/v1/admin/subjects?page=99&per_page=10')->assertOk()->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 10);
    }

    public function test_filters_are_combined_without_duplicates_and_escape_wildcards(): void
    {
        $this->authenticate();
        $first = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $second = Career::create(['code' => 'INF', 'name' => 'Informática', 'status' => 'ACTIVE']);
        $subject = $this->subject('INF-01', 'Programación I');
        $subject->careers()->attach([$first->id, $second->id]);
        $this->subject('INF-02', 'Programación II', 'INACTIVE')->careers()->attach($first->id);
        $this->subject('OTHER', 'Programación III');
        $this->subject('A%_!01', 'Literal');
        $this->getJson('/api/v1/admin/subjects?search=PROGRAM&status=ACTIVE&career_id='.$first->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonCount(2, 'data.0.careers');
        $this->getJson('/api/v1/admin/subjects?search=inf-01')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/subjects?search='.urlencode('%_!'))->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'A%_!01');
    }

    public function test_detail_keeps_historical_careers_and_subjects_without_associations(): void
    {
        $this->authenticate();
        $subject = $this->subject('OLD', 'Materia anterior', 'INACTIVE');
        $this->getJson('/api/v1/admin/subjects/'.$subject->id)->assertOk()
            ->assertExactJson(['data' => ['id' => $subject->id, 'code' => 'OLD', 'name' => 'Materia anterior', 'status' => 'INACTIVE', 'careers' => []]]);
        $career = Career::create(['code' => 'OLD', 'name' => 'Carrera histórica', 'status' => 'INACTIVE']);
        $subject->careers()->attach($career->id);
        $this->getJson('/api/v1/admin/subjects/'.$subject->id)->assertOk()
            ->assertJsonPath('data.careers.0.id', $career->id)->assertJsonPath('data.careers.0.status', 'INACTIVE');
    }

    public function test_career_endpoint_returns_all_sorted_careers_without_pagination(): void
    {
        $this->authenticate();
        Career::create(['code' => 'Z', 'name' => 'Zeta', 'status' => 'ACTIVE']);
        $first = Career::create(['code' => 'A1', 'name' => 'Alfa', 'status' => 'INACTIVE']);
        $second = Career::create(['code' => 'A2', 'name' => 'Alfa', 'status' => 'ACTIVE']);
        $this->getJson('/api/v1/admin/subjects/careers')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.0.status', 'INACTIVE')->assertJsonMissingPath('meta');
    }

    public function test_invalid_filters_return_field_errors(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/admin/subjects?page=0&per_page=101&status=OCCUPIED&career_id=99999&search='.str_repeat('a', 151))
            ->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page', 'status', 'career_id', 'search']);
    }

    private function subject(string $code = 'MAT101', string $name = 'Matemáticas', string $status = 'ACTIVE'): Subject
    {
        return Subject::create(compact('code', 'name', 'status'));
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
