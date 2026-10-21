<?php

namespace Tests\Feature\Api\V1\Enrollments;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class AcademicAuditContextTest extends TestCase
{
    use RefreshDatabase, CourseOfferingFixture;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->fixture['user']->roles()->detach(DB::table('roles')->where('name', 'ADMINISTRADOR')->value('id'));
        Sanctum::actingAs($this->fixture['user']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->withHeader('User-Agent', 'FIX-025A regression');
    }

    private function base(): string
    {
        return '/api/v1/course-offerings/'.$this->fixture['offering']->id;
    }

    private function manual(array $extra = [])
    {
        return $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '202600001'] + $extra);
    }

    private function csv(string $rows, bool $preview = false)
    {
        return $this->postJson($this->base().'/enrollments/bulk'.($preview ? '/preview' : ''), [
            'file' => UploadedFile::fake()->createWithContent('inscripciones.csv', "sisCode\n".$rows),
        ]);
    }

    private function write(string $entity): AuditLog
    {
        return AuditLog::where('action', 'WRITE')->where('entity_type', $entity)->sole();
    }

    private function assertActorAndRequest(AuditLog $audit): void
    {
        $this->assertSame($this->fixture['user']->id, $audit->user_id);
        $this->assertSame('203.0.113.20', $audit->ip_address);
        $this->assertSame('FIX-025A regression', $audit->user_agent);
        $this->assertTrue($audit->created_at->equalTo(now()));
        $this->assertNull($audit->old_values);
    }

    public function test_manual_event_uses_real_enrollment_actor_and_only_internal_identifiers(): void
    {
        $spoofedActor = User::factory()->create();
        $otherStudent = DB::table('students')->insertGetId([
            'user_id' => $spoofedActor->id, 'sis_code' => '202600002', 'identity_number' => '70000002',
            'first_names' => 'Otro', 'last_names' => 'Alumno', 'status' => 'ACTIVE',
            'career_id' => DB::table('students')->where('id', $this->fixture['studentId'])->value('career_id'),
        ]);
        DB::table('enrollments')->insert([
            'course_offering_id' => $this->fixture['offering']->id, 'student_id' => $otherStudent,
            'registered_by' => $this->fixture['user']->id, 'status' => 'ACTIVE',
        ]);
        $this->manual([
            'user_id' => $spoofedActor->id, 'entity_id' => 999999, 'mode' => 'CSV',
            'password' => 'NeverAuditPassword', 'token' => 'NeverAuditToken',
            'first_names' => 'NeverAuditName', 'identity_number' => 'NeverAuditCI', 'email' => 'never-audit@example.test',
        ])->assertCreated()->assertJsonPath('message', 'Student enrolled successfully.')
            ->assertJsonPath('data.id', $this->fixture['studentId']);
        $audit = $this->write('Enrollment');
        $this->assertActorAndRequest($audit);
        $enrollmentId = DB::table('enrollments')->where('student_id', $this->fixture['studentId'])->sole()->id;
        $this->assertEquals($enrollmentId, $audit->entity_id);
        $this->assertNotEquals($this->fixture['studentId'], $audit->entity_id);
        $this->assertNotEquals($this->fixture['offering']->id, $audit->entity_id);
        $this->assertEquals([
            'source' => 'APPLICATION', 'operation' => 'ENROLLMENT_CREATED', 'outcome' => 'CONFIRMED',
            'scope' => 'ENROLLMENT', 'mode' => 'MANUAL', 'course_offering_id' => $this->fixture['offering']->id,
            'student_ids' => [$this->fixture['studentId']],
            'summary' => ['totalProcessed' => 1, 'successfulRecords' => 1, 'duplicateRecords' => 0, 'failedCount' => 0],
        ], $audit->new_values);
        $serialized = json_encode($audit->toArray());
        foreach (['202600001', '70000001', 'NeverAudit', 'never-audit@example.test', $this->fixture['user']->password] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $serialized);
        }
        $this->manual()->assertUnprocessable();
        $this->assertSame(1, AuditLog::where('action', 'WRITE')->where('entity_type', 'Enrollment')->count());
    }

    public function test_partial_csv_audits_only_confirmed_students_and_exact_counts_including_blank_rows(): void
    {
        $response = $this->csv("202600001\n202600001\n999999999\n\n")->assertOk()
            ->assertJsonPath('data.totalProcessed', 4)->assertJsonPath('data.successfulRecords', 1)
            ->assertJsonPath('data.duplicateRecords', 1)->assertJsonPath('data.failedCount', 2);
        $audit = $this->write('Enrollment');
        $this->assertActorAndRequest($audit);
        $this->assertNull($audit->entity_id, 'A batch must not impersonate an individual enrollment.');
        $this->assertEquals([
            'source' => 'APPLICATION', 'operation' => 'ENROLLMENTS_IMPORTED', 'outcome' => 'CONFIRMED',
            'scope' => 'COURSE_OFFERING', 'mode' => 'CSV', 'course_offering_id' => $this->fixture['offering']->id,
            'student_ids' => [$this->fixture['studentId']],
            'summary' => array_intersect_key($response->json('data'), array_flip([
                'totalProcessed', 'successfulRecords', 'duplicateRecords', 'failedCount',
            ])),
        ], $audit->new_values);
        $summary = $audit->new_values['summary'];
        $this->assertSame($summary['totalProcessed'], $summary['successfulRecords'] + $summary['duplicateRecords'] + $summary['failedCount']);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertStringNotContainsString('202600001', json_encode($audit->new_values));
        $this->assertStringNotContainsString('999999999', json_encode($audit->new_values));
    }

    public function test_preview_and_confirm_without_new_enrollments_do_not_claim_success(): void
    {
        $this->csv("202600001\n", true)->assertOk()->assertJsonPath('data.successfulRecords', 1);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->csv("999999999\n\n")->assertOk()->assertJsonPath('data.successfulRecords', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->manual()->assertCreated();
        $before = $this->write('Enrollment')->getRawOriginal();
        $this->csv("202600001\n")->assertOk()->assertJsonPath('data.duplicateRecords', 1);
        $this->assertSame($before, $this->write('Enrollment')->getRawOriginal());
    }

    public function test_scheduling_keeps_one_application_event_and_one_trigger_record_for_the_same_exam(): void
    {
        $spoofedActor = User::factory()->create();
        Exam::create(array_replace($this->examPayload($this->fixture['room']->id), [
            'exam_date' => now()->addDays(10)->toDateString(),
            'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED',
        ]));
        $baselineCount = AuditLog::count();
        $payload = $this->examPayload($this->fixture['room']->id);
        $payload['name'] = 'Private free-text name';
        $payload['rules'] = 'Private free-text rules';
        $response = $this->postJson($this->base().'/exams', $payload + [
            'created_by' => $spoofedActor->id, 'user_id' => $spoofedActor->id, 'token' => 'NeverAuditToken',
        ])->assertCreated()->assertJsonPath('message', 'Exam scheduled successfully.');
        $examId = $response->json('data.id');
        $audit = $this->write('Exam');
        $this->assertActorAndRequest($audit);
        $this->assertEquals($examId, $audit->entity_id);
        $this->assertNotEquals($this->fixture['offering']->id, $audit->entity_id);
        $this->assertEquals([
            'source' => 'APPLICATION', 'operation' => 'EXAM_SCHEDULED', 'outcome' => 'CONFIRMED',
            'course_offering_id' => $this->fixture['offering']->id, 'room_id' => $payload['room_id'],
            'exam_date' => $payload['exam_date'], 'start_time' => $payload['start_time'],
            'duration_minutes' => $payload['duration_minutes'], 'evaluation_type' => $payload['evaluation_type'], 'status' => 'SCHEDULED',
        ], $audit->new_values);
        $this->assertSame($this->fixture['user']->id, Exam::findOrFail($examId)->created_by);
        foreach (['Private free-text', 'NeverAuditToken', $this->fixture['user']->email, $this->fixture['user']->password] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, json_encode($audit->toArray()));
        }
        $trigger = AuditLog::where('action', 'CREATE')->where('entity_type', 'Exam')->where('entity_id', $examId)->sole();
        $this->assertEquals($examId, $trigger->entity_id);
        $this->assertNull($trigger->user_id);
        $this->assertDatabaseCount('audit_logs', $baselineCount + 2);
        $this->assertSame(2, AuditLog::where('entity_type', 'Exam')->where('entity_id', $examId)->count());
    }

    public static function operations(): array
    {
        return [['manual'], ['csv'], ['exam']];
    }

    #[DataProvider('operations')]
    public function test_audit_failure_rolls_back_operation_and_all_transactional_events(string $operation): void
    {
        if ($operation !== 'exam') {
            Exam::create($this->examPayload($this->fixture['room']->id) + [
                'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED',
            ]);
        }
        $baseline = DB::table('audit_logs')->orderBy('id')->get()->toJson();
        // Fail after inserting the application event to prove it cannot survive rollback.
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->andReturnUsing(function (...$arguments) {
                (new AuditLogService)->log(...$arguments);
                throw new \RuntimeException('Simulated failure after audit insert');
            });
        });
        $response = match ($operation) {
            'manual' => $this->manual(),
            'csv' => $this->csv("202600001\n"),
            'exam' => $this->postJson($this->base().'/exams', $this->examPayload($this->fixture['room']->id)),
        };
        $response->assertStatus(500);
        $this->assertSame($baseline, DB::table('audit_logs')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('exam_eligibilities', 0);
        $this->assertDatabaseCount('exams', $operation === 'exam' ? 0 : 1);
    }

    public function test_invalid_or_unauthorized_requests_leave_no_success_events(): void
    {
        $this->postJson($this->base().'/enrollments/manual', ['sisCode' => '999999999'])->assertNotFound();
        $this->postJson($this->base().'/exams', [])->assertUnprocessable();
        $other = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $other->roles()->attach(DB::table('roles')->where('name', 'DOCENTE')->value('id'), ['status' => 'ACTIVE']);
        Sanctum::actingAs($other);
        $this->manual()->assertForbidden();
        $this->postJson($this->base().'/exams', $this->examPayload($this->fixture['room']->id))->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('exams', 0);
    }
}
