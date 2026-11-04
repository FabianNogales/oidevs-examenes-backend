<?php

namespace Tests\Feature\Incidents;

use App\Models\Exam;
use App\Models\Role;
use App\Models\SpecialCase;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Incidents\IncidentIdentity;
use App\Services\Reports\TextReportPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class ExamIncidentReportContractTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    private function manual(array $attributes = []): SpecialCase
    {
        return SpecialCase::create($attributes + ['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'type' => 'IDENTIFICATION', 'description' => 'Documento ilegible.', 'reported_by' => $this->owner->id, 'status' => 'PENDING']);
    }

    private function event(): int
    {
        return DB::table('audit_logs')->insertGetId(['user_id' => $this->owner->id,
            'action' => 'ENTRY_QR_WRONG_EXAM', 'entity_type' => 'Exam', 'entity_id' => $this->exam->id, 'created_at' => now(),
            'new_values' => json_encode(['student_id' => $this->student->id, 'qr_exam_id' => 123, 'reason' => 'WRONG_EXAM'])]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $admin;
    }

    private function foreignExam(): Exam
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::create(['user_id' => $user->id, 'institutional_code' => 'HU29-OTHER', 'identity_number' => 'HU29-OTHER',
            'first_names' => 'Otro', 'last_names' => 'Docente', 'status' => 'ACTIVE']);
        $offering = $this->exam->courseOffering->replicate();
        $offering->teacher_id = $teacher->id;
        $offering->save();
        $exam = $this->exam->replicate();
        $exam->course_offering_id = $offering->id;
        $exam->created_by = $user->id;
        $exam->name = 'Examen privado ajeno';
        $exam->save();

        return $exam;
    }

    public function test_empty_list_includes_five_zero_counters_context_and_permissions(): void
    {
        $response = $this->actingAs($this->owner)->getJson($this->url())->assertOk()
            ->assertJsonPath('exam.id', $this->exam->id)->assertJsonPath('permissions.can_create', true)
            ->assertJsonPath('permissions.can_export', true)->assertJsonPath('summary.total', 0)
            ->assertJsonPath('meta.total', 0)->assertJsonPath('filters.statuses', [])->assertJsonCount(0, 'data');
        $this->assertCount(5, $response->json('summary.by_type'));
        $this->assertSame(0, array_sum($response->json('summary.by_type')));
        $this->getJson($this->url().'?page=2')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_filtered_summary_and_stable_pagination_include_all_matches_and_status_catalog_is_unfiltered(): void
    {
        $ids = [];
        for ($i = 0; $i < 13; $i++) {
            $ids[] = $this->manual()->id;
        }
        $this->manual(['type' => 'ENVIRONMENT', 'status' => 'RESOLVED']);
        $this->event();
        DB::table('special_cases')->update(['created_at' => now()]);
        $this->actingAs($this->owner);
        $page = $this->getJson($this->url().'?type=IDENTIFICATION&per_page=10&page=1')->assertOk()
            ->assertJsonPath('meta.total', 13)->assertJsonPath('summary.total', 13)
            ->assertJsonPath('summary.by_type.IDENTIFICATION', 13)->assertJsonPath('summary.by_type.WRONG_EXAM', 0)
            ->assertJsonCount(10, 'data')->assertJsonPath('data.0.id', $ids[12]);
        $second = $this->getJson($this->url().'?type=IDENTIFICATION&per_page=10&page=2')->assertOk()
            ->assertJsonPath('summary.total', 13)->assertJsonCount(3, 'data');
        $this->assertSame([], array_intersect(array_column($page->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $statuses = array_column($page->json('filters.statuses'), 'value');
        $this->assertSame(['REGISTERED', 'REPORTED', 'RESOLVED'], $statuses);
        $this->getJson($this->url().'?status=RESOLVED')->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.status', 'RESOLVED')->assertJsonPath('filters.statuses', $page->json('filters.statuses'));
        $this->getJson($this->url().'?status=CONFIRMED_INFRACTION')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_search_and_combined_filters_preserve_sis_ci_names_and_both_incident_identifiers(): void
    {
        $this->student->update(['sis_code' => '00123', 'identity_number' => '00099', 'first_names' => 'María Elena', 'last_names' => 'ñúñez']);
        $manual = $this->manual();
        $event = $this->event();
        $automaticId = IncidentIdentity::automatic($event);
        $this->manual(['student_id' => null, 'type' => 'ENVIRONMENT']);
        $this->actingAs($this->owner);
        foreach (['00123', '00099', 'maría', 'Ñúñez María'] as $search) {
            $this->getJson($this->url().'?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('meta.total', 2);
        }
        foreach ([(string) $manual->id, '#'.$manual->id] as $search) {
            $this->getJson($this->url().'?'.http_build_query(['search' => $search, 'type' => 'IDENTIFICATION']))
                ->assertOk()->assertJsonPath('data.0.id', $manual->id);
        }
        $this->getJson($this->url().'?'.http_build_query(['search' => '#'.$automaticId, 'type' => 'WRONG_EXAM', 'status' => 'REGISTERED']))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('summary.by_type.WRONG_EXAM', 1)
            ->assertJsonPath('data.0.id', $automaticId)->assertJsonPath('data.0.origin', 'AUTOMATIC');
        foreach (['%', '_', "' OR 1=1 --"] as $search) {
            $this->getJson($this->url().'?'.http_build_query(['search' => $search]))->assertOk()->assertJsonPath('summary.total', 0);
        }
    }

    public function test_dates_use_inclusive_bolivian_days_in_list_summary_and_export(): void
    {
        config(['app.timezone' => 'UTC']);
        foreach (['2026-11-02 03:59:59', '2026-11-02 04:00:00', '2026-11-03 03:59:59', '2026-11-03 04:00:00'] as $clock) {
            $incident = $this->manual();
            DB::table('special_cases')->where('id', $incident->id)->update(['created_at' => $clock]);
        }
        $query = 'date_from=2026-11-02&date_to=2026-11-02';
        $this->actingAs($this->owner)->getJson($this->url().'?'.$query)->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('summary.total', 2);
        $csv = $this->get($this->url().'/export?format=csv&'.$query)->assertOk()->getContent();
        $rows = $this->csvRows($csv);
        $this->assertCount(3, $rows);
        $this->assertSame('2026-11-03T03:59:59.000000Z', $rows[1][9]);
        $this->assertSame('2026-11-02T04:00:00.000000Z', $rows[2][9]);
        $this->getJson($this->url().'?date_from=2026-11-03')->assertOk()->assertJsonPath('summary.total', 1);
        $this->getJson($this->url().'?date_to=2026-11-01')->assertOk()->assertJsonPath('summary.total', 1);
    }

    public function test_postgres_case_folding_matches_uppercase_accented_profile_names(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite LOWER is ASCII-only; Unicode case folding is verified in PostgreSQL.');
        }
        $this->student->update(['first_names' => 'MARÍA', 'last_names' => 'ÑÚÑEZ']);
        $this->manual();
        $this->actingAs($this->owner)->getJson($this->url().'?'.http_build_query(['search' => 'maría ñúñez']))
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_admin_catalog_includes_empty_exams_searches_subject_and_rejects_non_admins(): void
    {
        $foreign = $this->foreignExam();
        $endpoint = '/api/v1/admin/incidents/exams';
        $this->actingAs($this->owner)->getJson($endpoint)->assertForbidden();
        $this->actingAs($this->collaborator())->getJson($endpoint)->assertForbidden();
        $this->actingAs($this->admin())->getJson($endpoint.'?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
        $this->getJson($endpoint.'?'.http_build_query(['search' => 'privado Materia']))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.subject_name', 'Materia');
        $this->getJson($endpoint.'?search=sin-coincidencia')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
        $this->getJson($endpoint.'?page=999')->assertUnprocessable();
    }

    public function test_admin_reads_and_exports_without_receiving_creation_or_entry_control_permissions(): void
    {
        $manual = $this->manual();
        $this->actingAs($this->admin())->getJson($this->url())->assertOk()
            ->assertJsonPath('permissions.can_create', false)->assertJsonPath('permissions.can_export', true);
        $this->getJson($this->url().'/'.$manual->id)->assertOk()->assertJsonPath('data.reported_by.id', $this->owner->id);
        $this->get($this->url().'/export?format=csv')->assertOk();
        $this->postJson($this->url(), ['type' => 'ENVIRONMENT', 'description' => 'Sin permiso.'])->assertForbidden();
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/entry-control/students')->assertForbidden();
        $this->assertDatabaseCount('special_cases', 1);
    }

    public function test_collaborator_keeps_basic_consultation_but_cannot_export_or_escalate_access(): void
    {
        $manual = $this->manual();
        $collaborator = $this->collaborator();
        $this->actingAs($collaborator)->getJson($this->url())->assertOk()
            ->assertJsonPath('permissions.can_create', true)->assertJsonPath('permissions.can_export', false);
        foreach (['csv', 'pdf'] as $format) {
            $this->getJson($this->url().'/export?format='.$format.'&from=admin')->assertUnprocessable();
            $this->getJson($this->url().'/export?format='.$format)->assertForbidden();
        }
        DB::table('exam_collaborators')->where('user_id', $collaborator->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url().'/'.$manual->id)->assertForbidden();
    }

    public function test_foreign_exam_and_incident_ids_cannot_leak_through_list_detail_or_download(): void
    {
        $foreign = $this->foreignExam();
        $incident = $this->manual(['exam_id' => $foreign->id]);
        $this->actingAs($this->owner);
        foreach (['', '/export?format=csv', '/export?format=pdf', '/'.$incident->id] as $suffix) {
            $this->getJson('/api/v1/exams/'.$foreign->id.'/incidents'.$suffix)->assertForbidden();
        }
        $this->getJson($this->url().'/'.$incident->id)->assertNotFound();
        $this->getJson($this->url().'?from=admin&search=privado')->assertOk()->assertJsonPath('summary.total', 0);
    }

    public function test_csv_exports_entire_filtered_result_with_metadata_safe_text_and_no_side_effects(): void
    {
        $description = " \t=HYPERLINK(\"bad\")\nSegunda línea, revisión de María";
        $this->student->update(['sis_code' => '00123', 'identity_number' => '00099']);
        for ($i = 0; $i < 12; $i++) {
            $this->manual(['description' => $description]);
        }
        $this->event();
        $this->manual(['exam_id' => $this->foreignExam()->id, 'description' => 'PRIVADO']);
        $before = [DB::table('special_cases')->get()->toJson(), DB::table('audit_logs')->get()->toJson()];
        $response = $this->actingAs($this->owner)->get($this->url().'/export?format=csv&type=IDENTIFICATION')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->getContent();
        $rows = $this->csvRows($csv);
        $this->assertCount(13, $rows);
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame("'".$description, $row[8]);
            $this->assertSame('00123', $row[13]);
            $this->assertSame('00099', $row[14]);
            $this->assertSame('America/La_Paz', $row[18]);
            $this->assertSame('EXAM_INCIDENTS', $row[19]);
            $this->assertCount(20, $row);
        }
        $this->assertStringNotContainsString('PRIVADO', $csv);
        $this->assertStringNotContainsString('Filtros', $csv);
        $this->assertStringNotContainsString('"type":', $csv);
        $this->assertSame($before, [DB::table('special_cases')->get()->toJson(), DB::table('audit_logs')->get()->toJson()]);
        $this->assertDatabaseCount('infractions', 0);
    }

    public function test_pdf_is_real_paginated_content_and_empty_reports_are_nonempty_files(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->manual(['description' => 'Revisión número '.$i.'. Documento con fotografía ilegible.']);
        }
        $response = $this->actingAs($this->owner)->get($this->url().'/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('/Count 2', $pdf);
        $this->assertStringContainsString('startxref', $pdf);
        $this->assertStringNotContainsString('qr_token', $pdf);
        $this->get($this->url().'/export?format=csv&search=ausente')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $empty = $this->get($this->url().'/export?format=pdf&search=ausente')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $empty);
        $this->assertStringContainsString('No se encontraron incidencias', $empty);
    }

    public function test_rendering_failure_returns_json_error_instead_of_a_partial_successful_file(): void
    {
        $this->manual();
        $this->mock(TextReportPdf::class, function ($mock) {
            $mock->shouldReceive('render')->once()->andThrow(new \RuntimeException('Simulated PDF failure'));
        });
        $response = $this->actingAs($this->owner)->getJson($this->url().'/export?format=pdf')->assertStatus(503)
            ->assertJsonPath('code', 'INCIDENT_EXPORT_UNAVAILABLE');
        $this->assertStringNotContainsString('Simulated PDF failure', $response->getContent());
        $this->assertStringNotContainsString('%PDF-', $response->getContent());
    }

    public function test_invalid_filters_formats_pages_and_revoked_admin_are_rejected(): void
    {
        $this->getJson($this->url().'/export?format=csv')->assertUnauthorized();
        $this->getJson('/api/v1/admin/incidents/exams')->assertUnauthorized();
        $this->actingAs($this->owner);
        foreach (['date_from=2026-02-30', 'date_from=2026-11-03&date_to=2026-11-02',
            'type=INVALID', 'search[]=bad', 'per_page=101', 'page=0', 'status[]=OPEN'] as $query) {
            $this->getJson($this->url().'?'.$query)->assertUnprocessable();
        }
        foreach (['', 'format=html', 'format[]=csv', 'format=pdf&page=1', 'format=csv&per_page=10', 'format=pdf&date_to=impossible'] as $query) {
            $this->getJson($this->url().'/export?'.$query)->assertUnprocessable();
        }
        $admin = $this->admin();
        DB::table('role_user')->where('user_id', $admin->id)->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->getJson($this->url())->assertForbidden();
        $this->getJson($this->url().'/export?format=csv')->assertForbidden();
        $this->getJson('/api/v1/admin/incidents/exams')->assertForbidden();
    }

    private function csvRows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+b');
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
