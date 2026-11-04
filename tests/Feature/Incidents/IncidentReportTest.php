<?php

namespace Tests\Feature\Incidents;

use App\Models\Exam;
use App\Models\Role;
use App\Models\SpecialCase;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentReportTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/incident-reports';

    private function manual(array $attributes = []): SpecialCase
    {
        return SpecialCase::create($attributes + ['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'type' => 'IDENTIFICATION', 'description' => 'Documento ilegible.', 'reported_by' => $this->owner->id, 'status' => 'PENDING']);
    }

    private function automatic(array $overrides = []): int
    {
        return DB::table('audit_logs')->insertGetId($overrides + [
            'user_id' => $this->owner->id, 'action' => 'ENTRY_QR_WRONG_EXAM', 'entity_type' => 'Exam',
            'entity_id' => $this->exam->id, 'created_at' => now(),
            'new_values' => json_encode(['student_id' => $this->student->id, 'qr_exam_id' => 123, 'reason' => 'WRONG_EXAM']),
        ]);
    }

    private function administrator(): User
    {
        $admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $admin;
    }

    private function foreignExam(): Exam
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::create(['user_id' => $user->id, 'institutional_code' => 'OTHER', 'identity_number' => 'OTHER',
            'first_names' => 'Otro', 'last_names' => 'Docente', 'status' => 'ACTIVE']);
        $offering = $this->exam->courseOffering->replicate();
        $offering->teacher_id = $teacher->id;
        $offering->save();
        $exam = $this->exam->replicate();
        $exam->course_offering_id = $offering->id;
        $exam->created_by = $user->id;
        $exam->name = 'Examen ajeno';
        $exam->save();

        return $exam;
    }

    public function test_owner_list_and_detail_include_context_origin_reason_and_responsible(): void
    {
        $manual = $this->manual();
        $automatic = $this->automatic();
        $this->actingAs($this->owner)->getJson(self::BASE.'?exam_id='.$this->exam->id)->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->getJson(self::BASE.'/'.$manual->id)->assertOk()
            ->assertJsonPath('data.reason', 'Documento ilegible.')->assertJsonPath('data.origin', 'MANUAL')
            ->assertJsonPath('data.student.sis_code', $this->student->sis_code)
            ->assertJsonPath('data.student.identity_number', $this->student->identity_number)
            ->assertJsonPath('data.exam.id', $this->exam->id)
            ->assertJsonPath('data.responsible.name', 'Docente Responsable')
            ->assertJsonStructure(['data' => ['reported_at', 'description', 'type', 'status']]);
        $this->getJson(self::BASE.'/qr-'.$automatic)->assertOk()
            ->assertJsonPath('data.origin', 'AUTOMATIC_QR')->assertJsonPath('data.reason', 'WRONG_EXAM');
    }

    public function test_search_matches_sis_ci_names_and_manual_or_qr_identifier(): void
    {
        $manual = $this->manual();
        $automatic = $this->automatic();
        $this->manual(['student_id' => null, 'description' => 'Sin estudiante.']);
        $this->actingAs($this->owner);
        foreach ([$this->student->sis_code, $this->student->identity_number, 'estudiante identificable', 'IDENTIFICABLE estudiante'] as $search) {
            $this->getJson(self::BASE.'?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('meta.total', 2);
        }
        $this->getJson(self::BASE.'?search='.$manual->id)->assertOk()
            ->assertJsonFragment(['id' => $manual->id, 'origin' => 'MANUAL']);
        $this->getJson(self::BASE.'?search=qr-'.$automatic)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 'qr-'.$automatic);
        foreach (['%', '_', "' OR 1=1 --"] as $search) {
            $this->getJson(self::BASE.'?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('meta.total', 0);
        }
    }

    public function test_type_status_origin_and_student_filters_combine_correctly(): void
    {
        $this->manual();
        $resolved = $this->manual(['type' => 'ENVIRONMENT', 'status' => 'RESOLVED', 'student_id' => null]);
        $this->automatic();
        $this->actingAs($this->owner)->getJson(self::BASE.'?type=ENVIRONMENT&status=RESOLVED&origin=MANUAL')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $resolved->id);
        $this->getJson(self::BASE.'?status=REJECTED&student_id='.$this->student->id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.origin', 'AUTOMATIC_QR');
        $this->getJson(self::BASE.'?status=PENDING&type=WRONG_EXAM_QR')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_dates_use_bolivian_calendar_with_inclusive_end_date(): void
    {
        config(['app.timezone' => 'UTC']);
        $before = $this->manual();
        $start = $this->manual();
        $end = $this->manual();
        DB::table('special_cases')->where('id', $before->id)->update(['created_at' => '2026-11-01 03:59:59']);
        DB::table('special_cases')->where('id', $start->id)->update(['created_at' => '2026-11-01 04:00:00']);
        DB::table('special_cases')->where('id', $end->id)->update(['created_at' => '2026-11-02 03:59:59']);
        $this->automatic(['created_at' => '2026-11-02 04:00:00']);
        $this->actingAs($this->owner)->getJson(self::BASE.'?date=2026-11-01')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::BASE.'?date_from=2026-11-01&date_to=2026-11-01')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::BASE.'?date_from=2026-11-02')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::BASE.'?date_to=2026-10-31')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_summary_counts_all_filtered_records_independently_of_pagination(): void
    {
        $this->manual();
        $this->manual();
        $this->manual(['type' => 'ENVIRONMENT', 'status' => 'RESOLVED']);
        $this->automatic();
        $this->actingAs($this->owner)->getJson(self::BASE.'/summary?per_page=1&page=5')->assertOk()
            ->assertJsonPath('data.total', 4)->assertJsonPath('data.by_type.IDENTIFICATION', 2)
            ->assertJsonPath('data.by_type.WRONG_EXAM_QR', 1)->assertJsonPath('data.by_type.ENVIRONMENT', 1)
            ->assertJsonPath('data.by_type.SUSPECTED_IMPERSONATION', 0)
            ->assertJsonPath('data.by_origin.MANUAL', 3)->assertJsonPath('data.by_origin.AUTOMATIC_QR', 1)
            ->assertJsonPath('data.by_status.PENDING', 2)->assertJsonPath('data.by_status.RESOLVED', 1);
        $this->getJson(self::BASE.'/summary?origin=AUTOMATIC_QR')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson(self::BASE.'/summary?search=sin-coincidencia')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_teacher_cannot_list_summarize_read_or_export_other_exam_reports(): void
    {
        $foreign = $this->foreignExam();
        $foreignManual = $this->manual(['exam_id' => $foreign->id]);
        $foreignQr = $this->automatic(['entity_id' => $foreign->id]);
        $own = $this->manual();
        $this->actingAs($this->owner)->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->id);
        $this->getJson(self::BASE.'/summary')->assertOk()->assertJsonPath('data.total', 1);
        foreach (['', '/summary', '/export'] as $path) {
            $this->getJson(self::BASE.$path.'?exam_id='.$foreign->id)->assertForbidden();
        }
        $this->getJson(self::BASE.'/'.$foreignManual->id)->assertNotFound();
        $this->getJson(self::BASE.'/qr-'.$foreignQr)->assertNotFound();
        $csv = $this->get(self::BASE.'/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Examen ajeno', $csv);
    }

    public function test_active_administrator_can_select_any_exam_and_read_both_sources(): void
    {
        $foreign = $this->foreignExam();
        $this->manual();
        $manual = $this->manual(['exam_id' => $foreign->id]);
        $automatic = $this->automatic(['entity_id' => $foreign->id]);
        $this->actingAs($this->administrator())->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson(self::BASE.'?exam_id='.$foreign->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::BASE.'/'.$manual->id)->assertOk()->assertJsonPath('data.exam.name', 'Examen ajeno');
        $this->getJson(self::BASE.'/qr-'.$automatic)->assertOk();
        $this->getJson(self::BASE.'/summary?exam_id='.$foreign->id)->assertOk()->assertJsonPath('data.total', 2);
        $csv = $this->get(self::BASE.'/export?exam_id='.$foreign->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Examen ajeno', $csv);
    }

    public function test_collaborator_keeps_hu27_access_but_cannot_use_global_reports_or_export(): void
    {
        $manual = $this->manual();
        $this->actingAs($this->collaborator())->getJson($this->url())->assertOk();
        foreach (['', '/summary', '/export', '/'.$manual->id] as $path) {
            $this->getJson(self::BASE.$path)->assertForbidden();
        }
    }

    public function test_revoked_admin_role_and_inactive_users_have_no_report_access(): void
    {
        $admin = $this->administrator();
        DB::table('role_user')->where('user_id', $admin->id)->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::BASE)->assertForbidden();
        DB::table('role_user')->where('user_id', $admin->id)->update(['status' => 'ACTIVE']);
        $admin->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson(self::BASE.'/export')->assertUnauthorized();
    }

    public function test_no_matches_returns_informative_empty_result_and_zero_counters(): void
    {
        $this->actingAs($this->owner)->getJson(self::BASE.'?search=ausente')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('message', 'No se encontraron incidencias con los criterios seleccionados.');
        $this->getJson(self::BASE.'/summary')->assertOk()->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.by_type.WRONG_EXAM_QR', 0);
    }

    public function test_export_respects_filters_includes_all_pages_and_does_not_modify_records(): void
    {
        $this->manual();
        $this->manual();
        $this->automatic();
        $beforeManual = DB::table('special_cases')->get()->toJson();
        $beforeAudit = DB::table('audit_logs')->get()->toJson();
        $response = $this->actingAs($this->owner)->get(self::BASE.'/export?origin=MANUAL&per_page=1&page=2')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = $this->csvRows($csv);
        $this->assertCount(3, $rows);
        $this->assertSame('MANUAL', $rows[1][4]);
        $this->assertSame('MANUAL', $rows[2][4]);
        $this->assertSame($beforeManual, DB::table('special_cases')->get()->toJson());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->get()->toJson());
        $this->assertDatabaseCount('infractions', 0);
    }

    public function test_csv_escapes_formulas_and_preserves_quoted_multiline_descriptions(): void
    {
        $description = "=HYPERLINK(\"bad\")\nSegunda línea, con coma";
        $this->manual(['description' => $description]);
        $this->actingAs($this->owner);
        $rows = $this->csvRows($this->get(self::BASE.'/export')->assertOk()->streamedContent());
        $this->assertSame("'".$description, $rows[1][7]);
        $this->assertCount(16, $rows[1]);
    }

    public function test_legacy_records_without_student_or_actor_are_readable(): void
    {
        $this->manual(['student_id' => null]);
        $event = $this->automatic(['user_id' => null, 'new_values' => null]);
        $this->actingAs($this->owner)->getJson(self::BASE.'/qr-'.$event)->assertOk()
            ->assertJsonPath('data.student', null)->assertJsonPath('data.responsible', null);
        $this->get(self::BASE.'/export')->assertOk();
    }

    public function test_invalid_filters_missing_exam_and_guest_are_rejected(): void
    {
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->getJson(self::BASE.'/export')->assertUnauthorized();
        $this->actingAs($this->owner);
        foreach (['date=2026-02-30', 'date_from=invalid', 'date_from=2026-11-02&date_to=2026-11-01',
            'status[]=PENDING', 'status=bad-state', 'exam_id=0', 'exam_id=9999999999999999999999999',
            'search[]=bad', 'type=INVALID', 'per_page=101', 'page=0'] as $query) {
            $this->getJson(self::BASE.'?'.$query)->assertUnprocessable();
        }
        $this->getJson(self::BASE.'?exam_id=999999')->assertNotFound();
        $this->getJson(self::BASE.'/999999999999999999999999999')->assertNotFound();
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
