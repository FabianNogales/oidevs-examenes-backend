<?php

namespace Tests\Feature\CourseOfferings;

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\CourseOfferingImport;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class CourseOfferingImportConfirmTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/course-offerings/import/';

    private const CSV = "correo_docente,codigo_materia\nana@umss.edu.bo,INF-01\n";

    private User $admin;

    private Teacher $teacher;

    private AcademicTerm $term;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->teacher = Teacher::factory()->create(['user_id' => User::factory()->create(['email' => 'ana@umss.edu.bo', 'status' => 'ACTIVE'])->id]);
        $role = Role::create(['name' => 'DOCENTE', 'status' => 'ACTIVE']);
        $this->teacher->user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'ACTIVE']);
        $this->subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación', 'status' => 'ACTIVE']);
    }

    public function test_partial_report_audit_and_completed_retry_after_expiry_and_period_closure(): void
    {
        $text = self::CSV."ana@umss.edu.bo,INF-01\nmissing@umss.edu.bo,INF-01\n";
        $id = $this->preview($text);
        $response = $this->confirm($id, $text)->assertOk()->assertJsonPath('data.summary', ['total' => 3, 'imported' => 1, 'failed' => 1, 'omitted' => 1])
            ->assertJsonPath('data.rows.0.status', 'IMPORTED')->assertJsonPath('data.rows.0.errors', [])
            ->assertJsonPath('data.rows.1.status', 'OMITTED')->assertJsonPath('data.rows.2.status', 'ERROR');
        $this->assertIsInt($response->json('data.rows.0.course_offering_id'));
        $audit = AuditLog::firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('COURSE_OFFERING_CREATED', $audit->action);
        $this->assertSame($id, $audit->new_values['preview_id']);
        $this->assertSame($response->json('data.rows.0.course_offering_id'), $audit->entity_id);
        $this->term->update(['status' => 'INACTIVE']);
        $this->travel(25)->hours();
        $this->confirm($id, $text)->assertOk()->assertExactJson($response->json());
        $this->assertDatabaseCount('course_offerings', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame('COMPLETED', CourseOfferingImport::firstOrFail()->status);
    }

    public function test_new_duplicate_is_omitted_without_reactivating(): void
    {
        $id = $this->preview();
        $offering = CourseOffering::create(['teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'academic_term_id' => $this->term->id, 'status' => 'INACTIVE']);
        $this->confirm($id)->assertOk()->assertJsonPath('data.summary', ['total' => 1, 'imported' => 0, 'failed' => 0, 'omitted' => 1])->assertJsonPath('data.rows.0.status', 'OMITTED');
        $this->assertSame('INACTIVE', $offering->fresh()->status);
        $this->assertDatabaseCount('course_offerings', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_changed_references_are_errors_while_other_rows_are_imported(): void
    {
        $other = Subject::create(['code' => 'INF-02', 'name' => 'Segunda', 'status' => 'ACTIVE']);
        $text = self::CSV."ana@umss.edu.bo,INF-02\n";
        $id = $this->preview($text);
        $this->subject->update(['status' => 'INACTIVE']);
        $this->confirm($id, $text)->assertOk()->assertJsonPath('data.summary', ['total' => 2, 'imported' => 1, 'failed' => 1, 'omitted' => 0])
            ->assertJsonPath('data.rows.0.status', 'ERROR')->assertJsonPath('data.rows.1.status', 'IMPORTED');
        $this->assertDatabaseHas('course_offerings', ['subject_id' => $other->id]);
    }

    public function test_disabled_teacher_account_role_or_pivot_is_revalidated(): void
    {
        foreach (['teacher', 'account', 'role', 'pivot'] as $case) {
            $role = Role::where('name', 'DOCENTE')->firstOrFail();
            $this->teacher->update(['status' => 'ACTIVE']);
            $this->teacher->user->update(['status' => 'ACTIVE']);
            $role->update(['status' => 'ACTIVE']);
            $this->teacher->user->roles()->updateExistingPivot($role->id, ['status' => 'ACTIVE']);
            $id = $this->preview();
            match ($case) {
                'teacher' => $this->teacher->update(['status' => 'INACTIVE']),
                'account' => $this->teacher->user->update(['status' => 'INACTIVE']),
                'role' => $role->update(['status' => 'INACTIVE']),
                'pivot' => $this->teacher->user->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']),
            };
            $this->confirm($id)->assertOk()->assertJsonPath('data.summary.failed', 1);
        }
        $this->assertDatabaseCount('course_offerings', 0);
    }

    public function test_owner_hash_period_expiration_and_unknown_preview_are_checked_without_writes(): void
    {
        $id = $this->preview();
        $this->confirm($id, self::CSV."\n")->assertStatus(409);
        $other = AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'INACTIVE']);
        $this->confirm($id, self::CSV, ['academic_term_id' => $other->id])->assertStatus(409);
        $this->confirm((string) Str::uuid())->assertNotFound();
        $import = CourseOfferingImport::firstOrFail();
        $import->update(['user_id' => $this->teacher->user_id]);
        $this->confirm($id)->assertNotFound()->assertJsonMissingPath('data');
        $import->update(['user_id' => $this->admin->id, 'expires_at' => now()->subSecond()]);
        $this->confirm($id)->assertStatus(410);
        $this->assertDatabaseCount('course_offerings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('PENDING', $import->fresh()->status);
    }

    public function test_closed_or_inconsistent_period_blocks_all_rows_and_leaves_preview_retryable(): void
    {
        $id = $this->preview();
        $this->term->update(['status' => 'INACTIVE']);
        $this->confirm($id)->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_INACTIVE');
        $this->term->update(['status' => 'ACTIVE']);
        $other = AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'ACTIVE']);
        $this->confirm($id)->assertStatus(409)->assertJsonPath('code', 'ACADEMIC_TERM_STATE_INCONSISTENT');
        $this->assertSame('PENDING', CourseOfferingImport::firstOrFail()->status);
        $this->assertDatabaseCount('course_offerings', 0);
        $other->update(['status' => 'INACTIVE']);
        $this->confirm($id)->assertOk()->assertJsonPath('data.summary.imported', 1);
    }

    public function test_no_ready_rows_invalid_payload_and_revoked_permission_are_rejected(): void
    {
        $text = "correo_docente,codigo_materia\nmissing@umss.edu.bo,INF-01\n";
        $id = $this->preview($text);
        $this->confirm($id, $text)->assertUnprocessable()->assertJsonPath('code', 'IMPORT_NO_VALID_ROWS');
        $this->confirm('invalid')->assertUnprocessable()->assertJsonValidationErrors('preview_id');
        $this->confirm($id, $text, ['status' => 'ACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->admin->roles()->detach();
        $this->confirm($id, $text)->assertForbidden();
        $this->assertDatabaseCount('course_offerings', 0);
    }

    public function test_infrastructure_failure_rolls_back_offerings_audit_and_result_and_can_retry(): void
    {
        Subject::create(['code' => 'INF-02', 'name' => 'Segunda', 'status' => 'ACTIVE']);
        $text = self::CSV."ana@umss.edu.bo,INF-02\n";
        $id = $this->preview($text);
        $calls = 0;
        $this->mock(AuditLogService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('log')->twice()->andReturnUsing(function (...$args) use (&$calls) {
                if (++$calls === 2) {
                    throw new \RuntimeException('internal database detail');
                }

                return (new AuditLogService)->log(...$args);
            });
        });
        $response = $this->confirm($id, $text)->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('internal database detail', $response->getContent());
        $this->assertDatabaseCount('course_offerings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame('PENDING', CourseOfferingImport::firstOrFail()->status);
        $this->assertNull(CourseOfferingImport::firstOrFail()->result_report);
        $this->app->instance(AuditLogService::class, new AuditLogService);
        $this->confirm($id, $text)->assertOk()->assertJsonPath('data.summary.imported', 2);
        $this->assertDatabaseCount('course_offerings', 2);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    private function preview(string $text = self::CSV): string
    {
        return $this->postJson(self::BASE.'preview', ['academic_term_id' => $this->term->id, 'file' => UploadedFile::fake()->createWithContent('offerings.csv', $text)])->assertOk()->json('data.preview_id');
    }

    private function confirm(string $id, string $text = self::CSV, array $extra = [])
    {
        return $this->postJson(self::BASE.'confirm', array_replace(['academic_term_id' => (string) $this->term->id, 'preview_id' => $id, 'file' => UploadedFile::fake()->createWithContent('offerings.csv', $text)], $extra));
    }
}
