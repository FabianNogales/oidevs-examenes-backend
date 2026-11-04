<?php

namespace Tests\Feature\Reports;

use App\Models\Exam;
use App\Models\Expulsion;
use App\Models\Infraction;
use App\Models\Role;
use App\Models\SpecialCase;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Reports\ExamReportSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class ExamReportTest extends TestCase
{
    use EntryAnnulmentFixtures { setUp as private fixtureSetUp; }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        config(['exam_reports.cache_store' => 'array']);
    }

    private function reportPath(string $type = 'ENROLLED'): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/reports/'.$type;
    }

    private function report(string $type = 'ENROLLED', array $filters = [])
    {
        return $this->actingAs($this->owner)->getJson($this->reportPath($type).'?'.http_build_query($filters));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $admin;
    }

    private function extraStudent(string $status = 'ACTIVE'): Student
    {
        $student = $this->student->replicate();
        $student->user_id = User::factory()->create()->id;
        $student->sis_code = 'SIS-'.(Student::max('id') + 1);
        $student->identity_number = $student->sis_code;
        $student->save();
        DB::table('enrollments')->insert(['student_id' => $student->id, 'course_offering_id' => $this->exam->course_offering_id,
            'registered_by' => $this->owner->id, 'status' => $status]);

        return $student;
    }

    private function foreignExam(): Exam
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::create(['user_id' => $user->id, 'institutional_code' => 'HU33-OTHER', 'identity_number' => 'HU33-OTHER',
            'first_names' => 'Otro', 'last_names' => 'Docente', 'status' => 'ACTIVE']);
        $subject = DB::table('subjects')->insertGetId(['code' => 'PRIVATE', 'name' => 'Materia privada', 'status' => 'ACTIVE']);
        $offering = $this->exam->courseOffering->replicate();
        $offering->teacher_id = $teacher->id;
        $offering->subject_id = $subject;
        $offering->save();
        $exam = $this->exam->replicate();
        $exam->name = 'Examen ajeno';
        $exam->course_offering_id = $offering->id;
        $exam->save();

        return $exam->fresh('courseOffering');
    }

    public function test_catalog_is_scoped_with_authorized_filter_options_and_admin_policy(): void
    {
        $foreign = $this->foreignExam();
        $url = '/api/v1/exam-reports/catalog';
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $this->exam->id)->assertJsonPath('data.0.subject.code', 'INC')
            ->assertJsonCount(5, 'data.0.permissions.report_types')->assertJsonPath('data.0.permissions.formats', ['pdf', 'csv'])
            ->assertJsonCount(1, 'filters.subjects')->assertJsonMissing(['name' => 'Materia privada']);
        $this->getJson($url.'?exam_id='.$foreign->id)->assertForbidden();
        $this->getJson($url.'?subject_id='.$foreign->courseOffering->subject_id)->assertForbidden();
        $this->getJson($url.'?search=sin-coincidencias')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
        $this->actingAs($this->admin())->getJson($url)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'filters.subjects');
        $this->getJson('/api/v1/exams/'.$foreign->id.'/reports/ENROLLED')->assertOk();
    }

    public function test_active_roster_and_full_metadata_preserve_ids_strings_and_context(): void
    {
        $this->student->update(['sis_code' => '00123', 'identity_number' => '00099']);
        $this->extraStudent('INACTIVE');
        $response = $this->report()->assertOk()->assertJsonPath('report.total_participants', 1)->assertJsonPath('report.total_records', 1)
            ->assertJsonPath('report.scope', 'EXAM')->assertJsonPath('report.source', 'SERVER')->assertJsonPath('report.offline_pending_included', false)
            ->assertJsonPath('report.roster_scope', 'ACTIVE_ENROLLMENTS')->assertJsonPath('report.pending_classification', null)
            ->assertJsonPath('report.filters.date_from', null)->assertJsonPath('report.filters.date_to', null)
            ->assertJsonPath('data.0.record_type', 'ENROLLMENT')->assertJsonPath('data.0.state', 'ENROLLED')
            ->assertJsonPath('data.0.student.sis_code', '00123')->assertJsonPath('data.0.student.identity_number', '00099')
            ->assertJsonPath('data.0.recorded_by', null)->assertJsonPath('data.0.occurred_at', null);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,128}$/', $response->json('report.id'));
        $this->assertSame(now()->toISOString(), $response->json('report.generated_at'));
    }

    public function test_pages_and_exports_reuse_immutable_snapshot_while_new_generation_sees_changes(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->extraStudent();
        }
        $first = $this->report()->assertOk()->assertJsonPath('report.total_records', 13)->assertJsonCount(10, 'data')->json();
        $this->extraStudent();
        $this->travel(2)->minutes();
        $second = $this->report('ENROLLED', ['report_id' => $first['report']['id'], 'page' => 2])->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('report', $first['report'])->json();
        $this->assertSame([], array_intersect(array_column($first['data'], 'source_id'), array_column($second['data'], 'source_id')));
        $csv = $this->get($this->reportPath().'/export?'.http_build_query(['format' => 'csv', 'report_id' => $first['report']['id']]))
            ->assertOk()->assertHeader('X-Report-Id', $first['report']['id'])->assertHeader('X-Report-Generated-At', $first['report']['generated_at']);
        $rows = $this->csvRows($csv->getContent());
        $this->assertCount(17, $rows); // Metadata header/value, blank, column header and 13 records.
        $this->report()->assertOk()->assertJsonPath('report.total_records', 14);
    }

    public function test_attendance_snapshot_survives_annulment_and_new_snapshot_excludes_annulled_or_offline(): void
    {
        $entry = $this->initialEntry();
        $first = $this->report('ATTENDANCE')->assertOk()->assertJsonPath('data.0.record_type', 'ENTRY')
            ->assertJsonPath('data.0.state', 'VALID')->assertJsonPath('data.0.method', 'QR')
            ->assertJsonPath('data.0.recorded_by.id', $this->owner->id)->json();
        $this->postJson($this->entryBase().'/entries/'.$entry.'/annulment', ['reason' => 'Corregir ingreso.'])->assertOk();
        $this->report('ATTENDANCE', ['report_id' => $first['report']['id']])->assertOk()->assertJsonPath('data', $first['data'])
            ->assertJsonPath('report', $first['report']);
        $this->report('ATTENDANCE')->assertOk()->assertJsonPath('report.total_records', 0)->assertJsonPath('data', []);
        DB::table('exam_entries')->where('id', $entry)->update(['status' => 'PENDING_SYNC']);
        $this->report('ATTENDANCE')->assertOk()->assertJsonPath('report.total_records', 0);
        DB::table('exam_entries')->where('id', $entry)->update(['status' => 'VALID', 'verification_method' => 'MANUAL']);
        $this->report('ATTENDANCE')->assertOk()->assertJsonPath('data.0.method', 'MANUAL');
        DB::table('enrollments')->update(['status' => 'INACTIVE']);
        $this->report('ATTENDANCE')->assertOk()->assertJsonPath('report.total_participants', 0)->assertJsonPath('report.total_records', 0);
        $this->assertDatabaseHas('exam_entries', ['id' => $entry, 'status' => 'VALID']);
    }

    public function test_pending_absent_uses_server_clock_full_end_time_and_cancelled_classification(): void
    {
        config(['exam_reports.snapshot_ttl_minutes' => 180]);
        $initial = $this->report('PENDING_ABSENT')->assertOk()->assertJsonPath('report.pending_classification', 'PENDING')
            ->assertJsonPath('data.0.record_type', 'PENDING')->json();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:30:00', 'America/La_Paz'));
        $this->report('PENDING_ABSENT')->assertOk()->assertJsonPath('report.pending_classification', 'ABSENT')
            ->assertJsonPath('data.0.record_type', 'ABSENT')->assertJsonPath('data.0.occurred_at', null);
        $this->report('PENDING_ABSENT', ['report_id' => $initial['report']['id']])->assertOk()->assertJsonPath('report', $initial['report']);
        $this->exam->update(['status' => 'CANCELLED']);
        $this->report('PENDING_ABSENT')->assertOk()->assertJsonPath('report.pending_classification', 'NOT_APPLICABLE')
            ->assertJsonPath('report.total_records', 0)->assertJsonPath('data', []);
        $this->exam->update(['status' => 'SCHEDULED', 'start_time' => '23:30:00', 'duration_minutes' => 120]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 00:30:00', 'America/La_Paz'));
        $this->report('PENDING_ABSENT')->assertOk()->assertJsonPath('report.pending_classification', 'PENDING');
    }

    public function test_roster_dates_use_exam_day_and_event_dates_use_inclusive_bolivian_days(): void
    {
        $this->report('ENROLLED', ['date_from' => '2026-11-02'])->assertOk()->assertJsonPath('report.total_records', 0);
        foreach (['2026-11-02 03:59:59', '2026-11-02 04:00:00', '2026-11-03 03:59:59', '2026-11-03 04:00:00'] as $time) {
            $student = $this->extraStudent();
            DB::table('exam_entries')->insert(['exam_id' => $this->exam->id, 'student_id' => $student->id, 'room_id' => $this->exam->room_id,
                'verified_by' => $this->owner->id, 'verification_method' => 'MANUAL', 'entered_at' => $time, 'status' => 'VALID']);
        }
        $this->report('ATTENDANCE', ['date_from' => '2026-11-02', 'date_to' => '2026-11-02'])->assertOk()
            ->assertJsonPath('report.total_records', 2)->assertJsonPath('data.0.occurred_at', '2026-11-03T03:59:59.000000Z')
            ->assertJsonPath('data.1.occurred_at', '2026-11-02T04:00:00.000000Z');
    }

    public function test_incidents_reuse_manual_and_audit_evidence_with_disjoint_ids_and_optional_student(): void
    {
        $manual = SpecialCase::create(['exam_id' => $this->exam->id, 'student_id' => null, 'type' => 'ENVIRONMENT',
            'description' => 'Problema del ambiente.', 'reported_by' => $this->owner->id, 'status' => 'PENDING']);
        $audit = DB::table('audit_logs')->insertGetId(['user_id' => $this->owner->id, 'action' => 'ENTRY_QR_WRONG_EXAM',
            'entity_type' => 'Exam', 'entity_id' => $this->exam->id, 'created_at' => now(),
            'new_values' => json_encode(['student_id' => $this->student->id, 'qr_exam_id' => 999, 'reason' => 'WRONG_EXAM'])]);
        $before = DB::table('audit_logs')->count();
        $response = $this->report('INCIDENTS')->assertOk()->assertJsonPath('report.total_records', 2)->json();
        $rows = collect($response['data']);
        $this->assertSame('WRONG_EXAM', $rows->firstWhere('origin', 'AUTOMATIC')['category']);
        $this->assertNull($rows->firstWhere('origin', 'MANUAL')['student']);
        $this->assertSame('PENDING', $rows->firstWhere('origin', 'MANUAL')['state']);
        $this->assertCount(2, array_unique($rows->pluck('source_id')->all()));
        $this->report('INCIDENTS', ['report_id' => $response['report']['id']])->assertOk();
        $this->assertDatabaseCount('special_cases', 1);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_disciplinary_rows_keep_reported_state_and_expulsion_link_without_merging_ids(): void
    {
        $infraction = Infraction::create(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'type' => 'COPYING',
            'description' => 'Reporte por revisar.', 'reported_by' => $this->owner->id, 'occurred_at' => now(), 'status' => 'REPORTED']);
        Expulsion::create(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'infraction_id' => $infraction->id,
            'reason' => 'Medida justificada.', 'expelled_by' => $this->owner->id, 'expelled_at' => now()]);
        $rows = collect($this->report('DISCIPLINARY')->assertOk()->assertJsonPath('report.total_records', 2)->json('data'));
        $this->assertSame('REPORTED', $rows->firstWhere('record_type', 'INFRACTION')['state']);
        $this->assertSame('EXPELLED', $rows->firstWhere('record_type', 'EXPULSION')['state']);
        $this->assertSame($infraction->id, $rows->firstWhere('record_type', 'EXPULSION')['related_infraction_id']);
        $this->assertCount(2, $rows->map(fn ($row) => $row['record_type'].':'.$row['source_id'])->unique());
    }

    public function test_snapshots_are_bound_to_actor_type_exam_and_filters_and_expire_with_410(): void
    {
        $first = $this->report('ENROLLED', ['date_from' => '2026-11-01'])->assertOk()->json();
        $id = $first['report']['id'];
        $this->report('ENROLLED', ['report_id' => $id, 'date_from' => '2026-11-02'])->assertUnprocessable();
        $this->report('ATTENDANCE', ['report_id' => $id])->assertForbidden();
        $this->actingAs($this->admin())->getJson($this->reportPath().'?report_id='.$id)->assertForbidden();
        $this->actingAs($this->owner);
        $this->travel(31)->minutes();
        $this->getJson($this->reportPath().'?report_id='.$id)->assertStatus(410);
        $this->getJson($this->reportPath().'/export?report_id='.$id.'&format=pdf')->assertStatus(410);
        $this->getJson($this->reportPath().'?report_id=not-found')->assertStatus(410);
    }

    public function test_current_permissions_are_revalidated_before_download_and_foreign_scope_is_rejected(): void
    {
        $snapshot = $this->report()->assertOk()->json();
        $foreign = $this->foreignExam();
        $this->getJson('/api/v1/exams/'.$foreign->id.'/reports/ENROLLED')->assertForbidden();
        $this->report('ENROLLED', ['subject_id' => $foreign->courseOffering->subject_id])->assertUnprocessable();
        DB::table('role_user')->where('user_id', $this->owner->id)->update(['status' => 'INACTIVE']);
        $this->getJson($this->reportPath().'/export?report_id='.$snapshot['report']['id'].'&format=csv')->assertForbidden();
        $this->getJson($this->reportPath().'?report_id='.$snapshot['report']['id'])->assertForbidden();
        $this->actingAs($this->collaborator())->getJson('/api/v1/exam-reports/catalog')->assertForbidden();
    }

    public function test_pdf_and_csv_have_matching_snapshot_headers_metadata_and_formula_safe_cells(): void
    {
        $this->student->update(['first_names' => 'María', 'last_names' => 'Ñúñez', 'sis_code' => '00123', 'identity_number' => '00099']);
        SpecialCase::create(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'type' => 'IDENTIFICATION',
            'description' => " \t=HYPERLINK(\"bad\")\nLínea con coma, revisión.", 'reported_by' => $this->owner->id, 'status' => 'PENDING']);
        $snapshot = $this->report('INCIDENTS')->assertOk()->json();
        foreach (['csv', 'pdf'] as $format) {
            $response = $this->get($this->reportPath('INCIDENTS').'/export?'.http_build_query(['report_id' => $snapshot['report']['id'], 'format' => $format]),
                ['Origin' => 'http://127.0.0.1:5173'])->assertOk()
                ->assertHeader('X-Report-Id', $snapshot['report']['id'])->assertHeader('X-Report-Generated-At', $snapshot['report']['generated_at']);
            $this->assertStringContainsString('X-Report-Id', $response->headers->get('Access-Control-Expose-Headers'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            if ($format === 'pdf') {
                $response->assertHeader('Content-Type', 'application/pdf');
                $this->assertStringStartsWith('%PDF-', $response->getContent());
                $this->assertStringContainsString($snapshot['report']['id'], $response->getContent());
                $this->assertStringNotContainsString('Filtros:', $response->getContent());
                $this->assertStringNotContainsString('"date_from":', $response->getContent());
            } else {
                $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
                $rows = $this->csvRows($response->getContent());
                $this->assertNotContains('Filtros', $rows[0]);
                $this->assertCount(11, $rows[0]);
                $this->assertCount(11, $rows[1]);
                $this->assertStringNotContainsString('"date_from":', $response->getContent());
                $this->assertSame('00123', $rows[4][6]);
                $this->assertSame('00099', $rows[4][7]);
                $this->assertStringStartsWith("'", $rows[4][10]);
            }
        }
    }

    public function test_invalid_filters_unknown_types_and_failures_do_not_produce_false_reports(): void
    {
        $this->getJson('/api/v1/exam-reports/catalog')->assertUnauthorized();
        $this->report('INVALID')->assertUnprocessable();
        foreach (['date_from=2026-02-30', 'date_from=2026-11-03&date_to=2026-11-02', 'page=2', 'page=0', 'per_page=101',
            'report_id[]=bad', 'subject_id[]=bad', 'offline_pending=10'] as $query) {
            $this->getJson($this->reportPath().'?'.$query)->assertUnprocessable();
        }
        $this->getJson($this->reportPath().'/export?format=pdf')->assertUnprocessable();
        $this->getJson($this->reportPath().'/export?format=html&report_id=anything')->assertUnprocessable();
        $this->mock(ExamReportSnapshots::class, function ($mock) {
            $mock->shouldReceive('put')->once()->andThrow(new \RuntimeException('Simulated cache failure'));
        });
        $response = $this->report()->assertStatus(503)->assertJsonPath('code', 'EXAM_REPORT_UNAVAILABLE');
        $this->assertStringNotContainsString('Simulated cache failure', $response->getContent());
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
