<?php

namespace Tests\Feature\ExamManagement;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Room;
use App\Models\StudentQrToken;
use App\Models\Teacher;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\Support\ExamVersionPrecision;
use Tests\TestCase;

class ExamUpdateTest extends TestCase
{
    use CourseOfferingFixture, ExamVersionPrecision, RefreshDatabase;

    private array $fixture;

    private Exam $exam;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableExamMicroseconds();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create($this->examPayload($this->fixture['room']->id) + ['course_offering_id' => $this->fixture['offering']->id,
            'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED']);
        $this->version = $this->exam->fresh()->updated_at->toISOString();
        Sanctum::actingAs($this->fixture['user']);
    }

    public function test_patch_persists_all_allowed_fields_preserves_history_and_audits_before_after(): void
    {
        DB::table('enrollments')->insert(['course_offering_id' => $this->fixture['offering']->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id]);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
        $room = Room::create(['code' => 'NEW', 'name' => 'Otra aula', 'status' => 'ACTIVE']);
        $response = $this->edit(['name' => '  Actualizado  ', 'room_id' => $room->id, 'exam_date' => '2026-10-22', 'start_time' => '11:30:00',
            'duration_minutes' => 120, 'evaluation_type' => 'final', 'rules' => '  Nuevas normas  '])->assertOk()
            ->assertJsonPath('data.name', 'Actualizado')->assertJsonPath('data.room.id', $room->id)->assertJsonPath('data.exam_date', '2026-10-22')
            ->assertJsonPath('data.start_time', '11:30:00')->assertJsonPath('data.duration_minutes', 120)->assertJsonPath('data.evaluation_type', 'final')
            ->assertJsonPath('data.rules', 'Nuevas normas')->assertJsonPath('data.id', $this->exam->id)
            ->assertJsonPath('data.course_offering_id', $this->fixture['offering']->id)->assertJsonPath('data.status', 'SCHEDULED')->assertJsonPath('data.can_edit', true);
        $this->assertNotSame($this->version, $response->json('data.updated_at'));
        $this->assertSame($this->fixture['user']->id, $this->exam->fresh()->created_by);
        $this->assertDatabaseCount('exams', 1);
        $this->assertDatabaseCount('exam_eligibilities', 1);
        $this->assertDatabaseCount('enrollments', 1);
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('data.updated_at', $response->json('data.updated_at'))->assertJsonPath('data.name', 'Actualizado');
        $audit = DB::table('audit_logs')->where('action', 'EXAM_UPDATED')->first();
        $this->assertSame($this->exam->id, (int) $audit->entity_id);
        $this->assertSame($this->fixture['user']->id, (int) $audit->user_id);
        $this->assertSame('Parcial', json_decode($audit->old_values, true)['name']);
        $this->assertSame('Actualizado', json_decode($audit->new_values, true)['name']);
        $this->assertSame($response->json('data.updated_at'), json_decode($audit->new_values, true)['updated_at']);
    }

    public function test_two_changes_in_same_second_get_distinct_persisted_microsecond_versions(): void
    {
        $first = $this->edit(['name' => 'Primero'])->assertOk()->json('data.updated_at');
        $this->edit(['name' => 'Viejo'])->assertStatus(409)->assertJsonPath('code', 'EXAM_VERSION_CONFLICT');
        $second = $this->edit(['name' => 'Segundo', 'expected_updated_at' => $first])->assertOk()->json('data.updated_at');
        $this->assertNotSame($first, $second);
        $this->assertSame(substr($first, 0, 19), substr($second, 0, 19));
        $this->assertSame($second, $this->exam->fresh()->updated_at->toISOString());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'EXAM_UPDATED')->count());
    }

    public function test_edit_and_cancel_reject_stale_versions_in_both_orders(): void
    {
        $changed = $this->edit(['name' => 'Modificado'])->assertOk()->json('data.updated_at');
        $this->postJson($this->endpoint().'/cancel', ['expected_updated_at' => $this->version])->assertStatus(409)->assertJsonPath('code', 'EXAM_VERSION_CONFLICT');
        $cancelled = $this->postJson($this->endpoint().'/cancel', ['expected_updated_at' => $changed])->assertOk()->json('data.updated_at');
        $this->assertNotSame($changed, $cancelled);
        $this->edit(['name' => 'No sobrescribir', 'expected_updated_at' => $changed])->assertStatus(409)->assertJsonPath('code', 'EXAM_VERSION_CONFLICT');
        $this->assertSame('Modificado', $this->exam->fresh()->name);
        $this->assertSame('CANCELLED', $this->exam->fresh()->status);
    }

    public function test_own_room_is_excluded_other_overlaps_are_rejected_and_boundaries_are_allowed(): void
    {
        $this->edit(['name' => 'Misma aula'])->assertOk();
        $this->version = $this->exam->fresh()->updated_at->toISOString();
        $other = $this->exam->replicate();
        $other->start_time = '11:00:00';
        $other->save();
        $this->edit(['start_time' => '10:30:00', 'duration_minutes' => 60])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->assertSame('10:00:00', $this->exam->fresh()->start_time);
        $this->edit(['start_time' => '10:30:00', 'duration_minutes' => 30])->assertOk();
    }

    public function test_cross_midnight_overlap_and_inactive_room_are_rejected(): void
    {
        $other = $this->exam->replicate();
        $other->exam_date = '2026-10-22';
        $other->start_time = '00:15:00';
        $other->save();
        $this->edit(['exam_date' => '2026-10-21', 'start_time' => '23:30:00', 'duration_minutes' => 90])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->fixture['room']->update(['status' => 'INACTIVE']);
        $this->edit(['name' => 'No guardar'])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->assertSame('Parcial', $this->exam->fresh()->name);
    }

    public function test_foreign_or_missing_exam_and_protected_fields_are_rejected(): void
    {
        $other = Teacher::factory()->create();
        $offering = CourseOffering::create(['teacher_id' => $other->id, 'subject_id' => $this->fixture['subject']->id,
            'academic_term_id' => $this->fixture['term']->id, 'status' => 'ACTIVE']);
        $foreign = $this->exam->replicate();
        $foreign->course_offering_id = $offering->id;
        $foreign->save();
        $this->patchJson('/api/v1/teacher/exams/'.$foreign->id, $this->payload())->assertNotFound();
        $this->patchJson('/api/v1/teacher/exams/999999', $this->payload())->assertNotFound();
        foreach (['course_offering_id', 'teacher_id', 'status', 'created_by'] as $field) {
            $this->edit([$field => 1])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_started_cancelled_and_inactive_reference_states_cannot_be_edited(): void
    {
        foreach (['IN_PROGRESS', 'COMPLETED', 'CANCELLED'] as $status) {
            $this->exam->update(['status' => $status]);
            $this->edit(['name' => 'No'])->assertStatus(409);
        }
        $this->exam->update(['status' => 'SCHEDULED']);
        foreach (['offering', 'teacher', 'subject', 'term'] as $key) {
            $this->fixture[$key]->update(['status' => 'INACTIVE']);
            $this->edit(['name' => 'No'])->assertUnprocessable();
            $this->fixture[$key]->update(['status' => 'ACTIVE']);
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'America/La_Paz'));
        $this->edit(['name' => 'No', 'exam_date' => '2026-10-22'])->assertStatus(409);
        $this->assertSame('Parcial', $this->exam->fresh()->name);
    }

    public function test_rescheduling_revokes_old_tokens_but_content_changes_keep_them(): void
    {
        $token = StudentQrToken::create(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'token' => (string) Str::uuid(), 'status' => 'ACTIVE', 'generated_at' => now()]);
        $version = $this->edit(['name' => 'Nombre nuevo'])->assertOk()->json('data.updated_at');
        $this->assertSame('ACTIVE', $token->fresh()->status);
        $this->edit(['expected_updated_at' => $version, 'duration_minutes' => 15])->assertOk();
        $this->assertSame('REVOKED', $token->fresh()->status);
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertDatabaseCount('student_qr_tokens', 1);
    }

    public function test_audit_failure_rolls_back_fields_version_and_token_revocation(): void
    {
        $token = StudentQrToken::create(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'token' => (string) Str::uuid(), 'status' => 'ACTIVE', 'generated_at' => now()]);
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('private SQL details')));
        $response = $this->edit(['name' => 'No persistir', 'duration_minutes' => 90])->assertStatus(500);
        $this->assertStringNotContainsString('private SQL details', $response->getContent());
        $this->assertSame('Parcial', $this->exam->fresh()->name);
        $this->assertSame($this->version, $this->exam->fresh()->updated_at->toISOString());
        $this->assertSame('ACTIVE', $token->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_UPDATED')->count());
    }

    public function test_repeating_unchanged_payload_does_not_bump_version_or_audit(): void
    {
        $this->edit()->assertOk()->assertJsonPath('data.updated_at', $this->version);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_UPDATED')->count());
    }

    public function test_postgres_without_precision_rejects_real_edits_without_writes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL timestamp precision.');
        }
        DB::statement('ALTER TABLE exams ALTER COLUMN updated_at TYPE timestamp(0) without time zone');
        $this->edit(['name' => 'No guardar'])->assertStatus(409)->assertJsonPath('code', 'EXAM_VERSION_PRECISION_REQUIRED');
        $this->assertSame('Parcial', $this->exam->fresh()->name);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_UPDATED')->count());
    }

    private function payload(array $overrides = []): array
    {
        return array_replace($this->examPayload($this->fixture['room']->id) + ['expected_updated_at' => $this->version], $overrides);
    }

    private function edit(array $overrides = [])
    {
        return $this->patchJson($this->endpoint(), $this->payload($overrides));
    }

    private function endpoint(): string
    {
        return '/api/v1/teacher/exams/'.$this->exam->id;
    }
}
