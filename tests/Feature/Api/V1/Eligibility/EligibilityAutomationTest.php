<?php

namespace Tests\Feature\Api\V1\Eligibility;

use App\Models\AcademicImport;
use App\Models\AcademicRecord;
use App\Models\EligibilityEvaluation;
use App\Models\EligibilityPolicy;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Eligibility\Automation\CriteriaCatalog;
use App\Services\Eligibility\Automation\CriteriaEvaluator;
use App\Services\Eligibility\ExamEligibilityInitializer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class EligibilityAutomationTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    private Exam $exam;

    private string $base;

    private string $academic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create($this->examPayload($this->fixture['room']->id) + [
            'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED']);
        DB::table('enrollments')->insert(['course_offering_id' => $this->fixture['offering']->id, 'student_id' => $this->fixture['studentId'],
            'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id]);
        $this->base = '/api/v1/exams/'.$this->exam->id.'/eligibility-automation';
        $this->academic = '/api/v1/academic-offerings/'.$this->fixture['offering']->id.'/academic-records';
        Sanctum::actingAs($this->fixture['user']);
    }

    private function configure(array $criteria = [], int $revision = 0)
    {
        return $this->putJson($this->base.'/configuration', ['revision' => $revision, 'criteria' => $criteria]);
    }

    private function criterion(string $code = 'MIN_ATTENDANCE_PERCENTAGE', array $parameters = ['minimum_percentage' => 90]): array
    {
        return ['code' => $code, 'parameters' => $parameters];
    }

    private function csv(string $rows)
    {
        return $this->postJson($this->academic.'/imports/preview', ['file' => UploadedFile::fake()->createWithContent('notas.csv',
            "sis,assessment_key,was_taken,score,attended_classes,total_classes\n".$rows)]);
    }

    private function import(): int
    {
        $id = $this->csv("202600001,FIRST_PARTIAL,true,25,18,20\n202600001,SECOND_PARTIAL,true,25,,\n")
            ->assertOk()->assertJsonPath('data.can_confirm', true)->json('data.preview_id');

        return $this->postJson($this->academic.'/imports/'.$id.'/confirm')->assertOk()->json('data.record_ids.0');
    }

    public function test_empty_search_is_an_unfiltered_paginated_list_and_invalid_types_remain_rejected(): void
    {
        foreach (['', '?search=', '?search=%20%20'] as $suffix) {
            $this->getJson($this->academic.$suffix)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        }
        $this->import();
        foreach (['', '?search=', '?search=%20%20'] as $suffix) {
            $this->getJson($this->academic.$suffix)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
        }
        $this->getJson($this->academic.'?search=does-not-exist')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->academic.'?search[]=bad')->assertUnprocessable();
        $this->getJson($this->academic.'/history?search=')->assertOk();
        $this->getJson('/api/v1/admin/eligibility-policies?search=')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/eligibility-policies?search[]=bad')->assertUnprocessable();
    }

    public function test_team_proposal_operational_without_external_approval_and_second_instance_locked(): void
    {
        $this->getJson($this->base.'/options')->assertOk()->assertJsonCount(5, 'data.criteria')->assertJsonPath('data.revision', 0);
        $this->exam->update(['evaluation_type' => 'makeup']);
        $this->getJson($this->base.'/options')->assertOk()->assertJsonCount(6, 'data.criteria')
            ->assertJsonPath('data.mandatory_criteria.0.code', CriteriaCatalog::SECOND)->assertJsonPath('data.mandatory_criteria.0.locked', true);
        $response = $this->getJson($this->base.'/configuration')->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->data->mandatory_criteria[0]->parameters);
        $this->configure()->assertOk()->assertJsonPath('data.revision', 1)->assertJsonCount(1, 'data.criteria');
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->configure([], 0)->assertConflict();
        $this->configure([$this->criterion(CriteriaCatalog::SECOND, [])], 1)->assertUnprocessable();
        $this->configure([$this->criterion('MIN_ASSESSMENT_AVERAGE', ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'minimum_average' => 50])], 1)->assertUnprocessable();
        $this->configure([$this->criterion()], 1)->assertOk()->assertJsonCount(2, 'data.criteria');
    }

    public function test_policy_admin_only_versions_immutable_activation_without_approval_and_pinned_configuration(): void
    {
        $initial = EligibilityPolicy::first();
        $this->configure()->assertOk();
        $created = $this->postJson('/api/v1/admin/eligibility-policies/'.$initial->id.'/versions', ['name' => 'Versión revisada'])
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.revision', 1)->assertJsonPath('data.activated_at', null)->json('data.id');
        $definition = app(CriteriaCatalog::class)->definition();
        $definition['second_instance']['threshold'] = 30;
        $this->patchJson('/api/v1/admin/eligibility-policies/'.$created, ['revision' => 1, 'definition' => $definition])->assertOk();
        $this->postJson('/api/v1/admin/eligibility-policies/'.$created.'/activate', ['revision' => 1])->assertConflict();
        $this->postJson('/api/v1/admin/eligibility-policies/'.$created.'/activate', ['revision' => 2])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->patchJson('/api/v1/admin/eligibility-policies/'.$created, ['revision' => 3, 'name' => 'No permitido'])->assertConflict();
        $this->getJson($this->base.'/configuration')->assertJsonPath('data.policy.id', $initial->id);
        $this->fixture['user']->roles()->wherePivot('status', 'ACTIVE')->where('name', 'ADMINISTRADOR')->detach();
        $this->postJson('/api/v1/admin/eligibility-policies', ['name' => 'Sin permiso'])->assertForbidden();
    }

    public function test_csv_preview_confirmation_idempotency_correction_history_and_no_silent_replacement(): void
    {
        $preview = $this->csv("202600001,FIRST_PARTIAL,true,30,18,20\n202600001,SECOND_PARTIAL,true,40,,\n")
            ->assertOk()->assertJsonPath('data.can_confirm', true)->json('data.preview_id');
        $this->assertDatabaseCount('academic_records', 0);
        $result = $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.created_records', 1)->json('data');
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->assertExactJson(['data' => $result]);
        $this->assertDatabaseCount('academic_records', 1);
        $this->csv("202600001,FIRST_PARTIAL,true,60,,\n")->assertOk()->assertJsonPath('data.can_confirm', false);
        $row = AcademicRecord::first();
        $this->getJson($this->academic.'?search=202600001&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $payload = ['revision' => 1, 'reason' => 'Corrección de acta', 'assessments' => ['FIRST_PARTIAL' => ['was_taken' => true, 'score' => 50]], 'attended_classes' => 19, 'total_classes' => 20];
        $this->patchJson($this->academic.'/'.$row->id, $payload)->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson($this->academic.'/'.$row->id, $payload)->assertConflict();
        $this->getJson($this->academic.'/history')->assertOk()->assertJsonPath('data.0.action', 'ACADEMIC_RECORD_CORRECTED');
    }

    public function test_invalid_csv_rows_unknown_students_duplicate_assessments_and_conflicting_attendance_never_confirm(): void
    {
        foreach ([
            "999999,FIRST_PARTIAL,true,20,,\n",
            "202600001,FIRST_PARTIAL,false,20,,\n",
            "202600001,FIRST_PARTIAL,true,101,,\n",
            "202600001,FIRST_PARTIAL,true,20,21,20\n",
            "202600001,FIRST_PARTIAL,true,20,,\n202600001,FIRST_PARTIAL,true,20,,\n",
            "202600001,FIRST_PARTIAL,true,20,18,20\n202600001,SECOND_PARTIAL,true,30,19,20\n",
        ] as $rows) {
            $id = $this->csv($rows)->assertOk()->assertJsonPath('data.can_confirm', false)->json('data.preview_id');
            $this->postJson($this->academic.'/imports/'.$id.'/confirm')->assertUnprocessable();
        }
        $this->assertDatabaseCount('academic_records', 0);
        $this->postJson($this->academic.'/imports/preview', ['file' => UploadedFile::fake()->createWithContent('x.csv', "bad,header\n")])->assertUnprocessable();
    }

    public function test_second_instance_strict_boundary_automatic_persistence_and_manual_priority(): void
    {
        $this->exam->update(['evaluation_type' => 'makeup']);
        $this->configure()->assertOk();
        $record = $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.0.computed_status', 'INELIGIBLE')->assertJsonPath('data.results.0.will_update', true)->json('data.preview_id');
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $confirmation = $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 1)->json('data');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertExactJson(['data' => $confirmation]);
        $eligibility = ExamEligibility::first();
        $this->assertSame('INELIGIBLE', $eligibility->status);
        $this->assertSame('AUTOMATIC', $eligibility->decision_source);
        $this->assertDatabaseCount('student_criterion_results', 1);
        $this->patchJson($this->academic.'/'.$record, ['revision' => 1, 'reason' => 'Corrección', 'assessments' => [
            'FIRST_PARTIAL' => ['was_taken' => true, 'score' => 25], 'SECOND_PARTIAL' => ['was_taken' => true, 'score' => 25.01]],
            'attended_classes' => 18, 'total_classes' => 20])->assertOk();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.0.computed_status', 'ELIGIBLE')->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
        $eligibility->refresh();
        $this->patchJson('/api/v1/exams/'.$this->exam->id.'/eligibilities/'.$this->fixture['studentId'], [
            'status' => 'INELIGIBLE', 'reason' => 'Decisión manual justificada', 'revision' => $eligibility->revision])->assertOk();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.0.computed_status', 'ELIGIBLE')->assertJsonPath('data.results.0.effective_status', 'INELIGIBLE')
            ->assertJsonPath('data.results.0.manual_override_preserved', true)->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.manual_preserved', 1)->assertJsonPath('data.updated', 0);
        $this->getJson($this->base.'/evaluations')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson($this->base.'/evaluations/'.$confirmation['evaluation_id'])->assertOk()->assertJsonPath('data.results.0.computed_status', 'INELIGIBLE');
    }

    public function test_pending_and_no_optional_criteria_preserve_existing_states_and_legacy_decisions(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $legacy = ExamEligibility::create(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.0.computed_status', 'PENDING')->assertJsonPath('data.results.0.effective_status', 'ELIGIBLE')
            ->assertJsonPath('data.results.0.manual_override_preserved', true)->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertSame('LEGACY', $legacy->fresh()->decision_source);
        $this->configure([], 1)->assertOk();
        $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 2])->assertOk()->assertJsonPath('data.results.0.will_update', false);
    }

    public function test_stale_preview_after_manual_change_data_change_or_expiry_returns_409(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $record = $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()->json('data.preview_id');
        AcademicRecord::find($record)->update(['revision' => 2]);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()->json('data.preview_id');
        ExamEligibility::create(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()->json('data.preview_id');
        $this->travel(31)->minutes();
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
    }

    public function test_foreign_teacher_and_student_cannot_access_or_modify_hu34(): void
    {
        $other = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = DB::table('roles')->where('name', 'DOCENTE')->value('id');
        $other->roles()->attach($teacher, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        Sanctum::actingAs($other);
        $this->getJson($this->base.'/options')->assertForbidden();
        $this->getJson($this->academic)->assertForbidden();
        $this->configure()->assertForbidden();
        $this->postJson($this->academic.'/imports/preview', ['file' => UploadedFile::fake()->createWithContent('x.csv', 'data')])->assertForbidden();
        $other->roles()->detach();
        $this->getJson($this->base.'/options')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_entire_confirmation_and_import(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()->json('data.preview_id');
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('Audit failed')));
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertStatus(500);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->assertNull(EligibilityEvaluation::where('preview_id', $preview)->first()->confirmed_at);
    }

    public function test_pending_without_current_eligibility_never_persists_and_initialized_row_can_be_automated(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.0.effective_status', null)->assertJsonPath('data.results.0.will_update', false)->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.pending', 1);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        app(ExamEligibilityInitializer::class)->forExam($this->exam, $this->fixture['user']->id);
        $this->assertSame('INITIALIZED', ExamEligibility::first()->decision_source);
        $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.0.manual_override_preserved', false)->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame('AUTOMATIC', ExamEligibility::first()->decision_source);
    }

    public function test_import_revalidates_enrollment_and_expiry_and_preview_only_is_cancel_safe(): void
    {
        $preview = $this->csv("202600001,FIRST_PARTIAL,true,50,,\n")->assertOk()->json('data.preview_id');
        DB::table('enrollments')->where('student_id', $this->fixture['studentId'])->update(['status' => 'INACTIVE']);
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertConflict();
        $this->assertDatabaseCount('academic_records', 0);
        DB::table('enrollments')->where('student_id', $this->fixture['studentId'])->update(['status' => 'ACTIVE']);
        $this->travel(31)->minutes();
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertConflict();
        $this->assertDatabaseCount('academic_records', 0);
    }

    public function test_import_audit_failure_rolls_back_records_and_can_be_retried(): void
    {
        $preview = $this->csv("202600001,FIRST_PARTIAL,true,50,,\n")->assertOk()->json('data.preview_id');
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('Audit failed')));
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertStatus(500);
        $this->assertDatabaseCount('academic_records', 0);
        $this->assertNull(AcademicImport::find($preview)->confirmed_at);
        $this->app->forgetInstance(AuditLogService::class);
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.created_records', 1);
    }

    public function test_exam_type_change_can_be_read_and_fixed_but_incompatible_preview_is_rejected(): void
    {
        $this->configure([$this->criterion('MIN_ASSESSMENT_AVERAGE', ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'minimum_average' => 50])])->assertOk();
        $this->exam->update(['evaluation_type' => 'makeup']);
        $this->getJson($this->base.'/options')->assertOk()->assertJsonCount(1, 'data.mandatory_criteria');
        $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertUnprocessable()->assertJsonPath('code', 'CRITERIA_CONFLICT');
        $this->configure([], 1)->assertOk();
        $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 2])->assertOk();
    }

    public function test_configuration_enrollment_and_exam_changes_invalidate_preview_and_history_keeps_original(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()->json('data');
        $this->configure([$this->criterion('MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 95])], 1)->assertOk();
        $this->postJson($this->base.'/evaluations/'.$preview['preview_id'].'/confirm')->assertConflict();
        $this->getJson($this->base.'/evaluations/'.$preview['id'])->assertOk()->assertJsonPath('data.results.0.computed_status', 'ELIGIBLE');
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 2])->assertOk()->json('data.preview_id');
        $this->exam->update(['start_time' => '11:00:00']);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 2])->assertOk()->json('data.preview_id');
        DB::table('enrollments')->where('student_id', $this->fixture['studentId'])->update(['status' => 'INACTIVE']);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
    }

    public function test_validation_rejects_unknown_parameters_duplicates_out_of_range_and_unsafe_policy(): void
    {
        foreach ([[$this->criterion('UNKNOWN', [])], [$this->criterion(), $this->criterion()],
            [$this->criterion('MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 0])],
            [$this->criterion('MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 90, 'formula' => 'anything'])],
            [$this->criterion('MIN_ACCUMULATED_SCORE', ['assessment_keys' => ['FIRST_PARTIAL'], 'minimum_total' => 101])],
        ] as $criteria) {
            $this->configure($criteria)->assertUnprocessable();
        }
        $definition = app(CriteriaCatalog::class)->definition();
        $definition['allowed_criteria'] = ['MIN_ATTENDANCE_PERCENTAGE'];
        $this->postJson('/api/v1/admin/eligibility-policies', ['name' => 'Inválida', 'definition' => $definition])->assertUnprocessable();
        $this->assertDatabaseCount('exam_eligibility_configurations', 0);
        $this->getJson($this->academic.'?search[]=bad')->assertUnprocessable();
    }

    public function test_preview_expiry_is_an_instant_independent_of_application_timezone(): void
    {
        $previous = config('app.timezone');
        config(['app.timezone' => 'America/La_Paz']);
        date_default_timezone_set('America/La_Paz');
        try {
            $preview = $this->csv("202600001,FIRST_PARTIAL,true,50,,\n")->assertOk()->json('data.preview_id');
            $stored = AcademicImport::find($preview);
            $this->assertEqualsWithDelta(1800, now()->diffInSeconds($stored->expires_at, false), 3);
            $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk();
        } finally {
            config(['app.timezone' => $previous]);
            date_default_timezone_set($previous);
        }
    }

    public function test_creation_timestamps_preserve_the_instant_for_policy_import_records_and_evaluation(): void
    {
        $previous = config('app.timezone');
        config(['app.timezone' => 'America/La_Paz']);
        date_default_timezone_set('America/La_Paz');
        $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00-04:00'));
        try {
            $policy = $this->postJson('/api/v1/admin/eligibility-policies', ['name' => 'Zona horaria de prueba'])->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');
            $this->assertTrue(EligibilityPolicy::find($policy)->created_at->equalTo(now()));
            $preview = $this->csv("202600001,FIRST_PARTIAL,true,50,18,20\n")->assertOk()->json('data.preview_id');
            $this->assertTrue(AcademicImport::find($preview)->created_at->equalTo(now()));
            $record = $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->json('data.record_ids.0');
            $this->assertTrue(AcademicRecord::find($record)->created_at->equalTo(now()));
            $this->configure([$this->criterion()])->assertOk();
            $evaluation = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1])->assertOk()->json('data.id');
            $row = EligibilityEvaluation::find($evaluation);
            $this->assertTrue($row->created_at->equalTo(now()));
            $this->assertSame(1800, (int) now()->diffInSeconds($row->expires_at, false));
        } finally {
            $this->travelBack();
            config(['app.timezone' => $previous]);
            date_default_timezone_set($previous);
        }
    }

    public function test_multi_student_confirmation_is_atomic_and_preview_pagination_keeps_global_summary(): void
    {
        $student = (array) DB::table('students')->where('id', $this->fixture['studentId'])->first();
        unset($student['id']);
        $student['user_id'] = User::factory()->create()->id;
        $student['sis_code'] = '202600099';
        $student['identity_number'] = '79999999';
        $second = DB::table('students')->insertGetId($student);
        DB::table('enrollments')->insert(['course_offering_id' => $this->fixture['offering']->id, 'student_id' => $second, 'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id]);
        $this->configure([$this->criterion()])->assertOk();
        $import = $this->csv("202600001,FIRST_PARTIAL,true,60,18,20\n202600099,FIRST_PARTIAL,true,50,18,20\n")->assertOk()->json('data.preview_id');
        $this->postJson($this->academic.'/imports/'.$import.'/confirm')->assertOk()->assertJsonPath('data.created_records', 2);
        $preview = $this->postJson($this->base.'/evaluations/preview?per_page=1', ['configuration_revision' => 1])->assertOk()
            ->assertJsonCount(1, 'data.results')->assertJsonPath('data.meta.total', 2)->assertJsonPath('data.summary.eligible', 2)->json('data');
        $this->getJson($this->base.'/evaluations/'.$preview['id'].'?page=2&per_page=1')->assertOk()->assertJsonPath('data.results.0.student.id', $second);
        $real = new AuditLogService;
        $calls = 0;
        $this->mock(AuditLogService::class, function ($mock) use ($real, &$calls) {
            $mock->shouldReceive('log')->andReturnUsing(function (...$args) use ($real, &$calls) {
                if (++$calls === 2) {
                    throw new \RuntimeException('Failure on second student');
                }

                return $real->log(...$args);
            });
        });
        $this->postJson($this->base.'/evaluations/'.$preview['preview_id'].'/confirm')->assertStatus(500);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->assertDatabaseCount('student_criterion_results', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'EXAM_ELIGIBILITY_AUTOMATED']);
        $this->assertNull(EligibilityEvaluation::find($preview['id'])->confirmed_at);
    }

    #[DataProvider('criterionCases')]
    public function test_catalog_evaluation_rules(string $code, array $parameters, array $assessments, ?int $attended, ?int $total, string $expected): void
    {
        $record = new AcademicRecord(['assessments' => $assessments, 'attended_classes' => $attended, 'total_classes' => $total]);
        $result = app(CriteriaEvaluator::class)->evaluate(['code' => $code, 'parameters' => $parameters], $record, app(CriteriaCatalog::class)->definition());
        $this->assertSame($expected, $result['status']);
    }

    public static function criterionCases(): array
    {
        $taken = ['FIRST_PARTIAL' => ['was_taken' => true, 'score' => 25], 'SECOND_PARTIAL' => ['was_taken' => true, 'score' => 25]];

        return [
            ['PREVIOUS_ASSESSMENTS_TAKEN', ['assessment_keys' => ['FIRST_PARTIAL']], $taken, null, null, 'PASSED'],
            ['PREVIOUS_ASSESSMENTS_TAKEN', ['assessment_keys' => ['FIRST_PARTIAL']], ['FIRST_PARTIAL' => ['was_taken' => false, 'score' => null]], null, null, 'FAILED'],
            ['MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 90], [], 18, 20, 'PASSED'],
            ['MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 90], [], 17, 20, 'FAILED'],
            ['MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 90], [], null, null, 'PENDING'],
            ['MIN_ASSESSMENT_SCORE', ['assessment_key' => 'FIRST_PARTIAL', 'minimum_score' => 25], $taken, null, null, 'PASSED'],
            ['MIN_ACCUMULATED_SCORE', ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'minimum_total' => 51], $taken, null, null, 'FAILED'],
            ['MIN_ASSESSMENT_AVERAGE', ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'minimum_average' => 25], $taken, null, null, 'PASSED'],
            [CriteriaCatalog::SECOND, [], $taken, null, null, 'FAILED'],
            [CriteriaCatalog::SECOND, [], [], null, null, 'PENDING'],
        ];
    }
}
