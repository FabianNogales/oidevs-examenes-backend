<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class AdminAccountIntegrationTest extends TestCase
{
    use RefreshDatabase, CourseOfferingFixture;

    private User $administrator;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.sqlite');
        if (! $app->environment('testing') || $app->configurationIsCached() || $app['config']->get('database.default') !== 'sqlite'
            || $connection['database'] !== ':memory:' || ! empty($connection['url'])
            || $app['config']->get('session.driver') !== 'array' || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('These tests require isolated in-memory SQLite, array session/cache and no cached configuration.');
        }
        $pdo = $app['db']->connection()->getPdo();
        foreach ($pdo->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if ($database['file'] !== '') throw new \RuntimeException('Persistent test connection rejected.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ADMINISTRADOR', 'DOCENTE', 'ESTUDIANTE'] as $name) Role::create(['name' => $name, 'status' => 'ACTIVE']);
        $this->administrator = $this->account('ADMINISTRADOR');
        Sanctum::actingAs($this->administrator);
    }

    private function account(?string $role = null): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        if ($role) $user->roles()->attach(Role::where('name', $role)->value('id'), ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $user;
    }

    public function test_list_search_pagination_detail_and_safe_contract(): void
    {
        $user = $this->account('DOCENTE');
        $teacher = Teacher::factory()->create(['user_id' => $user->id, 'first_names' => 'Nombre Unico', 'last_names' => 'Busqueda', 'institutional_code' => 'PROF-REAL']);
        $this->getJson('/api/v1/admin/users?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/users?search=PROF-REAL')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id);
        $this->getJson('/api/v1/admin/users?search=Busqueda%20Nombre')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/users?search=ZZZNOTFOUND')->assertOk()->assertJsonCount(0, 'data');
        $response = $this->getJson('/api/v1/admin/users/'.$user->id)->assertOk()->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.roles.0.name', 'DOCENTE');
        foreach (['password', 'remember_token', 'active_session_id', 'access_code_hash'] as $secret) $this->assertArrayNotHasKey($secret, $response->json('data'));
        $this->getJson('/api/v1/admin/users/999999')->assertNotFound();
        $this->getJson('/api/v1/admin/users?per_page=101')->assertUnprocessable();
    }

    public function test_deactivation_and_reactivation_preserve_profiles_roles_and_revoke_credentials(): void
    {
        $fixture = $this->offeringFixture();
        $user = $fixture['user'];
        $user->forceFill(['active_session_id' => 'old-session'])->save();
        $token = $user->createToken('old-device')->plainTextToken;
        $roles = $user->roles()->count();
        $this->patchJson('/api/v1/admin/users/'.$user->id.'/status', ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->assertNotSame('old-session', $user->refresh()->active_session_id);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('ACTIVE', $fixture['teacher']->refresh()->getRawOriginal('status'));
        $this->assertSame($roles, $user->roles()->count());
        $this->patchJson('/api/v1/admin/users/'.$user->id.'/status', ['status' => 'ACTIVE'])->assertOk();
        $this->assertNotSame('old-session', $user->refresh()->active_session_id);
        $this->assertDatabaseCount('course_offerings', 1);
        $this->assertSame(2, AuditLog::where('action', 'USER_STATUS_CHANGED')->count());
        // A deleted bearer credential cannot revive when the account is reactivated.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_self_deactivation_and_role_mutation_through_hu21_are_rejected(): void
    {
        $this->patchJson('/api/v1/admin/users/'.$this->administrator->id.'/status', ['status' => 'INACTIVE'])->assertConflict();
        $target = $this->account();
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['status' => 'ACTIVE', 'roles' => ['ADMINISTRADOR']])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unauthorized_and_inactive_admin_assignments_cannot_manage_accounts(): void
    {
        $target = $this->account();
        Sanctum::actingAs($this->account('ESTUDIANTE'));
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->postJson('/api/v1/admin/users/'.$target->id.'/roles', ['role_id' => Role::where('name', 'ADMINISTRADOR')->value('id')])->assertForbidden();
        Sanctum::actingAs($this->administrator);
        DB::table('role_user')->where('user_id', $this->administrator->id)->update(['status' => 'INACTIVE']);
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['status' => 'INACTIVE'])->assertForbidden();
        $this->assertSame('ACTIVE', $target->refresh()->status);
    }

    public function test_role_assignment_requires_profile_avoids_duplicates_and_preserves_data_on_withdrawal(): void
    {
        $target = $this->account();
        $teacherRole = Role::where('name', 'DOCENTE')->sole();
        $path = '/api/v1/admin/users/'.$target->id.'/roles';
        $this->postJson($path, ['role_id' => $teacherRole->id])->assertUnprocessable();
        Teacher::factory()->create(['user_id' => $target->id]);
        $this->postJson($path, ['role_id' => $teacherRole->id])->assertOk();
        $this->postJson($path, ['role_id' => $teacherRole->id])->assertConflict();
        $this->deleteJson($path.'/'.$teacherRole->id)->assertOk();
        $this->assertFalse($target->hasRole('DOCENTE'));
        $this->assertNotNull($target->refresh()->teacher);
        $this->postJson($path, ['role_id' => $teacherRole->id])->assertOk();
        $this->assertSame(1, DB::table('role_user')->where('user_id', $target->id)->count());
        $this->assertSame(3, AuditLog::whereIn('action', ['USER_ROLE_ASSIGNED', 'USER_ROLE_REVOKED'])->count());
        $this->assertNotNull(AuditLog::where('action', 'USER_ROLE_REVOKED')->sole()->old_values['assigned_at']);
        $this->getJson($path)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/admin/roles')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_collaborator_is_not_a_permanent_role_and_own_admin_access_is_preserved(): void
    {
        $target = $this->account();
        $temporary = Role::create(['name' => 'COLABORADOR', 'status' => 'ACTIVE']);
        $this->postJson('/api/v1/admin/users/'.$target->id.'/roles', ['role_id' => $temporary->id])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/users/'.$this->administrator->id.'/roles/'.Role::where('name', 'ADMINISTRADOR')->value('id'))->assertConflict();
        $this->assertTrue($this->administrator->hasRole('ADMINISTRADOR'));
    }

    public function test_guest_cannot_read_accounts_or_mutate_roles(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->postJson('/api/v1/admin/users/'.$this->administrator->id.'/roles', ['role_id' => Role::where('name', 'DOCENTE')->value('id')])->assertUnauthorized();
    }

    public function test_inactive_role_assignment_can_be_seen_and_withdrawn_but_not_granted(): void
    {
        $target = $this->account('DOCENTE');
        $role = Role::where('name', 'DOCENTE')->sole();
        $role->update(['status' => 'INACTIVE']);
        $base = '/api/v1/admin/users/'.$target->id.'/roles';
        $roles = $this->getJson($base)->assertOk()->json('data');
        $this->assertTrue(collect($roles)->firstWhere('id', $role->id)['assigned']);
        $this->deleteJson($base.'/'.$role->id)->assertOk();
        $this->postJson($base, ['role_id' => $role->id])->assertUnprocessable();
    }

    public function test_teacher_responsibilities_block_role_withdrawal(): void
    {
        $fixture = $this->offeringFixture();
        $this->deleteJson('/api/v1/admin/users/'.$fixture['user']->id.'/roles/'.Role::where('name', 'DOCENTE')->value('id'))->assertConflict();
        $this->assertTrue($fixture['user']->hasRole('DOCENTE'));
    }

    public function test_audit_failure_rolls_back_status_tokens_and_role_assignment(): void
    {
        $target = $this->account();
        $target->forceFill(['active_session_id' => 'preserved'])->save();
        $target->createToken('preserved');
        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['status' => 'INACTIVE'])->assertStatus(500);
        $this->assertSame('ACTIVE', $target->refresh()->status);
        $this->assertSame('preserved', $target->active_session_id);
        $this->assertSame(1, $target->tokens()->count());
        $this->postJson('/api/v1/admin/users/'.$target->id.'/roles', ['role_id' => Role::where('name', 'ADMINISTRADOR')->value('id')])->assertStatus(500);
        $this->assertFalse($target->hasRole('ADMINISTRADOR'));
    }

    public function test_history_contains_only_persisted_events_with_real_actor_and_safe_context(): void
    {
        $target = $this->account();
        $this->getJson('/api/v1/admin/users/history')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['status' => 'INACTIVE', 'actor_id' => $target->id])->assertOk();
        $this->getJson('/api/v1/admin/users/history?search='.$target->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor_id', $this->administrator->id)->assertJsonPath('data.0.new_context.status', 'INACTIVE');
        $this->getJson('/api/v1/admin/users/'.$target->id.'/history')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_profile_records_are_scoped_paginated_real_and_never_invent_grades_or_history(): void
    {
        $fixture = $this->offeringFixture();
        DB::table('enrollments')->insert(['student_id' => $fixture['studentId'], 'course_offering_id' => $fixture['offering']->id,
            'registered_by' => $fixture['user']->id, 'status' => 'ACTIVE']);
        $student = Student::findOrFail($fixture['studentId']);
        $base = '/api/v1/admin/profiles/students/'.$student->id.'/records';
        $this->getJson($base.'?section=subjects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_code', 'INF-01')->assertJsonPath('data.0.group_code', null);
        $this->getJson($base.'?section=subject-history')->assertOk()->assertJsonCount(0, 'data');
        $fixture['term']->update(['status' => 'INACTIVE']);
        $this->getJson($base.'?section=subject-history')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.final_result', null);
        $this->getJson($base.'?section=subject-history&search=notfound')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?section=roles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/profiles/students/999999/records?section=subjects')->assertNotFound();
        Sanctum::actingAs($fixture['user']);
        DB::table('role_user')->where('user_id', $fixture['user']->id)->where('role_id', Role::where('name', 'ADMINISTRADOR')->value('id'))->update(['status' => 'INACTIVE']);
        $this->getJson($base.'?section=subjects')->assertForbidden();
    }

    public function test_teacher_exam_records_share_current_time_rules_and_include_cancelled_history(): void
    {
        $fixture = $this->offeringFixture();
        foreach ([['Future', 'SCHEDULED', 2], ['Past', 'SCHEDULED', -2], ['Cancelled', 'CANCELLED', 3]] as [$name, $status, $days]) {
            \App\Models\Exam::create(['course_offering_id' => $fixture['offering']->id, 'room_id' => $fixture['room']->id,
                'created_by' => $fixture['user']->id, 'name' => $name, 'exam_date' => now()->addDays($days)->toDateString(),
                'start_time' => '10:00:00', 'duration_minutes' => 60, 'evaluation_type' => 'partial', 'status' => $status]);
        }
        $base = '/api/v1/admin/profiles/teachers/'.$fixture['teacher']->id.'/records';
        $this->getJson($base.'?section=exams')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.exam_name', 'Future');
        $this->getJson($base.'?section=exam-history&per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson($base.'?section=subjects')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_collaborations_and_authorization_history_use_persisted_sources_without_exposing_access_hashes(): void
    {
        $fixture = $this->offeringFixture();
        $student = Student::findOrFail($fixture['studentId']);
        $exam = \App\Models\Exam::create(['course_offering_id' => $fixture['offering']->id, 'room_id' => $fixture['room']->id,
            'created_by' => $fixture['user']->id, 'name' => 'Real exam', 'exam_date' => now()->addDays(2)->toDateString(),
            'start_time' => '10:00:00', 'duration_minutes' => 60, 'status' => 'SCHEDULED', 'evaluation_type' => 'final']);
        $assignment = \App\Models\ExamCollaborator::create(['exam_id' => $exam->id, 'user_id' => $student->user_id,
            'assigned_by' => $fixture['user']->id, 'assigned_at' => now(), 'access_code_hash' => 'must-not-be-exposed', 'status' => 'ACTIVE']);
        $base = '/api/v1/admin/profiles/students/'.$student->id.'/records';
        $response = $this->getJson($base.'?section=collaborations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.active', 1);
        $this->assertStringNotContainsString('must-not-be-exposed', $response->getContent());
        $this->getJson($base.'?section=roles')->assertOk()->assertJsonCount(0, 'data');
        AuditLog::create(['action' => 'EXAM_COLLABORATOR_ASSIGNED', 'user_id' => $fixture['user']->id,
            'entity_type' => 'ExamCollaborator', 'entity_id' => $assignment->id, 'created_at' => now(),
            'new_values' => ['collaborator_user_id' => $student->user_id, 'exam_id' => $exam->id]]);
        $this->getJson($base.'?section=roles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'EXAM_COLLABORATOR_ASSIGNED');
        $assignment->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->getJson($base.'?section=collaborations&state=COMPLETED')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.completed', 1);
    }

    public function test_role_changes_reject_the_previous_http_session_cookie(): void
    {
        $connection = $this->app['db']->connection();
        $root = realpath(storage_path('framework'));
        $directory = $root.DIRECTORY_SEPARATOR.'testing-accounts-'.\Illuminate\Support\Str::uuid();
        mkdir($directory);
        $cookies = [];
        $password = 'Password123';
        $this->administrator->forceFill(['email' => 'cookie.admin@umss.edu.bo', 'password' => $password])->save();
        $target = $this->account('ESTUDIANTE');
        $target->forceFill(['email' => 'cookie.target@umss.edu.bo', 'password' => $password])->save();
        Teacher::factory()->create(['user_id' => $target->id]);
        $send = function (string $method, string $uri, array $payload = []) use ($connection, $directory, &$cookies) {
            // Fresh guards/session state; retain the SAME verified SQLite memory connection and transaction.
            $this->refreshApplication();
            $this->app['db']->purge();
            $this->app['db']->extend('sqlite', fn () => $connection);
            config(['eida.institutional_email_domains' => ['umss.edu.bo'], 'session.driver' => 'file',
                'session.files' => $directory, 'session.cookie' => 'isolated_account_test_session',
                'sanctum.stateful' => ['127.0.0.1:5173']]);
            $response = $this->call($method, $uri, [], $cookies, [], ['HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://127.0.0.1:5173'], $payload ? json_encode($payload) : null);
            foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();

            return $response;
        };
        try {
            $send('GET', '/sanctum/csrf-cookie')->assertNoContent();
            $send('POST', '/login', ['identifier' => $target->email, 'password' => $password])->assertOk();
            $send('GET', '/api/v1/me')->assertOk();
            $oldCookies = $cookies;
            $cookies = [];
            $send('GET', '/sanctum/csrf-cookie')->assertNoContent();
            $send('POST', '/login', ['identifier' => $this->administrator->email, 'password' => $password])->assertOk();
            $send('POST', '/api/v1/admin/users/'.$target->id.'/roles', ['role_id' => Role::where('name', 'DOCENTE')->value('id')])->assertOk();
            $cookies = $oldCookies;
            $send('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
            $this->assertTrue($target->hasRole('ESTUDIANTE'));
            $this->assertTrue($target->hasRole('DOCENTE'));
        } finally {
            if (dirname($directory) === $root) (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($directory);
        }
    }
}
