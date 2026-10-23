<?php

namespace Tests\Feature\Api\V1\Enrollments;

use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class LateEnrollmentEligibilityTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        Sanctum::actingAs($this->fixture['user']);
    }

    private function base(): string
    {
        return '/api/v1/course-offerings/'.$this->fixture['offering']->id;
    }

    private function exam(array $attributes = []): Exam
    {
        return Exam::create($attributes + [
            'course_offering_id' => $this->fixture['offering']->id,
            'room_id' => $this->fixture['room']->id,
            'created_by' => $this->fixture['user']->id,
            'name' => 'Examen previo a inscripción', 'evaluation_type' => 'partial', 'rules' => 'Reglas',
            'exam_date' => '2026-10-20', 'start_time' => '10:00:00', 'duration_minutes' => 60,
            'status' => 'SCHEDULED',
        ]);
    }

    private function manual()
    {
        return $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001']);
    }

    private function csv(bool $preview = false, string $content = "sisCode\n202600001\n")
    {
        return $this->postJson($this->base().'/enrollments/bulk'.($preview ? '/preview' : ''), [
            'file' => UploadedFile::fake()->createWithContent('inscripciones.csv', $content),
        ]);
    }

    public static function examStates(): array
    {
        return [
            'programado' => ['SCHEDULED', '10:00:00', true],
            'en curso' => ['IN_PROGRESS', '08:30:00', true],
            'legado activo' => ['ACTIVE', '10:00:00', true],
            'finalizado' => ['FINISHED', '10:00:00', false],
            'completado' => ['COMPLETED', '10:00:00', false],
            'cancelado' => ['CANCELLED', '10:00:00', false],
            'vencido' => ['SCHEDULED', '07:00:00', false],
            'fin exacto La Paz' => ['IN_PROGRESS', '08:00:00', false],
        ];
    }

    #[DataProvider('examStates')]
    public function test_manual_initializes_only_available_exams(string $status, string $time, bool $expected): void
    {
        $exam = $this->exam(['status' => $status, 'start_time' => $time]);
        $this->manual()->assertCreated();
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->fixture['studentId'], 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('exam_eligibilities', $expected ? 1 : 0);
        if ($expected) {
            $this->assertDatabaseHas('exam_eligibilities', [
                'exam_id' => $exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE',
                'reason' => null, 'evaluated_by' => $this->fixture['user']->id,
                'revision' => 1,
            ]);
        }
    }

    public function test_preview_then_partial_csv_confirm_and_retry_keep_hu07_contract(): void
    {
        $exam = $this->exam();
        $content = "sisCode\n202600001\n202600001\n999999999\n\n";
        $this->csv(true, $content)->assertOk()->assertJsonPath('data.totalProcessed', 4)
            ->assertJsonPath('data.successfulRecords', 1)->assertJsonPath('data.duplicateRecords', 1)
            ->assertJsonPath('data.failedCount', 2)->assertJsonPath('data.records.3.row', 5);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'Enrollment')->count());

        $this->csv(false, $content)->assertOk()->assertJsonPath('data.totalProcessed', 4)
            ->assertJsonPath('data.successfulRecords', 1)->assertJsonPath('data.duplicateRecords', 1)
            ->assertJsonPath('data.failedCount', 2)->assertJsonPath('data.records.0.status', 'ADDED')
            ->assertJsonPath('data.records.3.row', 5);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('exam_eligibilities', 1);
        $before = DB::table('exam_eligibilities')->first();
        $this->csv()->assertOk()->assertJsonPath('data.successfulRecords', 0)->assertJsonPath('data.duplicateRecords', 1);
        $this->manual()->assertUnprocessable();
        $this->assertEquals($before, DB::table('exam_eligibilities')->where('exam_id', $exam->id)->first());
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', 'Enrollment')->count());
    }

    public static function existingDecisions(): array
    {
        return [['ELIGIBLE'], ['INELIGIBLE']];
    }

    #[DataProvider('existingDecisions')]
    public function test_existing_decision_and_all_metadata_remain_identical(string $status): void
    {
        $exam = $this->exam();
        $decision = ExamEligibility::create([
            'exam_id' => $exam->id, 'student_id' => $this->fixture['studentId'], 'status' => $status,
            'reason_code' => $status === 'INELIGIBLE' ? 'OTHER' : null,
            'reason' => $status === 'INELIGIBLE' ? 'Decisión manual' : null,
            'observations' => 'Conservar observaciones', 'evaluated_by' => $this->fixture['user']->id,
            'evaluated_at' => now()->subDay(),
        ]);
        $decision->forceFill(['revision' => 7])->save();
        $before = $decision->fresh()->getRawOriginal();
        $this->manual()->assertCreated();
        $this->assertSame($before, $decision->fresh()->getRawOriginal());
        $this->assertDatabaseCount('exam_eligibilities', 1);
    }

    public function test_inactive_student_does_not_receive_eligibilities(): void
    {
        $this->exam();
        DB::table('students')->where('id', $this->fixture['studentId'])->update(['status' => 'INACTIVE']);
        $this->manual()->assertCreated(); // Preserve the existing enrollment response.
        $this->assertDatabaseCount('exam_eligibilities', 0);
    }

    public function test_duplicates_do_not_reactivate_or_backfill_historical_enrollments(): void
    {
        $this->exam();
        DB::table('enrollments')->insert([
            'course_offering_id' => $this->fixture['offering']->id, 'student_id' => $this->fixture['studentId'],
            'status' => 'INACTIVE', 'registered_by' => $this->fixture['user']->id,
        ]);
        $this->manual()->assertUnprocessable();
        $this->csv()->assertOk()->assertJsonPath('data.duplicateRecords', 1);
        $this->assertDatabaseHas('enrollments', ['student_id' => $this->fixture['studentId'], 'status' => 'INACTIVE']);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        DB::table('enrollments')->update(['status' => 'ACTIVE']);
        $this->manual()->assertUnprocessable();
        $this->assertDatabaseCount('exam_eligibilities', 0);
    }

    public function test_initializes_only_new_student_and_same_offering(): void
    {
        $exam = $this->exam();
        $subject = $this->fixture['subject']->replicate();
        $subject->code = 'OTRO';
        $subject->save();
        $other = $this->fixture['offering']->replicate();
        $other->subject_id = $subject->id;
        $other->save();
        $this->exam(['course_offering_id' => $other->id]);
        $otherStudent = DB::table('students')->insertGetId([
            'user_id' => User::factory()->create()->id, 'sis_code' => '202600002', 'identity_number' => '70000002',
            'first_names' => 'Otro', 'last_names' => 'Alumno', 'status' => 'ACTIVE',
            'career_id' => DB::table('students')->where('id', $this->fixture['studentId'])->value('career_id'),
        ]);
        DB::table('enrollments')->insert([
            'student_id' => $otherStudent, 'course_offering_id' => $this->fixture['offering']->id,
            'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id,
        ]);
        $this->manual()->assertCreated();
        $this->assertDatabaseCount('exam_eligibilities', 1);
        $this->assertDatabaseHas('exam_eligibilities', ['exam_id' => $exam->id, 'student_id' => $this->fixture['studentId']]);
    }

    public static function rollbackCases(): array
    {
        return [[false, 'exam_eligibilities'], [true, 'exam_eligibilities'], [false, 'audit_logs'], [true, 'audit_logs']];
    }

    #[DataProvider('rollbackCases')]
    public function test_initialization_and_hu07_audit_failures_rollback_all_writes(bool $csv, string $table): void
    {
        $this->exam();
        // This trigger exists only on the verified in-memory SQLite test connection.
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::unprepared("CREATE TRIGGER fail_late_enrollment BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
        try {
            ($csv ? $this->csv() : $this->manual())->assertStatus(500);
            $this->assertDatabaseCount('enrollments', 0);
            $this->assertDatabaseCount('exam_eligibilities', 0);
            $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'Enrollment')->count());
        } finally {
            DB::unprepared('DROP TRIGGER fail_late_enrollment');
        }
    }

    public function test_new_eligibility_supports_hu12_hu13_manual_and_qr_without_bypassing_manual_decisions(): void
    {
        $exam = $this->exam();
        $studentId = $this->fixture['studentId'];
        $this->manual()->assertCreated();
        $eligibilities = '/api/v1/exams/'.$exam->id.'/eligibilities';
        $entry = '/api/v1/exams/'.$exam->id.'/entry-control';
        $this->getJson($eligibilities)->assertOk()->assertJsonPath('data.0.student_id', $studentId)
            ->assertJsonPath('data.0.status', 'ELIGIBLE');
        $this->travelTo(CarbonImmutable::parse($exam->exam_date.' '.$exam->start_time, 'America/La_Paz'));
        $this->getJson($entry.'/students/'.$studentId)->assertOk()->assertJsonPath('data.meets_entry_conditions', true);

        $studentUser = User::findOrFail(DB::table('students')->where('id', $studentId)->value('user_id'));
        $studentUser->update(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ESTUDIANTE'], ['status' => 'ACTIVE']);
        $studentUser->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        Sanctum::actingAs($studentUser);
        $this->getJson('/api/v1/students/exams')->assertOk()->assertJsonPath('data.0.qr_status', 'AVAILABLE');
        $token = $this->getJson('/api/v1/students/exams/'.$exam->id.'/qr')->assertOk()->json('data.token');
        $this->assertNotEmpty($token);

        Sanctum::actingAs($this->fixture['user']);
        $this->patchJson($eligibilities.'/'.$studentId, ['status' => 'INELIGIBLE', 'reason_code' => 'OTHER', 'observations' => 'Manual', 'revision' => 1])
            ->assertOk();
        $this->postJson($entry.'/students/'.$studentId.'/entries', ['room_id' => $exam->room_id])
            ->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'INELIGIBLE');
        $this->patchJson($eligibilities.'/'.$studentId, ['status' => 'ELIGIBLE', 'revision' => 2])->assertOk();
        $this->postJson($entry.'/students/'.$studentId.'/entries', ['room_id' => $exam->room_id])
            ->assertCreated()->assertJsonPath('data.entry.verification_method', 'MANUAL');
        $this->postJson($entry.'/qr', ['qr_token' => $token, 'room_id' => $exam->room_id])
            ->assertConflict()->assertJsonPath('result', 'ALREADY_REGISTERED');
    }
}
