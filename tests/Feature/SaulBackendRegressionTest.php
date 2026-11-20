<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Career;
use App\Models\PortalEventCategory;
use App\Models\Role;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaulBackendRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/La_Paz'));
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        Sanctum::actingAs($this->admin);
    }

    public function test_periods_reject_past_short_equal_extreme_and_distant_dates(): void
    {
        foreach ([['2025-01-01', '2025-06-30'], ['1660-01-01', '1660-12-31'],
            ['2027-01-01', '2027-01-02'], ['2027-01-01', '2027-01-01'],
            ['0001-01-01', '1600-01-01'], ['2027-01-01', '9999-01-01'],
            ['2032-01-01', '2032-06-30'], ['2027-01-01', '2028-01-03']] as [$start, $end]) {
            $this->postJson('/api/v1/admin/academic-terms', [
                'name' => 'Período QA', 'start_date' => $start, 'end_date' => $end, 'status' => 'INACTIVE',
            ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->assertDatabaseCount('academic_terms', 0);
        $this->postJson('/api/v1/admin/academic-terms', [
            'name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'INACTIVE',
        ])->assertCreated();
    }

    public function test_expired_period_cannot_activate_and_expires_on_read_with_audit(): void
    {
        $term = AcademicTerm::create(['name' => 'Histórico', 'start_date' => '2025-01-01', 'end_date' => '2025-06-30', 'status' => 'ACTIVE']);
        $this->getJson('/api/v1/admin/academic-terms/active')->assertOk()->assertJsonPath('data', null);
        $this->assertSame('INACTIVE', $term->fresh()->status);
        $this->patchJson('/api/v1/admin/academic-terms/'.$term->id.'/status', ['status' => 'ACTIVE'])
            ->assertUnprocessable()->assertJsonPath('code', 'ACADEMIC_TERM_EXPIRED');
        $this->getJson('/api/v1/admin/academic-terms')->assertOk()->assertJsonPath('data.0.name', 'Histórico');
        $this->assertSame(1, AuditLog::where('action', 'ACADEMIC_TERM_EXPIRED')->count());
    }

    public function test_period_end_date_remains_active_for_its_entire_local_day(): void
    {
        $term = AcademicTerm::create(['name' => 'Actual', 'start_date' => '2026-07-01', 'end_date' => '2026-11-20', 'status' => 'ACTIVE']);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 23:59:59', 'America/La_Paz'));
        $this->getJson('/api/v1/admin/academic-terms/active')->assertJsonPath('data.id', $term->id);
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/admin/academic-terms/active')->assertJsonPath('data', null);
    }

    public function test_events_reject_today_past_and_excessive_duration_but_accept_tomorrow(): void
    {
        foreach ([['2026-11-19T10:00:00-04:00', '2026-11-19T11:00:00-04:00'],
            ['2026-11-20T23:59:59-04:00', '2026-11-21T01:00:00-04:00'],
            ['0001-12-31T10:00:00-04:00', '9999-12-31T11:00:00-04:00'],
            ['2026-11-21T10:00:00-04:00', '2031-11-22T10:00:00-04:00']] as [$start, $end]) {
            $this->postJson('/api/v1/admin/portal/events', $this->eventPayload($start, $end))->assertUnprocessable();
        }
        $this->assertDatabaseCount('portal_events', 0);
        $this->postJson('/api/v1/admin/portal/events', $this->eventPayload('2026-11-21T04:00:00+00:00', '2026-11-21T05:00:00+00:00'))->assertCreated();
    }

    public function test_historical_event_can_be_consulted_and_edited_without_reprogramming(): void
    {
        $id = $this->postJson('/api/v1/admin/portal/events', $this->eventPayload())->assertCreated()->json('data.id');
        $this->travel(2)->days();
        $this->patchJson('/api/v1/admin/portal/events/'.$id, ['title' => 'Título corregido', 'revision' => 1])->assertOk();
        $this->patchJson('/api/v1/admin/portal/events/'.$id, ['starts_at' => '2026-11-20T09:00:00-04:00', 'revision' => 2])->assertUnprocessable();
        $this->getJson('/api/v1/admin/portal/events/'.$id)->assertOk()->assertJsonPath('data.title', 'Título corregido');
    }

    public function test_room_name_is_case_insensitive_and_code_is_generated_when_omitted(): void
    {
        $id = $this->postJson('/api/v1/admin/rooms', ['name' => 'Aula Prueba', 'capacity' => 30, 'floor' => 2])->assertCreated()->json('data.id');
        $code = Room::findOrFail($id)->code;
        $this->assertStringStartsWith('ROOM-', $code);
        $this->postJson('/api/v1/admin/rooms', ['name' => ' aula prueba ', 'capacity' => 30])->assertUnprocessable()->assertJsonValidationErrors('name');
        $other = $this->postJson('/api/v1/admin/rooms', ['name' => 'Otra aula'])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/admin/rooms/'.$other, ['name' => 'AULA PRUEBA', 'code' => Room::find($other)->code])->assertUnprocessable();
        $this->putJson('/api/v1/admin/rooms/'.$id, ['name' => 'Aula Prueba', 'code' => $code])->assertOk();
    }

    public function test_capacity_floor_and_ground_floor_validation(): void
    {
        foreach (['eeee', 1, 9, 1.5, -1] as $capacity) {
            $this->postJson('/api/v1/admin/rooms', ['name' => 'Aula', 'capacity' => $capacity])->assertUnprocessable()->assertJsonValidationErrors('capacity');
        }
        $this->postJson('/api/v1/admin/rooms', ['name' => 'Aula', 'floor' => 'eeee'])->assertUnprocessable()->assertJsonValidationErrors('floor');
        $this->postJson('/api/v1/admin/rooms', ['name' => 'Aula', 'capacity' => 10, 'is_ground_floor' => true])
            ->assertCreated()->assertJsonPath('data.floor', '0');
    }

    public function test_buildings_are_registered_references_and_inactive_ids_are_rejected(): void
    {
        $building = $this->postJson('/api/v1/admin/rooms/buildings', ['name' => 'Laboratorios'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/rooms/buildings', ['name' => 'laboratorios'])->assertUnprocessable();
        $this->getJson('/api/v1/admin/rooms/buildings')->assertOk()->assertJsonCount(2, 'data');
        $room = $this->postJson('/api/v1/admin/rooms', ['name' => 'Aula', 'building_id' => $building])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('rooms', ['id' => $room, 'building_id' => $building]);
        DB::table('buildings')->where('id', $building)->update(['is_active' => false]);
        $this->postJson('/api/v1/admin/rooms', ['name' => 'Otra aula', 'building_id' => $building])->assertUnprocessable()->assertJsonValidationErrors('building_id');
        $this->getJson('/api/v1/admin/rooms/'.$room)->assertOk()->assertJsonPath('data.building_id', $building);
    }

    public function test_temporary_inactivity_expires_once_and_manual_reactivation_cancels_deadline(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL TIME ZONE 'America/La_Paz'");
        }
        $room = Room::create(['name' => 'Aula', 'code' => 'A', 'status' => 'ACTIVE']);
        $base = '/api/v1/admin/rooms/'.$room->id;
        $this->patchJson($base.'/status', ['status' => 'INACTIVE', 'inactive_until' => '2026-11-20T10:01:00-04:00'])->assertOk();
        $this->travel(59)->seconds();
        $this->getJson($base)->assertJsonPath('data.status', 'INACTIVE');
        $this->travel(1)->seconds();
        $this->getJson($base)->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.inactive_until', null);
        $this->getJson($base)->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'ROOM_AUTOMATICALLY_REACTIVATED')->count());
        $this->patchJson($base.'/status', ['status' => 'INACTIVE', 'inactive_until' => '2026-11-20T11:00:00-04:00'])->assertOk();
        $this->patchJson($base.'/status', ['status' => 'ACTIVE'])->assertOk()->assertJsonPath('data.inactive_until', null);
        $this->patchJson($base.'/status', ['status' => 'INACTIVE', 'inactive_until' => '2026-11-19T10:00:00-04:00'])->assertUnprocessable();
    }

    public function test_bulk_activation_is_atomic_audited_and_rejects_non_admin(): void
    {
        $rooms = collect(['A', 'B'])->map(fn ($code) => Room::create(['code' => $code, 'name' => $code, 'status' => 'INACTIVE']));
        $ids = $rooms->pluck('id')->all();
        $this->patchJson('/api/v1/admin/rooms/bulk-activate', ['room_ids' => [$ids[0], 99999]])->assertUnprocessable();
        $this->assertSame(0, Room::where('status', 'ACTIVE')->count());
        $this->patchJson('/api/v1/admin/rooms/bulk-activate', ['room_ids' => $ids])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, AuditLog::where('action', 'ROOM_ACTIVATED')->count());
        $this->patchJson('/api/v1/admin/rooms/bulk-activate', ['room_ids' => $ids])->assertOk();
        $this->assertSame(2, AuditLog::where('action', 'ROOM_ACTIVATED')->count());
        $this->admin->roles()->detach();
        $this->patchJson('/api/v1/admin/rooms/bulk-activate', ['room_ids' => $ids])->assertForbidden();
    }

    public function test_subject_duplicate_names_are_blocked_for_creation_and_csv(): void
    {
        $career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $this->postJson('/api/v1/admin/subjects', ['code' => 'BD', 'name' => 'Bases de Datos', 'career_ids' => [$career->id]])->assertCreated();
        $this->postJson('/api/v1/admin/subjects', ['code' => 'BD2', 'name' => 'bases de datos', 'career_ids' => [$career->id]])->assertUnprocessable()->assertJsonValidationErrors('name');
        $file = UploadedFile::fake()->createWithContent('materias.csv', "codigo_materia,nombre_materia,codigo_carrera\nBD3,Bases de Datos,SIS\n");
        $this->postJson('/api/v1/admin/subjects/import/preview', ['file' => $file])->assertOk()->assertJsonPath('data.summary.valid', 0);
        $this->assertDatabaseCount('subjects', 1);
    }

    public function test_csv_cannot_bypass_room_name_and_capacity_rules(): void
    {
        $file = UploadedFile::fake()->createWithContent('aulas.csv', "codigo,nombre,descripcion,capacidad,piso\nA,Aula,,20,0\nB,aula,,20,0\nC,Otra,,1,0\n");
        $this->postJson('/api/v1/admin/rooms/import/preview', ['file' => $file])->assertOk()->assertJsonPath('data.valid_rows', 0)->assertJsonPath('data.error_rows', 3);
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_daily_availability_includes_secondary_rooms_and_cancelled_reservations_do_not_occupy(): void
    {
        $primary = Room::create(['name' => 'Principal', 'code' => 'A', 'status' => 'ACTIVE']);
        $extra = Room::create(['name' => 'Secundaria', 'code' => 'B', 'status' => 'ACTIVE']);
        $teacher = Teacher::factory()->create(['user_id' => $this->admin->id]);
        $subject = DB::table('subjects')->insertGetId(['code' => 'SIS', 'name' => 'Software', 'status' => 'ACTIVE']);
        $term = AcademicTerm::create(['name' => 'II/2026', 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'ACTIVE']);
        $offering = DB::table('course_offerings')->insertGetId(['subject_id' => $subject, 'teacher_id' => $teacher->id, 'academic_term_id' => $term->id, 'status' => 'ACTIVE']);
        $exam = DB::table('exams')->insertGetId(['created_by' => $this->admin->id, 'course_offering_id' => $offering, 'room_id' => $primary->id, 'name' => 'Parcial', 'exam_date' => '2026-11-20', 'start_time' => '11:00:00', 'duration_minutes' => 90, 'evaluation_type' => 'PARTIAL', 'status' => 'SCHEDULED']);
        DB::table('exam_rooms')->insert(['exam_id' => $exam, 'room_id' => $extra->id, 'is_primary' => false, 'is_shared' => false, 'shared_with_consent' => false]);
        $this->getJson('/api/v1/admin/rooms?date=2026-11-20')->assertOk()->assertJsonPath('data.1.availability', 'OCCUPIED')->assertJsonPath('data.1.current_exam.id', $exam)->assertJsonPath('data.1.reservation_count', 1);
        $this->getJson('/api/v1/admin/rooms')->assertJsonPath('data.1.availability', 'AVAILABLE');
        $this->travel(1)->hours();
        $this->getJson('/api/v1/admin/rooms')->assertJsonPath('data.1.availability', 'OCCUPIED');
        DB::table('exams')->where('id', $exam)->update(['status' => 'CANCELLED']);
        $this->getJson('/api/v1/admin/rooms?date=2026-11-20')->assertJsonPath('data.1.availability', 'AVAILABLE');
        $this->getJson('/api/v1/admin/rooms?date=2027-01-01')->assertUnprocessable();
    }

    private function eventPayload(string $start = '2026-11-21T09:00:00-04:00', string $end = '2026-11-21T11:00:00-04:00'): array
    {
        return ['title' => 'Evento QA', 'description' => 'Prueba de calendario',
            'category_id' => PortalEventCategory::where('is_active', true)->value('id'), 'starts_at' => $start, 'ends_at' => $end];
    }

    public function test_incident_exam_catalog_filters_offering_period_career_and_type_before_pagination(): void
    {
        $room = Room::create(['name' => 'Aula', 'code' => 'QA', 'status' => 'ACTIVE']);
        $teacher = Teacher::factory()->create(['user_id' => $this->admin->id]);
        $subject = DB::table('subjects')->insertGetId(['code' => 'CS', 'name' => 'Informática', 'status' => 'ACTIVE']);
        $career = Career::create(['code' => 'CS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        DB::table('career_subject')->insert(['career_id' => $career->id, 'subject_id' => $subject]);
        $ids = [];
        foreach (['2025', '2026'] as $year) {
            $term = AcademicTerm::create(['name' => $year, 'start_date' => $year.'-01-01', 'end_date' => $year.'-12-31', 'status' => 'INACTIVE']);
            $offering = DB::table('course_offerings')->insertGetId(['subject_id' => $subject, 'teacher_id' => $teacher->id, 'academic_term_id' => $term->id, 'status' => 'ACTIVE']);
            foreach (['partial', 'final'] as $type) {
                $exam = DB::table('exams')->insertGetId(['created_by' => $this->admin->id, 'course_offering_id' => $offering, 'room_id' => $room->id, 'name' => $type, 'exam_date' => $year.'-11-25', 'start_time' => '11:00:00', 'duration_minutes' => 90, 'evaluation_type' => $type, 'status' => 'SCHEDULED']);
                $ids[$year][$type] = $exam;
            }
        }
        $base = '/api/v1/admin/incidents/exams?career_id='.$career->id.'&subject_id='.$subject.'&academic_term_id='.$term->id.'&evaluation_type=partial&per_page=1';
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ids['2026']['partial'])
            ->assertJsonPath('data.0.academic_term_name', '2026')->assertJsonPath('data.0.course_offering_id', $offering);
        $this->getJson('/api/v1/admin/incidents/exams?course_offering_id='.$offering)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/incidents/exams?academic_term_id=99999')->assertUnprocessable();
        $this->admin->roles()->detach();
        $this->getJson($base)->assertForbidden();
    }

    public function test_room_migration_preserves_existing_fields_and_identifiers(): void
    {
        $migration = require database_path('migrations/2026_11_20_000001_add_room_buildings_and_temporary_inactivity.php');
        $migration->down();
        $id = DB::table('rooms')->insertGetId(['code' => 'LEGACY-01', 'name' => 'Aula histórica', 'floor' => 'PB', 'location' => 'Ubicación original', 'status' => 'INACTIVE']);
        $before = (array) DB::table('rooms')->where('id', $id)->first();
        $migration->up();
        $after = (array) DB::table('rooms')->where('id', $id)->first();
        foreach ($before as $key => $value) {
            $this->assertSame($value, $after[$key]);
        }
        $this->assertNull($after['building_id']);
        $this->assertNull($after['inactive_until']);
        $this->assertSame(1, DB::table('buildings')->where('is_active', true)->count());
    }

    public function test_bulk_activation_rolls_back_all_rooms_when_second_audit_fails(): void
    {
        $ids = collect(['A', 'B'])->map(fn ($code) => Room::create(['code' => $code, 'name' => $code, 'status' => 'INACTIVE'])->id)->all();
        $calls = 0;
        $original = new AuditLogService;
        $this->mock(AuditLogService::class)->shouldReceive('log')->andReturnUsing(function (...$args) use (&$calls, $original) {
            if (++$calls === 2) {
                throw new \RuntimeException('Simulated audit failure');
            }

            return $original->log(...$args);
        });
        $this->patchJson('/api/v1/admin/rooms/bulk-activate', ['room_ids' => $ids])->assertStatus(500);
        $this->assertSame(2, Room::where('status', 'INACTIVE')->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
