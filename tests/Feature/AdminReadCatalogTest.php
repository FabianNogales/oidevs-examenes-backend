<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminReadCatalogTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedEndpoints(): array
    {
        return [['/api/v1/admin/audit-logs'], ['/api/v1/admin/audit-logs/1'],
            ['/api/v1/admin/careers'], ['/api/v1/admin/careers/1']];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guests_cannot_read_administrative_data(string $endpoint): void
    {
        $this->getJson($endpoint)->assertUnauthorized();
    }

    #[DataProvider('protectedEndpoints')]
    public function test_other_roles_do_not_gain_administrative_read_access(string $endpoint): void
    {
        $user = $this->account(RoleName::DOCENTE);
        $student = Role::create(['name' => 'ESTUDIANTE', 'status' => 'ACTIVE']);
        $user->roles()->attach($student->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->getJson($endpoint)->assertForbidden();
    }

    public function test_inactive_role_assignment_password_and_replaced_session_are_rejected(): void
    {
        $user = $this->account();
        DB::table('role_user')->where('user_id', $user->id)->update(['status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        DB::table('role_user')->where('user_id', $user->id)->update(['status' => 'ACTIVE']);
        Role::where('name', 'ADMINISTRADOR')->update(['status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/careers')->assertForbidden();
        Role::where('name', 'ADMINISTRADOR')->update(['status' => 'ACTIVE']);
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->getJson('/api/v1/admin/careers')->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_replaced_http_cookie_is_rejected_by_both_catalogs(): void
    {
        $user = $this->account();
        $user->forceFill(['email' => 'read.admin@umss.edu.bo', 'password' => 'Password1'])->save();
        config(['session.driver' => 'file', 'sanctum.stateful' => ['127.0.0.1:5173'], 'eida.institutional_email_domains' => ['umss.edu.bo']]);
        Session::setDefaultDriver('file');
        foreach (['audit-logs', 'careers'] as $catalog) {
            $this->cookieSession(Str::random(40));
            $this->postJson('/login', ['identifier' => $user->email, 'password' => 'Password1'])->assertOk();
            $session = $user->refresh()->active_session_id;
            $this->cookieSession($session)->getJson('/api/v1/admin/'.$catalog)->assertOk();
            $user->forceFill(['active_session_id' => Str::random(40)])->save();
            $this->cookieSession($session)->getJson('/api/v1/admin/'.$catalog)->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
        }
    }

    private function cookieSession(string $id): self
    {
        if ($this->app['session']->isStarted()) $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->app['session']->setId($id);
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        return $this->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')->withCookie(config('session.cookie'), $id);
    }

    public function test_audit_pagination_is_stable_read_only_and_does_not_create_access_events(): void
    {
        $this->account();
        for ($i = 0; $i < 12; $i++) { $this->event(['entity_id' => $i + 1]); }
        $before = AuditLog::count();
        $response = $this->getJson('/api/v1/admin/audit-logs?per_page=10&page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.current_page', 2);
        $id = $response->json('data.0.id');
        $this->getJson('/api/v1/admin/audit-logs/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame($before, AuditLog::count());
        foreach (['postJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}('/api/v1/admin/audit-logs/'.$id)->assertStatus(405);
        }
        $this->assertSame($before, AuditLog::count());
    }

    public function test_audit_context_preserves_known_events_without_exposing_free_form_data(): void
    {
        $user = $this->account();
        $log = $this->event(['user_id' => $user->id, 'new_values' => [
            'source' => 'APPLICATION', 'operation' => 'ENROLLMENTS_IMPORTED', 'outcome' => 'CONFIRMED',
            'mode' => 'CSV', 'course_offering_id' => 42, 'student_ids' => [15, 16],
            'summary' => ['totalProcessed' => 4, 'successfulRecords' => 2, 'duplicateRecords' => 1, 'failedCount' => 1,
                'password' => 'private-marker'], 'password' => 'private-marker', 'token' => 'private-marker',
            'email' => 'private-marker', 'identity_number' => 'private-marker', 'rules' => 'private-marker',
            'unknown' => ['token' => 'private-marker'],
        ], 'old_values' => ['password' => 'private-marker'], 'ip_address' => '192.0.2.1', 'user_agent' => 'private-marker']);
        $before = DB::table('audit_logs')->where('id', $log->id)->value('new_values');
        foreach (['/api/v1/admin/audit-logs', '/api/v1/admin/audit-logs/'.$log->id] as $endpoint) {
            $response = $this->getJson($endpoint)->assertOk()->assertDontSee('private-marker')->assertDontSee('192.0.2.1');
            $data = str_ends_with($endpoint, (string) $log->id) ? $response->json('data') : $response->json('data.0');
            $this->assertSame(42, $data['new_context']['course_offering_id']);
            $this->assertSame(2, $data['new_context']['summary']['successfulRecords']);
            $this->assertSame([], $data['old_context']);
        }
        $this->assertSame($before, DB::table('audit_logs')->where('id', $log->id)->value('new_values'));
    }

    public function test_wrong_exam_collaboration_and_exam_dual_events_keep_distinct_records(): void
    {
        $this->account();
        $this->event(['action' => 'ENTRY_QR_WRONG_EXAM', 'new_values' => ['qr_exam_id' => 9, 'student_id' => 4, 'reason' => 'WRONG_EXAM', 'method' => 'QR']]);
        $this->event(['action' => 'EXAM_COLLABORATOR_REVOKED', 'entity_type' => 'ExamCollaborator',
            'new_values' => ['exam_id' => 8, 'collaborator_user_id' => 7, 'opening_event_id' => 3, 'history_complete' => true]]);
        $this->event(['action' => 'WRITE', 'entity_type' => 'Exam', 'entity_id' => 8]);
        $this->event(['action' => 'CREATE', 'entity_type' => 'Exam', 'entity_id' => 8, 'user_id' => null]);
        $response = $this->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame(2, collect($response->json('data'))->where('entity_type', 'Exam')->where('entity_id', 8)->count());
        $this->assertSame(3, collect($response->json('data'))->firstWhere('entity_type', 'ExamCollaborator')['new_context']['opening_event_id']);
    }

    public function test_careers_include_inactive_search_paginate_and_offer_read_only_detail(): void
    {
        $this->account();
        $active = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $inactive = Career::create(['code' => 'IND', 'name' => 'Industrial', 'status' => 'INACTIVE']);
        $this->getJson('/api/v1/admin/careers?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/careers?search=ind')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson('/api/v1/admin/careers/'.$active->id)->assertOk()->assertJsonPath('data.code', 'SIS');
        $this->getJson('/api/v1/admin/careers/'.$inactive->id)->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson('/api/v1/admin/careers?search=missing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/careers/99999')->assertNotFound();
        $this->postJson('/api/v1/admin/careers', [])->assertStatus(405);
    }

    public function test_invalid_pagination_and_missing_audit_are_explicit(): void
    {
        $this->account();
        $this->getJson('/api/v1/admin/audit-logs?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/admin/careers?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/admin/audit-logs/999999')->assertNotFound();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manual_student_api_preserves_hu20_and_inactive_career_policy(): void
    {
        $actor = $this->account();
        $payload = $this->studentPayload();
        $this->getJson('/api/v1/admin/subjects/careers')->assertOk()->assertJsonPath('data.0.status', 'INACTIVE');
        $this->postJson('/api/v1/admin/students', $payload)->assertCreated()
            ->assertJsonPath('data.sis_code', '000123')->assertJsonMissingPath('data.password');
        $student = Student::where('sis_code', '000123')->firstOrFail();
        $this->assertSame('00123456', $student->identity_number);
        $this->assertTrue($student->user->must_change_password);
        $this->assertTrue(Hash::check('00123456', $student->user->password));
        $this->assertNull($student->user->profile_photo);
        $this->assertSame('INACTIVE', $student->career->status);
        $this->assertSame('MANUAL', AuditLog::where('action', 'STUDENT_CREATED')->firstOrFail()->new_values['context']);
        $this->assertSame($actor->id, AuditLog::where('action', 'STUDENT_CREATED')->firstOrFail()->user_id);
    }

    public function test_manual_student_duplicates_and_invalid_ci_are_field_errors_without_extra_accounts(): void
    {
        $this->account();
        $payload = $this->studentPayload();
        $this->postJson('/api/v1/admin/students', $payload)->assertCreated();
        $before = User::count();
        $this->postJson('/api/v1/admin/students', $payload)->assertUnprocessable()->assertJsonValidationErrors(['sis_code', 'identity_number', 'email']);
        $this->postJson('/api/v1/admin/students', array_replace($payload, ['sis_code' => '000124', 'identity_number' => 'ABC', 'email' => 'other@umss.edu.bo']))
            ->assertUnprocessable()->assertJsonValidationErrors(['identity_number']);
        $this->assertSame(1, Student::count());
        $this->assertSame($before, User::count());
    }

    public function test_manual_registration_rolls_back_if_its_existing_audit_fails(): void
    {
        $this->account();
        $payload = $this->studentPayload();
        $before = User::count();
        $fail = true;
        AuditLog::creating(function (AuditLog $log) use (&$fail) {
            if ($fail && $log->action === 'STUDENT_CREATED') throw new \RuntimeException('Simulated audit failure');
        });
        try {
            $this->postJson('/api/v1/admin/students', $payload)->assertStatus(500);
        } finally {
            $fail = false;
        }
        $this->assertSame(0, Student::count());
        $this->assertSame($before, User::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'STUDENT_CREATED']);
    }

    private function studentPayload(): array
    {
        config(['eida.institutional_email_domains' => ['umss.edu.bo']]);
        Role::firstOrCreate(['name' => 'ESTUDIANTE'], ['status' => 'ACTIVE']);
        $career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'INACTIVE']);
        return ['sis_code' => '000123', 'identity_number' => '00123456', 'first_names' => 'Ana',
            'last_names' => 'Ejemplo', 'email' => 'manual.test@umss.edu.bo', 'career_id' => $career->id];
    }

    private function account(RoleName $name = RoleName::ADMINISTRADOR): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => $name->value], ['status' => 'ACTIVE']);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $user->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($user);
        return $user;
    }

    private function event(array $overrides = []): AuditLog
    {
        return AuditLog::create($overrides + ['action' => 'WRITE', 'entity_type' => 'Enrollment', 'entity_id' => 1, 'created_at' => '2026-10-20 12:00:00']);
    }
}
