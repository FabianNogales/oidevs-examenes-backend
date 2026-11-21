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
use App\Services\Eligibility\Automation\GlobalCriteriaCatalog;
use App\Services\Eligibility\ExamEligibilityInitializer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        return $this->putJson($this->base.'/configuration', ['revision' => $revision, 'catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'criteria' => $criteria]);
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

    private function mergeCsv(string $csv, ?string $reason = null)
    {
        return $this->postJson($this->academic.'/imports/preview', ['mode' => 'MERGE', 'reason' => $reason,
            'file' => UploadedFile::fake()->createWithContent('parcial.csv', $csv)]);
    }

    public function test_removing_criteria_restores_automatic_decisions_only_after_confirmation(): void
    {
        $this->configure([$this->criterion('MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 95])])->assertOk();
        $this->import();
        $payload = ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()];
        $preview = $this->postJson($this->base.'/evaluations/preview', $payload)->assertOk()->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk();
        $this->assertSame('INELIGIBLE', ExamEligibility::first()->status);
        $this->configure([], 1)->assertOk();
        $this->assertSame('INELIGIBLE', ExamEligibility::first()->status);
        $payload['configuration_revision'] = 2;
        $preview = $this->postJson($this->base.'/evaluations/preview?filter=CHANGES', $payload)->assertOk()
            ->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE')
            ->assertJsonPath('data.results.data.0.state_will_change', true)
            ->assertJsonPath('data.results.data.0.traceability_only', false)->json('data.preview_id');
        $this->assertSame('INELIGIBLE', ExamEligibility::first()->status);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame('ELIGIBLE', ExamEligibility::first()->status);
    }

    public function test_filters_precede_pagination_summary_is_global_and_confirm_applies_every_result(): void
    {
        $this->configure()->assertOk();
        $student = (array) DB::table('students')->where('id', $this->fixture['studentId'])->first();
        unset($student['id']);
        foreach (['MANUAL', 'LEGACY', 'INITIALIZED'] as $index => $source) {
            $student['user_id'] = User::factory()->create()->id;
            $student['sis_code'] = '20260000'.($index + 2);
            $student['identity_number'] = '7000000'.($index + 2);
            $student['first_names'] = 'Protegido '.$index;
            $id = DB::table('students')->insertGetId($student);
            DB::table('enrollments')->insert(['course_offering_id' => $this->fixture['offering']->id, 'student_id' => $id,
                'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id]);
            (new ExamEligibility)->forceFill(['exam_id' => $this->exam->id, 'student_id' => $id,
                'status' => $source === 'INITIALIZED' ? 'ELIGIBLE' : 'INELIGIBLE', 'decision_source' => $source])->save();
        }
        $preview = $this->postJson($this->base.'/evaluations/preview?filter=PROTECTED&per_page=1&page=2',
            ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])->assertOk()
            ->assertJsonPath('data.results.meta.total', 2)->assertJsonCount(1, 'data.results.data')
            ->assertJsonPath('data.results.data.0.protected_source', 'LEGACY')
            ->assertJsonPath('data.summary.total', 4)->assertJsonPath('data.summary.state_changes', 1)
            ->assertJsonPath('data.summary.traceability_only', 1)->assertJsonPath('data.summary.protected', 2)
            ->assertJsonPath('data.summary.effective_eligible', 2)->assertJsonPath('data.summary.effective_ineligible', 2)->json('data');
        foreach (['ALL' => 4, 'CHANGES' => 1, 'ELIGIBLE' => 2, 'INELIGIBLE' => 2, 'PENDING' => 0, 'PROTECTED' => 2] as $filter => $total) {
            $this->getJson($this->base.'/evaluations/'.$preview['id'].'?filter='.$filter.'&per_page=1')->assertOk()
                ->assertJsonPath('data.results.meta.total', $total)->assertJsonPath('data.summary', $preview['summary']);
        }
        $this->getJson($this->base.'/evaluations/'.$preview['id'].'?search=202600003&filter=PROTECTED')->assertOk()
            ->assertJsonPath('data.results.meta.total', 1)->assertJsonPath('data.results.data.0.protected_source', 'LEGACY');
        $this->getJson($this->base.'/evaluations/'.$preview['id'].'?search=PROTEGIDO%201')->assertOk()->assertJsonPath('data.results.meta.total', 1);
        $this->getJson($this->base.'/evaluations/'.$preview['id'].'?search[]=x')->assertUnprocessable();
        $this->postJson($this->base.'/evaluations/preview?filter=BAD', ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])->assertUnprocessable();
        $this->postJson($this->base.'/evaluations/'.$preview['preview_id'].'/confirm?filter=PROTECTED&page=2')->assertOk()
            ->assertJsonPath('data.updated', 2)->assertJsonPath('data.manual_preserved', 2);
        $this->assertDatabaseCount('exam_eligibilities', 4);
        $this->assertDatabaseHas('exam_eligibilities', ['student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
    }

    public function test_merge_can_create_attendance_only_then_add_a_score_without_losing_attendance(): void
    {
        $schema = $this->getJson($this->academic.'/import-schema')->assertOk()->assertJsonPath('data.default_mode', 'CREATE_ONLY');
        $this->assertContains('MERGE', $schema->json('data.modes'));
        $preview = $this->mergeCsv("sis,attended_classes,total_classes\n202600001,18,20\n")->assertOk()
            ->assertJsonPath('data.can_confirm', true)->assertJsonPath('data.summary.created_records', 1)->json('data.preview_id');
        $this->assertDatabaseCount('academic_records', 0);
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.created_records', 1);
        $row = AcademicRecord::first();
        $this->assertSame([], $row->assessments);
        $listing = $this->getJson($this->academic)->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode($listing->getContent())->data[0]->assessments);
        $this->configure([$this->criterion()])->assertOk();
        $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])->assertOk()
            ->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE');
        $this->mergeCsv("sis,assessment_key,was_taken,score\n202600001,FIRST_PARTIAL,true,60\n")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $preview = $this->mergeCsv("sis,assessment_key,was_taken,score\n202600001,FIRST_PARTIAL,true,60\n", 'Agregar nota')
            ->assertOk()->assertJsonPath('data.valid_records.0.proposed.attended_classes', 18)
            ->assertJsonPath('data.summary.updated_records', 1)->json('data.preview_id');
        $result = $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated_records', 1)->json('data');
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertExactJson(['data' => $result]);
        $row->refresh();
        $this->assertSame(2, $row->revision);
        $this->assertSame(18, $row->attended_classes);
        $this->assertEquals(60, $row->assessments['FIRST_PARTIAL']['score']);
        $this->getJson($this->academic.'/history')->assertOk()->assertJsonPath('data.0.action', 'ACADEMIC_IMPORT_CONFIRMED');
    }

    public function test_merge_preserves_hidden_notes_and_blanks_and_noop_preserves_revision(): void
    {
        $id = $this->import();
        $preview = $this->mergeCsv("sis,assessment_key,score\n202600001,FIRST_PARTIAL,80\n", 'Corregir parcial')->assertOk()
            ->assertJsonPath('data.valid_records.0.proposed.assessments.SECOND_PARTIAL.score', 25)
            ->assertJsonPath('data.valid_records.0.proposed.attended_classes', 18)->json('data.preview_id');
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk();
        $row = AcademicRecord::find($id);
        $this->assertEquals(80, $row->assessments['FIRST_PARTIAL']['score']);
        $this->assertEquals(25, $row->assessments['SECOND_PARTIAL']['score']);
        $preview = $this->mergeCsv("sis,assessment_key,was_taken,score,attended_classes,total_classes\n202600001,FIRST_PARTIAL,true,,,\n")
            ->assertOk()->assertJsonPath('data.summary.unchanged_records', 1)->json('data.preview_id');
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.unchanged_records', 1);
        $this->assertSame(2, $row->fresh()->revision);
        $this->assertEquals(80, $row->fresh()->assessments['FIRST_PARTIAL']['score']);
    }

    public function test_merge_rejects_duplicate_targets_invalid_rows_and_destructive_taken_change(): void
    {
        $this->import();
        foreach ([
            "sis,was_taken,score\n202600001,true,50\n",
            "sis,assessment_key,score\n202600001,FINAL,50\n",
            "sis,attended_classes,total_classes\n202600001,,20\n",
            "sis,attended_classes,total_classes\n202600001,21,20\n",
            "sis,attended_classes,total_classes\n999999,18,20\n",
            "sis,assessment_key,was_taken\n202600001,FIRST_PARTIAL,false\n",
            "sis,assessment_key,score\n202600001,FIRST_PARTIAL,50\n202600001,FIRST_PARTIAL,60\n",
            "sis,attended_classes,total_classes\n202600001,18,20\n202600001,18,20\n",
        ] as $csv) {
            $preview = $this->mergeCsv($csv, 'Prueba')->assertOk()->assertJsonPath('data.can_confirm', false)->json('data.preview_id');
            $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertUnprocessable();
        }
        $this->assertSame(1, AcademicRecord::first()->revision);
        $this->mergeCsv("sis,sis\n202600001,202600001\n")->assertUnprocessable();
        $this->mergeCsv("sis,nombre_completo\n202600001,Nombre\n")->assertUnprocessable();
        $this->mergeCsv("sis,assessment_key,score\n202600001,FIRST_PARTIAL,40\n", str_repeat('x', 501))->assertUnprocessable();
    }

    public function test_merge_snapshot_conflicts_rollback_retry_and_no_eligibility_side_effects(): void
    {
        $record = $this->import();
        $preview = $this->mergeCsv("sis,attended_classes,total_classes\n202600001,19,20\n", 'Actualizar asistencia')->assertOk()->json('data.preview_id');
        AcademicRecord::find($record)->update(['revision' => 2]);
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertConflict()->assertJsonPath('code', 'AUTOMATION_CONFLICT');
        $this->assertSame(18, AcademicRecord::find($record)->attended_classes);
        $preview = $this->mergeCsv("sis,attended_classes,total_classes\n202600001,19,20\n", 'Actualizar asistencia')->assertOk()->json('data.preview_id');
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('audit unavailable')));
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertServerError();
        $this->assertSame(18, AcademicRecord::find($record)->attended_classes);
        $this->assertNull(AcademicImport::find($preview)->confirmed_at);
        $this->assertDatabaseCount('exam_eligibilities', 0);
    }

    public function test_pending_filter_preserves_manual_state_and_its_final_reason(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        (new ExamEligibility)->forceFill(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'],
            'status' => 'INELIGIBLE', 'reason' => 'Proyecto pendiente', 'decision_source' => 'MANUAL'])->save();
        $preview = $this->postJson($this->base.'/evaluations/preview?filter=PENDING',
            ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])->assertOk()
            ->assertJsonPath('data.results.meta.total', 1)->assertJsonPath('data.results.data.0.effective_status', 'INELIGIBLE')
            ->assertJsonPath('data.results.data.0.protected_source', 'MANUAL')
            ->assertJsonPath('data.results.data.0.effective_reason', 'Proyecto pendiente')
            ->assertJsonPath('data.results.data.0.state_will_change', false)
            ->assertJsonPath('data.results.data.0.traceability_only', false)
            ->assertJsonPath('data.summary.pending', 1)->assertJsonPath('data.summary.protected', 1)
            ->assertJsonPath('data.summary.effective_ineligible', 1)->json('data');
        $this->getJson($this->base.'/evaluations/'.$preview['id'].'?filter=CHANGES')->assertOk()->assertJsonPath('data.results.meta.total', 0);
        $this->getJson($this->base.'/evaluations/'.$preview['id'].'?filter=INELIGIBLE')->assertOk()->assertJsonPath('data.results.meta.total', 1);
    }

    public function test_merge_revalidates_new_record_identity_and_enrollment(): void
    {
        $csv = "sis,attended_classes,total_classes\n202600001,18,20\n";
        $first = $this->mergeCsv($csv)->assertOk()->json('data.preview_id');
        $second = $this->mergeCsv($csv)->assertOk()->json('data.preview_id');
        $this->postJson($this->academic.'/imports/'.$first.'/confirm')->assertOk();
        $this->postJson($this->academic.'/imports/'.$second.'/confirm')->assertConflict();
        $preview = $this->mergeCsv("sis,attended_classes,total_classes\n202600001,19,20\n", 'Actualizar')->assertOk()->json('data.preview_id');
        DB::table('enrollments')->where('student_id', $this->fixture['studentId'])->update(['status' => 'INACTIVE']);
        $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertConflict();
        $this->assertSame(18, AcademicRecord::first()->attended_classes);
        $this->assertSame(1, AcademicRecord::first()->revision);
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
        $this->getJson('/api/v1/admin/eligibility-criteria?search=')->assertOk()->assertJsonPath('meta.total', 6);
        $this->getJson('/api/v1/admin/eligibility-criteria?search[]=bad')->assertUnprocessable();
    }

    public function test_team_proposal_operational_without_external_approval_and_second_instance_locked(): void
    {
        $this->getJson($this->base.'/options')->assertOk()->assertJsonCount(5, 'data.criteria')->assertJsonPath('data.revision', 0);
        $this->exam->update(['evaluation_type' => 'makeup']);
        $this->getJson($this->base.'/options')->assertOk()->assertJsonCount(6, 'data.criteria')
            ->assertJsonPath('data.mandatory_criteria.0.code', CriteriaCatalog::SECOND)->assertJsonPath('data.mandatory_criteria.0.locked', true);
        $response = $this->getJson($this->base.'/configuration')->assertOk();
        $this->assertSame(25, $response->json('data.mandatory_criteria.0.parameters.threshold'));
        $this->configure()->assertOk()->assertJsonPath('data.revision', 1)->assertJsonCount(1, 'data.criteria');
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->configure([], 0)->assertConflict();
        $this->configure([$this->criterion(CriteriaCatalog::SECOND, [])], 1)->assertUnprocessable();
        $this->configure([$this->criterion('MIN_ASSESSMENT_AVERAGE', ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'minimum_average' => 50])], 1)->assertUnprocessable();
        $this->configure([$this->criterion()], 1)->assertOk()->assertJsonCount(2, 'data.criteria');
    }

    public function test_global_catalog_is_fixed_editable_audited_and_admin_only(): void
    {
        $base = '/api/v1/admin/eligibility-criteria';
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 6);
        $this->getJson($base.'/'.CriteriaCatalog::SECOND)->assertOk()->assertJsonPath('data.parameters', []);
        $code = 'MIN_ATTENDANCE_PERCENTAGE';
        $this->patchJson($base.'/'.$code, ['revision' => 1, 'name' => 'Asistencia minima'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson($base.'/'.$code, ['revision' => 1, 'name' => 'Viejo'])->assertConflict();
        $this->patchJson($base.'/'.$code, ['revision' => 2, 'code' => 'ARBITRARY'])->assertUnprocessable();
        $this->postJson($base, ['name' => 'Nuevo'])->assertStatus(405);
        $this->deleteJson($base.'/'.$code)->assertStatus(405);
        $this->getJson($base.'/'.$code.'/history')->assertOk()->assertJsonPath('meta.total', 1);
        $this->fixture['user']->roles()->where('name', 'ADMINISTRADOR')->detach();
        $this->getJson($base)->assertForbidden();
        $this->patchJson($base.'/'.$code.'/status', ['revision' => 2, 'status' => 'INACTIVE'])->assertForbidden();
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
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.data.0.computed_status', 'INELIGIBLE')->assertJsonPath('data.results.data.0.will_update', true)->json('data.preview_id');
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
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE')->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
        $eligibility->refresh();
        $this->patchJson('/api/v1/exams/'.$this->exam->id.'/eligibilities/'.$this->fixture['studentId'], [
            'status' => 'INELIGIBLE', 'reason' => 'Decisión manual justificada', 'revision' => $eligibility->revision])->assertOk();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE')->assertJsonPath('data.results.data.0.effective_status', 'INELIGIBLE')
            ->assertJsonPath('data.results.data.0.manual_override_preserved', true)->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.manual_preserved', 1)->assertJsonPath('data.updated', 0);
        $this->getJson($this->base.'/evaluations')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson($this->base.'/evaluations/'.$confirmation['evaluation_id'])->assertOk()->assertJsonPath('data.results.data.0.computed_status', 'INELIGIBLE');
    }

    public function test_pending_and_no_optional_criteria_preserve_existing_states_and_legacy_decisions(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $legacy = ExamEligibility::create(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.data.0.computed_status', 'PENDING')->assertJsonPath('data.results.data.0.effective_status', 'ELIGIBLE')
            ->assertJsonPath('data.results.data.0.manual_override_preserved', true)->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 0);
        $this->assertSame('LEGACY', $legacy->fresh()->decision_source);
        $this->configure([], 1)->assertOk();
        $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 2])->assertOk()->assertJsonPath('data.results.data.0.will_update', false);
    }

    public function test_stale_preview_after_manual_change_data_change_or_expiry_returns_409(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $record = $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()->json('data.preview_id');
        AcademicRecord::find($record)->update(['revision' => 2]);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()->json('data.preview_id');
        ExamEligibility::create(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()->json('data.preview_id');
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
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()->json('data.preview_id');
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('Audit failed')));
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertStatus(500);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->assertNull(EligibilityEvaluation::where('preview_id', $preview)->first()->confirmed_at);
    }

    public function test_pending_without_current_eligibility_never_persists_and_initialized_row_can_be_automated(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.data.0.effective_status', null)->assertJsonPath('data.results.data.0.will_update', false)->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.pending', 1);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        app(ExamEligibilityInitializer::class)->forExam($this->exam, $this->fixture['user']->id);
        $this->assertSame('INITIALIZED', ExamEligibility::first()->decision_source);
        $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()
            ->assertJsonPath('data.results.data.0.manual_override_preserved', false)->json('data.preview_id');
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
        $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertUnprocessable()->assertJsonPath('code', 'CRITERIA_CONFLICT');
        $this->configure([], 1)->assertOk();
        $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 2])->assertOk();
    }

    public function test_configuration_enrollment_and_exam_changes_invalidate_preview_and_history_keeps_original(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()->json('data');
        $this->configure([$this->criterion('MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 95])], 1)->assertOk();
        $this->postJson($this->base.'/evaluations/'.$preview['preview_id'].'/confirm')->assertConflict();
        $this->getJson($this->base.'/evaluations/'.$preview['id'])->assertOk()->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE');
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 2])->assertOk()->json('data.preview_id');
        $this->exam->update(['start_time' => '11:00:00']);
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertConflict();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 2])->assertOk()->json('data.preview_id');
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
        $this->postJson('/api/v1/admin/eligibility-policies', ['name' => 'Invalid', 'definition' => $definition])->assertNotFound();
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
            $policy = EligibilityPolicy::create(['family' => (string) Str::uuid(), 'name' => 'Registro historico', 'definition' => app(CriteriaCatalog::class)->definition()])->id;
            $this->assertTrue(EligibilityPolicy::find($policy)->created_at->equalTo(now()));
            $preview = $this->csv("202600001,FIRST_PARTIAL,true,50,18,20\n")->assertOk()->json('data.preview_id');
            $this->assertTrue(AcademicImport::find($preview)->created_at->equalTo(now()));
            $record = $this->postJson($this->academic.'/imports/'.$preview.'/confirm')->assertOk()->json('data.record_ids.0');
            $this->assertTrue(AcademicRecord::find($record)->created_at->equalTo(now()));
            $this->configure([$this->criterion()])->assertOk();
            $evaluation = $this->postJson($this->base.'/evaluations/preview', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()->json('data.id');
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
        $preview = $this->postJson($this->base.'/evaluations/preview?per_page=1', ['catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'configuration_revision' => 1])->assertOk()
            ->assertJsonCount(1, 'data.results.data')->assertJsonPath('data.results.meta.total', 2)->assertJsonPath('data.summary.eligible', 2)->json('data');
        $this->getJson($this->base.'/evaluations/'.$preview['id'].'?page=2&per_page=1')->assertOk()->assertJsonPath('data.results.data.0.student.id', $second);
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

    private function globalRevision(): string
    {
        return app(GlobalCriteriaCatalog::class)->revision();
    }

    private function globalStatus(string $code, string $status)
    {
        $revision = DB::table('eligibility_criteria')->where('code', $code)->value('revision');

        return $this->patchJson('/api/v1/admin/eligibility-criteria/'.$code.'/status', ['revision' => $revision, 'status' => $status]);
    }

    public function test_optional_deactivation_is_a_reference_and_reactivation_respects_saved_selection(): void
    {
        $code = 'MIN_ATTENDANCE_PERCENTAGE';
        $this->configure([$this->criterion()])->assertOk();
        $this->import();
        $this->globalStatus($code, 'INACTIVE')->assertOk()->assertJsonPath('data.revision', 2);
        $this->getJson($this->base.'/configuration')->assertOk()->assertJsonCount(0, 'data.selected_criteria')->assertJsonPath('data.inactive_criteria.0.code', $code)->assertJsonPath('data.revision', 1);
        $this->getJson($this->base.'/options')->assertOk()->assertJsonCount(4, 'data.criteria');
        $this->configure([$this->criterion()], 1)->assertUnprocessable();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])->assertOk()->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE')->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertDatabaseHas('exam_eligibilities', ['status' => 'ELIGIBLE']);
        $this->globalStatus($code, 'ACTIVE')->assertOk();
        $this->getJson($this->base.'/configuration')->assertJsonPath('data.selected_criteria.0.parameters.minimum_percentage', 90);
        $this->globalStatus($code, 'INACTIVE')->assertOk();
        $this->configure([], 1)->assertOk();
        $this->globalStatus($code, 'ACTIVE')->assertOk();
        $this->getJson($this->base.'/configuration')->assertJsonCount(0, 'data.selected_criteria');
    }

    public function test_admin_can_disable_second_instance_without_changing_confirmed_results_or_habilitations(): void
    {
        $this->exam->update(['evaluation_type' => 'makeup']);
        $this->configure()->assertOk();
        $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])->assertOk()->json('data');
        $this->postJson($this->base.'/evaluations/'.$preview['preview_id'].'/confirm')->assertOk();
        $stored = EligibilityEvaluation::find($preview['id'])->toArray();
        $this->globalStatus(CriteriaCatalog::SECOND, 'INACTIVE')->assertOk();
        $this->getJson($this->base.'/configuration')->assertJsonCount(0, 'data.mandatory_criteria');
        $this->assertSame('INELIGIBLE', ExamEligibility::first()->status);
        $this->assertSame($stored, EligibilityEvaluation::find($preview['id'])->toArray());
        $pending = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])->assertOk()->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE')->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$pending.'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame('ELIGIBLE', ExamEligibility::first()->status);
        $this->configure([$this->criterion('MIN_ASSESSMENT_AVERAGE', ['assessment_keys' => CriteriaCatalog::ASSESSMENTS, 'minimum_average' => 20])], 1)->assertOk();
        $this->globalStatus(CriteriaCatalog::SECOND, 'ACTIVE')->assertOk();
        $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 2, 'catalog_revision' => $this->globalRevision()])->assertUnprocessable()->assertJsonPath('code', 'CRITERIA_CONFLICT');
    }

    public function test_every_global_change_invalidates_preview_and_stale_catalog_requests(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $this->import();
        $code = 'MIN_ASSESSMENT_SCORE';
        $base = '/api/v1/admin/eligibility-criteria/'.$code;
        foreach ([['name' => 'Nombre actualizado'], ['description' => 'Descripcion nueva'], ['status' => 'INACTIVE']] as $change) {
            $hash = $this->globalRevision();
            $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1, 'catalog_revision' => $hash])->assertOk()->json('data');
            $revision = DB::table('eligibility_criteria')->where('code', $code)->value('revision');
            $this->patchJson($base.(isset($change['status']) ? '/status' : ''), ['revision' => $revision] + $change)->assertOk();
            $this->assertNotSame($hash, $this->globalRevision());
            $this->postJson($this->base.'/evaluations/'.$preview['preview_id'].'/confirm')->assertConflict()->assertJsonPath('code', 'AUTOMATION_CONFLICT');
            $this->putJson($this->base.'/configuration', ['revision' => 1, 'catalog_revision' => $hash, 'criteria' => []])->assertConflict();
            $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1, 'catalog_revision' => $hash])->assertConflict();
            $this->assertDatabaseCount('exam_eligibilities', 0);
            $this->getJson($this->base.'/evaluations/'.$preview['id'])->assertOk()->assertJsonPath('data.configuration.catalog_revision', $hash);
        }
    }

    public function test_manual_conditions_are_plain_text_visible_in_hu12_and_never_evaluated(): void
    {
        $payload = ['revision' => 0, 'catalog_revision' => $this->globalRevision(), 'criteria' => []];
        foreach ([str_repeat('x', 501), '<script>alert(1)</script>', ['not text']] as $bad) {
            $this->putJson($this->base.'/configuration', $payload + ['manual_review_conditions' => $bad])->assertUnprocessable();
        }        $text = str_repeat('a', 500);
        $this->putJson($this->base.'/configuration', $payload + ['manual_review_conditions' => $text])->assertOk()->assertJsonPath('data.manual_review_conditions', $text);
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/eligibilities')->assertOk()->assertJsonPath('exam.manual_review_conditions', $text)->assertJsonPath('data', []);
        $this->configure([], 1)->assertOk()->assertJsonPath('data.manual_review_conditions', $text);
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 2, 'catalog_revision' => $this->globalRevision()])->assertOk()->assertJsonPath('data.configuration.manual_review_conditions', $text)->assertJsonPath('data.results.data.0.computed_status', 'ELIGIBLE')->assertJsonPath('data.results.data.0.criteria_results', [])->json('data.preview_id');
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertDatabaseHas('exam_eligibilities', ['status' => 'ELIGIBLE']);
        foreach (['0', null, '', 'Recordatorio'] as $value) {
            $revision = DB::table('exam_eligibility_configurations')->where('exam_id', $this->exam->id)->value('revision');
            $this->putJson($this->base.'/configuration', ['revision' => $revision, 'catalog_revision' => $this->globalRevision(),                'criteria' => [], 'manual_review_conditions' => $value])->assertOk()->assertJsonPath('data.manual_review_conditions', $value === '' ? null : $value);
        }        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Exam', 'entity_id' => $this->exam->id, 'action' => 'ELIGIBILITY_CONFIGURATION_SAVED']);
    }

    public function test_different_exams_have_independent_thresholds_and_legacy_policy_never_changes_the_catalog(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $other = Exam::create($this->examPayload($this->fixture['room']->id) + ['course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED']);
        $this->putJson('/api/v1/exams/'.$other->id.'/eligibility-automation/configuration', ['revision' => 0,            'catalog_revision' => $this->globalRevision(), 'criteria' => [$this->criterion('MIN_ATTENDANCE_PERCENTAGE', ['minimum_percentage' => 80])]])->assertOk();
        $this->getJson($this->base.'/configuration')->assertJsonPath('data.selected_criteria.0.parameters.minimum_percentage', 90);
        $this->getJson('/api/v1/exams/'.$other->id.'/eligibility-automation/configuration')->assertJsonPath('data.selected_criteria.0.parameters.minimum_percentage', 80);
        $definition = app(CriteriaCatalog::class)->definition();
        $definition['allowed_criteria'] = [CriteriaCatalog::SECOND];
        $definition['second_instance']['threshold'] = 99;
        EligibilityPolicy::first()->update(['definition' => $definition]);
        $this->getJson($this->base.'/options')->assertJsonCount(5, 'data.criteria');
        $this->exam->update(['evaluation_type' => 'makeup']);
        $this->getJson($this->base.'/configuration')->assertJsonPath('data.mandatory_criteria.0.parameters.threshold', 25);
    }

    public function test_admin_noop_keeps_revision_and_audit_failure_rolls_back_catalog_change(): void
    {
        $code = 'MIN_ATTENDANCE_PERCENTAGE';
        $hash = $this->globalRevision();
        $this->globalStatus($code, 'ACTIVE')->assertOk()->assertJsonPath('data.revision', 1);
        $this->assertSame($hash, $this->globalRevision());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ELIGIBILITY_CRITERION_STATUS_CHANGED']);
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('Audit failed')));
        $this->globalStatus($code, 'INACTIVE')->assertStatus(500);
        $this->assertSame($hash, $this->globalRevision());
        $this->assertDatabaseHas('eligibility_criteria', ['code' => $code, 'status' => 'ACTIVE', 'revision' => 1]);
    }

    public function test_historical_policy_snapshots_are_read_without_rewriting_them(): void
    {
        $configuration = ['exam_id' => $this->exam->id, 'revision' => 1, 'policy' => ['id' => EligibilityPolicy::first()->id, 'version' => 1]];
        $row = EligibilityEvaluation::create(['preview_id' => (string) Str::uuid(),
            'exam_id' => $this->exam->id, 'eligibility_policy_id' => EligibilityPolicy::first()->id,
            'created_by' => $this->fixture['user']->id,
            'snapshot' => ['configuration' => $configuration, 'policy_definition' => ['origin' => 'TEAM_PROPOSAL'], 'fingerprint' => 'old-model'],
            'results' => [['student' => ['id' => $this->fixture['studentId']], 'computed_status' => 'PENDING']],
            'expires_at' => now()->addMinutes(30)]);
        $before = $row->fresh()->toArray();
        $this->getJson($this->base.'/evaluations/'.$row->id)->assertOk()
            ->assertJsonPath('data.configuration.policy.version', 1)
            ->assertJsonPath('data.policy_definition.origin', 'TEAM_PROPOSAL')
            ->assertJsonPath('data.results.data.0.computed_status', 'PENDING');
        $this->postJson($this->base.'/evaluations/'.$row->preview_id.'/confirm')->assertConflict();
        $this->assertSame($before, $row->fresh()->toArray());
    }

    public function test_confirmed_evaluation_remains_idempotent_after_global_change(): void
    {
        $this->configure([$this->criterion()])->assertOk();
        $this->import();
        $preview = $this->postJson($this->base.'/evaluations/preview', ['configuration_revision' => 1, 'catalog_revision' => $this->globalRevision()])
            ->assertOk()->json('data.preview_id');
        $confirmed = $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertOk()->json('data');
        $before = ExamEligibility::first()->toArray();
        $this->globalStatus('MIN_ATTENDANCE_PERCENTAGE', 'INACTIVE')->assertOk();
        $this->postJson($this->base.'/evaluations/'.$preview.'/confirm')->assertExactJson(['data' => $confirmed]);
        $this->assertSame($before, ExamEligibility::first()->toArray());
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
