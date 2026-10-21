<?php

namespace Tests\Feature\ExamManagement;

use App\Http\Requests\Exams\UpdateExamRequest;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class ExamManagementPreparationTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create($this->examPayload($this->fixture['room']->id) + ['course_offering_id' => $this->fixture['offering']->id,
            'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED']);
        Sanctum::actingAs($this->fixture['user']);
        // Exercise the real FormRequest while PATCH remains pending its version prerequisite.
        Route::patch('/test-hu18-edit-validation', fn (UpdateExamRequest $request) => response()->json(['data' => $request->validated()]));
    }

    public function test_own_exclusion_frees_only_the_exam_itself_and_keeps_creation_behavior(): void
    {
        $this->rooms()->assertOk()->assertJsonCount(0, 'data');
        $this->rooms(['exclude_exam_id' => $this->exam->id])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->fixture['room']->id);
        $this->secondExam();
        $this->rooms(['exclude_exam_id' => $this->exam->id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_foreign_missing_or_malformed_exclusion_cannot_bypass_reservations(): void
    {
        $other = Teacher::factory()->create();
        $offering = CourseOffering::create(['teacher_id' => $other->id, 'subject_id' => $this->fixture['subject']->id,
            'academic_term_id' => $this->fixture['term']->id, 'status' => 'ACTIVE']);
        $foreign = $this->secondExam(['course_offering_id' => $offering->id]);
        foreach ([$foreign->id, 999999] as $id) {
            $this->rooms(['exclude_exam_id' => $id])->assertNotFound()->assertJsonMissingPath('data');
        }
        foreach ([0, -1, 'invalid', '1.5', '999999999999999999999999'] as $id) {
            $this->rooms(['exclude_exam_id' => $id])->assertUnprocessable();
        }
        $this->getJson('/api/v1/rooms?exclude_exam_id='.$this->exam->id)->assertUnprocessable();
    }

    public function test_exclusion_never_reintroduces_inactive_room_and_in_progress_reserves_it(): void
    {
        $this->fixture['room']->update(['status' => 'INACTIVE']);
        $this->rooms(['exclude_exam_id' => $this->exam->id])->assertOk()->assertJsonCount(0, 'data');
        $this->fixture['room']->update(['status' => 'ACTIVE']);
        $this->secondExam(['status' => 'IN_PROGRESS']);
        $this->rooms(['exclude_exam_id' => $this->exam->id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_adjacent_cross_midnight_and_cancelled_intervals_keep_half_open_rules(): void
    {
        $this->exam->update(['exam_date' => '2026-10-21', 'start_time' => '23:30:00', 'duration_minutes' => 90]);
        $this->rooms(['exam_date' => '2026-10-22', 'start_time' => '00:30', 'duration_minutes' => 15])->assertOk()->assertJsonCount(0, 'data');
        $this->rooms(['exam_date' => '2026-10-22', 'start_time' => '01:00', 'duration_minutes' => 15])->assertOk()->assertJsonCount(1, 'data');
        $this->rooms(['exam_date' => '2026-10-22', 'start_time' => '00:30', 'duration_minutes' => 15, 'exclude_exam_id' => $this->exam->id])->assertOk()->assertJsonCount(1, 'data');
        $this->exam->update(['status' => 'CANCELLED']);
        $this->rooms(['exam_date' => '2026-10-22', 'start_time' => '00:30', 'duration_minutes' => 15])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_complete_edit_payload_is_normalized_without_imposing_a_new_duration_or_rules_limit(): void
    {
        $payload = $this->payload(['name' => '  Actualizado  ', 'rules' => str_repeat('x', 10000), 'duration_minutes' => 2147483647]);
        $this->patchJson('/test-hu18-edit-validation', $payload)->assertOk()->assertJsonPath('data.name', 'Actualizado')->assertJsonPath('data.duration_minutes', 2147483647);
        $this->patchJson('/test-hu18-edit-validation', $this->payload(['rules' => '  ']))->assertOk()->assertJsonPath('data.rules', null);
        $this->patchJson('/test-hu18-edit-validation', $this->payload(['rules' => '0']))->assertOk()->assertJsonPath('data.rules', '0');
    }

    public function test_invalid_dates_time_durations_room_type_version_and_protected_fields_are_rejected(): void
    {
        $cases = ['name' => ['', str_repeat('x', 256)], 'exam_date' => ['2026-02-30', '2026-10-15', 'invalid'],
            'start_time' => ['24:00:00', '10:00', 'bad'], 'duration_minutes' => [0, -1, 1.5, '60', 2147483648],
            'room_id' => [0, 999999, '1'], 'evaluation_type' => ['unknown'],
            'expected_updated_at' => ['invalid', '2026-02-30T09:00:00Z', '2026-10-16 09:00:00']];
        foreach ($cases as $field => $values) {
            foreach ($values as $value) {
                $this->patchJson('/test-hu18-edit-validation', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        foreach (['id', 'course_offering_id', 'subject_id', 'teacher_id', 'academic_term_id', 'status', 'created_by'] as $field) {
            $this->patchJson('/test-hu18-edit-validation', $this->payload([$field => 1]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->patchJson('/test-hu18-edit-validation', $this->payload(['exam_date' => '2026-10-16', 'start_time' => '09:00:00']))->assertUnprocessable()->assertJsonValidationErrors('start_time');
        foreach (array_keys($this->payload()) as $field) {
            $payload = $this->payload();
            unset($payload[$field]);
            $this->patchJson('/test-hu18-edit-validation', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('Parcial', $this->exam->fresh()->name);
        $this->assertDatabaseCount('exams', 1);
    }

    private function secondExam(array $overrides = []): Exam
    {
        return Exam::create(array_replace($this->exam->only(['course_offering_id', 'room_id', 'name', 'exam_date', 'start_time', 'duration_minutes', 'evaluation_type', 'rules', 'status', 'created_by']), $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace($this->examPayload($this->fixture['room']->id) + ['expected_updated_at' => $this->exam->fresh()->updated_at->toISOString()], $overrides);
    }

    private function rooms(array $overrides = [])
    {
        return $this->getJson('/api/v1/rooms?'.http_build_query(array_replace(['exam_date' => '2026-10-21', 'start_time' => '10:00', 'duration_minutes' => 60], $overrides)));
    }
}
