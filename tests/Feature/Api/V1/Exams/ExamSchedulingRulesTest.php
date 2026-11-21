<?php

namespace Tests\Feature\Api\V1\Exams;

use App\Models\Exam;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\Support\ExamVersionPrecision;
use Tests\TestCase;

class ExamSchedulingRulesTest extends TestCase
{
    use CourseOfferingFixture, ExamVersionPrecision, RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableExamMicroseconds();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 06:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->fixture['term']->update([
            'name' => 'II/2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-15',
        ]);
        Sanctum::actingAs($this->fixture['user']);
    }

    public function test_store_and_update_allow_recovery_and_keep_partial_number_null(): void
    {
        $createResponse = $this->postJson($this->storeEndpoint(), $this->validPayload([
            'name' => 'Recuperatorio 2',
            'evaluation_type' => 'recovery',
            'partial_number' => 2,
        ]))->assertCreated();

        $examId = (int) $createResponse->json('data.id');
        $this->assertDatabaseHas('exams', [
            'id' => $examId,
            'evaluation_type' => 'recovery',
            'partial_number' => null,
            'is_shared_room' => false,
        ]);

        // Switch to partial with partial_number=3, then back to recovery and verify partial_number resets to null.
        $exam = Exam::findOrFail($examId);
        $updatedToPartial = $this->patchJson("/api/v1/teacher/exams/{$examId}", $this->validUpdatePayload($exam, [
            'name' => 'Tercer Parcial',
            'evaluation_type' => 'partial',
            'partial_number' => 3,
        ]))->assertOk();

        $this->assertDatabaseHas('exams', [
            'id' => $examId,
            'evaluation_type' => 'partial',
            'partial_number' => 3,
        ]);

        $this->patchJson("/api/v1/teacher/exams/{$examId}", $this->validUpdatePayload($exam->fresh(), [
            'name' => 'Recuperatorio 3',
            'evaluation_type' => 'recovery',
            'partial_number' => 3,
            'expected_updated_at' => $updatedToPartial->json('data.updated_at'),
        ]))->assertOk()->assertJsonPath('data.evaluation_type', 'recovery');

        $this->assertDatabaseHas('exams', [
            'id' => $examId,
            'evaluation_type' => 'recovery',
            'partial_number' => null,
        ]);
    }

    public function test_store_and_update_enforce_institutional_start_times_duration_and_21_45_limit(): void
    {
        // Reject non-institutional start time
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'start_time' => '10:00:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('start_time');

        // Reject duration > 270
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'duration_minutes' => 271,
        ]))->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');

        // Reject 20:15 + 120 min (reserves 180 -> 23:15 > 21:45)
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'start_time' => '20:15:00',
            'duration_minutes' => 120,
        ]))->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');

        // Accept 20:15 + 90 min (reserves 90 -> 21:45 <= 21:45)
        $created = $this->postJson($this->storeEndpoint(), $this->validPayload([
            'start_time' => '20:15:00',
            'duration_minutes' => 90,
        ]))->assertCreated();

        $exam = Exam::findOrFail((int) $created->json('data.id'));

        // Reject update to non-institutional start time
        $this->patchJson("/api/v1/teacher/exams/{$exam->id}", $this->validUpdatePayload($exam, [
            'start_time' => '11:30:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('start_time');

        // Reject update exceeding 21:45 (18:45 + 181 reserves 270 -> 23:15)
        $this->patchJson("/api/v1/teacher/exams/{$exam->id}", $this->validUpdatePayload($exam, [
            'start_time' => '18:45:00',
            'duration_minutes' => 181,
        ]))->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');

        // Accept update fitting exactly at 21:45 (17:15 + 270 -> 21:45)
        $this->patchJson("/api/v1/teacher/exams/{$exam->id}", $this->validUpdatePayload($exam, [
            'start_time' => '17:15:00',
            'duration_minutes' => 270,
        ]))->assertOk()->assertJsonPath('data.duration_minutes', 270);
    }

    public function test_120_minute_exam_reserves_two_full_90_minute_blocks_for_overlap_and_room_slots(): void
    {
        // 08:15 + 120 min must reserve 180 min -> 08:15 to 11:15
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'name' => 'Examen de 2 horas',
            'exam_date' => '2026-10-15',
            'start_time' => '08:15:00',
            'duration_minutes' => 120,
        ]))->assertCreated();

        // Room index without time filter reports busy_slots/schedules_list with end = 11:15
        $listResponse = $this->getJson('/api/v1/rooms?course_offering_id='.$this->fixture['offering']->id)
            ->assertOk();
        $listResponse->assertJsonPath('data.0.schedules_list.0.start', '08:15')
            ->assertJsonPath('data.0.schedules_list.0.end', '11:15')
            ->assertJsonPath('data.0.schedules_list.0.is_shared', false);

        // Querying availability at 09:45 (inside second reserved period 09:45-11:15) excludes the room
        $this->getJson('/api/v1/rooms?exam_date=2026-10-15&start_time=09:45:00&duration_minutes=90')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Scheduling at 09:45 in the same room fails due to full-block overlap
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'name' => 'Solapado en segundo bloque',
            'exam_date' => '2026-10-15',
            'start_time' => '09:45:00',
            'duration_minutes' => 60,
        ]))->assertUnprocessable()->assertJsonValidationErrors('room_id');

        // Querying and scheduling at 11:15 succeeds (adjacent block boundary)
        $this->getJson('/api/v1/rooms?exam_date=2026-10-15&start_time=11:15:00&duration_minutes=90')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.busy_slots.0.end', '11:15');

        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'name' => 'Siguiente bloque libre',
            'exam_date' => '2026-10-15',
            'start_time' => '11:15:00',
            'duration_minutes' => 90,
        ]))->assertCreated();
    }

    public function test_shared_room_flags_are_ignored_and_occupied_rooms_cannot_be_shared(): void
    {
        $first = $this->postJson($this->storeEndpoint(), $this->validPayload([
            'name' => 'Examen 1',
            'exam_date' => '2026-10-15',
            'start_time' => '08:15:00',
            'duration_minutes' => 90,
            'is_shared_room' => true,
            'shared_consent' => true,
        ]))->assertCreated();

        $firstId = (int) $first->json('data.id');
        $this->assertDatabaseHas('exams', ['id' => $firstId, 'is_shared_room' => false]);
        $this->assertDatabaseHas('exam_rooms', [
            'exam_id' => $firstId,
            'room_id' => $this->fixture['room']->id,
            'is_shared' => false,
            'shared_with_consent' => false,
        ]);

        // Trying to schedule another exam in the same room/block with sharing flags must fail
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'name' => 'Examen 2 intento compartir',
            'exam_date' => '2026-10-15',
            'start_time' => '08:15:00',
            'duration_minutes' => 60,
            'is_shared_room' => true,
            'shared_consent' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('room_id');

        // Create second exam in another room, then try to update into occupied room with sharing flags
        $otherRoom = Room::create(['code' => 'B-02', 'name' => 'Aula 02', 'capacity' => 40, 'status' => 'ACTIVE']);
        $second = $this->postJson($this->storeEndpoint(), $this->validPayload([
            'name' => 'Examen 2',
            'room_id' => $otherRoom->id,
            'exam_date' => '2026-10-15',
            'start_time' => '08:15:00',
            'duration_minutes' => 90,
        ]))->assertCreated();

        $secondExam = Exam::findOrFail((int) $second->json('data.id'));
        $this->patchJson("/api/v1/teacher/exams/{$secondExam->id}", $this->validUpdatePayload($secondExam, [
            'room_id' => $this->fixture['room']->id,
            'is_shared_room' => true,
            'shared_consent' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('room_id');
    }

    public function test_rooms_index_returns_academic_term_metadata_for_course_offering(): void
    {
        $term = $this->fixture['term'];

        $this->getJson('/api/v1/rooms?course_offering_id='.$this->fixture['offering']->id)
            ->assertOk()
            ->assertJsonPath('meta.academic_term', [
                'id' => (int) $term->id,
                'name' => 'II/2026',
                'start_date' => '2026-08-01',
                'end_date' => '2026-12-15',
            ]);
    }

    public function test_store_and_update_enforce_academic_term_date_range(): void
    {
        // Date after term end_date rejected on store
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'exam_date' => '2026-12-16',
        ]))->assertUnprocessable()->assertJsonValidationErrors('exam_date');

        // Date before term start_date rejected on store (travel before term start so after_or_equal:today passes)
        $this->travelTo(CarbonImmutable::parse('2026-07-20 08:00:00', 'America/La_Paz'));
        $this->postJson($this->storeEndpoint(), $this->validPayload([
            'exam_date' => '2026-07-31',
        ]))->assertUnprocessable()->assertJsonValidationErrors('exam_date');

        // Exact start_date boundary succeeds
        $created = $this->postJson($this->storeEndpoint(), $this->validPayload([
            'exam_date' => '2026-08-01',
        ]))->assertCreated();

        $exam = Exam::findOrFail((int) $created->json('data.id'));

        // Update outside term end_date rejected
        $this->patchJson("/api/v1/teacher/exams/{$exam->id}", $this->validUpdatePayload($exam, [
            'exam_date' => '2026-12-16',
        ]))->assertUnprocessable()->assertJsonValidationErrors('exam_date');

        // Update before term start_date rejected
        $this->patchJson("/api/v1/teacher/exams/{$exam->id}", $this->validUpdatePayload($exam, [
            'exam_date' => '2026-07-31',
        ]))->assertUnprocessable()->assertJsonValidationErrors('exam_date');

        // Exact end_date boundary succeeds
        $this->patchJson("/api/v1/teacher/exams/{$exam->id}", $this->validUpdatePayload($exam, [
            'exam_date' => '2026-12-15',
        ]))->assertOk()->assertJsonPath('data.exam_date', '2026-12-15');
    }

    private function storeEndpoint(): string
    {
        return '/api/v1/course-offerings/'.$this->fixture['offering']->id.'/exams';
    }

    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Primer Parcial',
            'exam_date' => '2026-10-15',
            'start_time' => '08:15:00',
            'duration_minutes' => 90,
            'room_id' => $this->fixture['room']->id,
            'evaluation_type' => 'partial',
            'rules' => 'Normas del examen.',
        ], $overrides);
    }

    private function validUpdatePayload(Exam $exam, array $overrides = []): array
    {
        return array_replace([
            'name' => $exam->name,
            'exam_date' => substr((string) $exam->exam_date, 0, 10),
            'start_time' => substr((string) $exam->start_time, 0, 8),
            'duration_minutes' => (int) $exam->duration_minutes,
            'room_id' => (int) $exam->room_id,
            'evaluation_type' => (string) $exam->evaluation_type,
            'rules' => $exam->rules,
            'expected_updated_at' => $exam->fresh()->updated_at->toISOString(),
        ], $overrides);
    }
}
