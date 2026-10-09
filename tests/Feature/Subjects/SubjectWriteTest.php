<?php

namespace Tests\Feature\Subjects;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Subjects\SubjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubjectWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
    }

    public function test_create_normalizes_and_returns_complete_representation_with_audit(): void
    {
        $response = $this->postJson('/api/v1/admin/subjects', $this->payload(['code' => ' inf-01 ', 'name' => ' Programación I ']))
            ->assertCreated()->assertJsonPath('data.code', 'INF-01')->assertJsonPath('data.name', 'Programación I')
            ->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.careers.0.id', $this->career->id);
        $audit = AuditLog::where('action', 'SUBJECT_CREATED')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($response->json('data.id'), $audit->entity_id);
        $this->assertNull($audit->old_values);
        $this->assertSame([$this->career->id], $audit->new_values['career_ids']);
    }

    public function test_edit_preserves_state_and_inactive_historical_career(): void
    {
        $subject = Subject::create(['code' => 'OLD', 'name' => 'Anterior', 'status' => 'INACTIVE']);
        $this->career->update(['status' => 'INACTIVE']);
        $subject->careers()->attach($this->career->id);
        $this->putJson('/api/v1/admin/subjects/'.$subject->id, $this->payload())
            ->assertOk()->assertJsonPath('data.id', $subject->id)->assertJsonPath('data.status', 'INACTIVE')
            ->assertJsonPath('data.careers.0.status', 'INACTIVE');
        $audit = AuditLog::where('action', 'SUBJECT_UPDATED')->firstOrFail();
        $this->assertSame('OLD', $audit->old_values['code']);
        $this->assertSame('INF-01', $audit->new_values['code']);
    }

    public function test_edit_replaces_associations_without_deleting_careers(): void
    {
        $id = $this->postJson('/api/v1/admin/subjects', $this->payload())->json('data.id');
        $other = Career::create(['code' => 'INF', 'name' => 'Informática', 'status' => 'ACTIVE']);
        $this->putJson('/api/v1/admin/subjects/'.$id, $this->payload(['career_ids' => [$other->id]]))
            ->assertOk()->assertJsonCount(1, 'data.careers')->assertJsonPath('data.careers.0.id', $other->id);
        $this->assertDatabaseCount('careers', 2);
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseMissing('career_subject', ['subject_id' => $id, 'career_id' => $this->career->id]);
    }

    public function test_duplicate_codes_include_inactive_and_legacy_lowercase_but_names_can_repeat(): void
    {
        $subject = Subject::create(['code' => 'inf-01', 'name' => 'Programación I', 'status' => 'INACTIVE']);
        $this->postJson('/api/v1/admin/subjects', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson('/api/v1/admin/subjects/'.$subject->id, $this->payload())->assertOk();
        $other = $this->postJson('/api/v1/admin/subjects', $this->payload(['code' => 'INF-02']))->assertCreated()->json('data.id');
        $this->putJson('/api/v1/admin/subjects/'.$other, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_invalid_payloads_and_protected_fields_do_not_write(): void
    {
        $this->postJson('/api/v1/admin/subjects', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name', 'career_ids']);
        foreach (['ÁBC', '-INF', 'A B', str_repeat('A', 51)] as $code) {
            $this->postJson('/api/v1/admin/subjects', $this->payload(['code' => $code]))->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        foreach (['1', '12345', "Materia\nDos", str_repeat('a', 256)] as $name) {
            $this->postJson('/api/v1/admin/subjects', $this->payload(['name' => $name]))->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        foreach ([[], [(string) $this->career->id], [$this->career->id, $this->career->id], [99999], [0]] as $ids) {
            $this->postJson('/api/v1/admin/subjects', $this->payload(['career_ids' => $ids]))->assertUnprocessable();
        }
        $this->postJson('/api/v1/admin/subjects', $this->payload() + ['id' => null, 'status' => 'INACTIVE', 'unexpected' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['id', 'status', 'unexpected']);
        $this->assertDatabaseCount('subjects', 0);
        $this->assertDatabaseCount('career_subject', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_inactive_new_career_is_rejected_and_missing_subject_returns_404(): void
    {
        $this->career->update(['status' => 'INACTIVE']);
        $this->postJson('/api/v1/admin/subjects', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('career_ids');
        $subject = Subject::create(['code' => 'OLD', 'name' => 'Anterior', 'status' => 'ACTIVE']);
        $this->putJson('/api/v1/admin/subjects/'.$subject->id, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('career_ids');
        $this->putJson('/api/v1/admin/subjects/99999', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('career_subject', 0);
    }

    public function test_write_permissions_are_enforced(): void
    {
        $subject = Subject::create(['code' => 'OLD', 'name' => 'Anterior', 'status' => 'ACTIVE']);
        $this->admin->roles()->detach();
        $this->postJson('/api/v1/admin/subjects', $this->payload())->assertForbidden();
        $this->putJson('/api/v1/admin/subjects/'.$subject->id, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_career_changes_after_request_validation_are_rechecked_by_service(): void
    {
        $data = $this->payload();
        $this->career->update(['status' => 'INACTIVE']);
        try {
            app(SubjectService::class)->save($data, $this->request());
            $this->fail('Expected inactive career rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('career_ids', $exception->errors());
        }
        $this->career->delete();
        try {
            app(SubjectService::class)->save($data, $this->request());
            $this->fail('Expected deleted career rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('career_ids', $exception->errors());
        }
        $this->assertDatabaseCount('subjects', 0);
    }

    public function test_audit_failure_rolls_back_subject_and_association_changes(): void
    {
        $subject = Subject::create(['code' => 'OLD', 'name' => 'Anterior', 'status' => 'ACTIVE']);
        $subject->careers()->attach($this->career->id);
        $other = Career::create(['code' => 'INF', 'name' => 'Informática', 'status' => 'ACTIVE']);
        $this->mock(AuditLogService::class)->shouldReceive('log')->twice()->andThrow(new \RuntimeException('Audit unavailable'));
        foreach ([null, $subject] as $target) {
            try {
                app(SubjectService::class)->save($this->payload(['career_ids' => [$other->id]]), $this->request(), $target);
                $this->fail('Expected audit failure.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Audit unavailable', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('subjects', 1);
        $this->assertSame('OLD', $subject->refresh()->code);
        $this->assertDatabaseCount('career_subject', 1);
        $this->assertDatabaseHas('career_subject', ['subject_id' => $subject->id, 'career_id' => $this->career->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['code' => 'INF-01', 'name' => 'Programación I', 'career_ids' => [$this->career->id]], $overrides);
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/admin/subjects', 'POST');
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }
}
