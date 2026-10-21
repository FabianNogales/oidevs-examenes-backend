<?php

namespace Tests\Feature\Api\V1\Entries;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Room;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Students\StudentQrService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\Support\ExamVersionPrecision;
use Tests\TestCase;

class SharedExamAvailabilityTest extends TestCase
{
    use CourseOfferingFixture, ExamVersionPrecision, RefreshDatabase;

    private array $fixture;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        $this->enableExamMicroseconds();
        $this->fixture = $this->offeringFixture();
        $this->fixture['term']->update(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->exam = Exam::create(array_replace($this->examPayload($this->fixture['room']->id), [
            'exam_date' => '2026-10-16', 'start_time' => '10:00:00', 'duration_minutes' => 60,
            'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED',
        ]));
        DB::table('enrollments')->insert(['course_offering_id' => $this->exam->course_offering_id,
            'student_id' => $this->fixture['studentId'], 'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id]);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
        Sanctum::actingAs($this->fixture['user']);
    }

    private function base(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/entry-control';
    }

    private function qr(): string
    {
        return app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id)['token'];
    }

    private function scan(string $token, ?int $roomId = null)
    {
        return $this->postJson($this->base().'/qr', ['qr_token' => $token, 'room_id' => $roomId ?? $this->exam->room_id]);
    }

    private function manual()
    {
        return $this->postJson($this->base().'/students/'.$this->fixture['studentId'].'/entries', ['room_id' => $this->exam->room_id]);
    }

    private function edit(array $overrides)
    {
        return $this->patchJson('/api/v1/teacher/exams/'.$this->exam->id,
            array_replace($this->exam->only(['name', 'exam_date', 'start_time', 'duration_minutes', 'room_id', 'evaluation_type', 'rules']),
                ['expected_updated_at' => $this->exam->fresh()->updated_at->toISOString()], $overrides));
    }

    public function test_state_matrix_and_reason_priority_preserve_counters_and_history(): void
    {
        $cases = ['CANCELLED' => 'EXAM_CANCELLED', 'COMPLETED' => 'EXAM_FINISHED',
            'FINISHED' => 'EXAM_FINISHED', 'UNKNOWN' => 'EXAM_STATE_NOT_ALLOWED',
            'SCHEDULED' => null, 'ACTIVE' => null, 'IN_PROGRESS' => null];
        foreach ($cases as $state => $reason) {
            $this->exam->update(['status' => $state]);
            $response = $this->getJson($this->base().'/students')->assertOk()
                ->assertJsonPath('exam.entry_available', $reason === null)->assertJsonPath('exam.entry_unavailable_reason', $reason)
                ->assertJsonPath('data.0.meets_entry_conditions', $reason === null)
                ->assertJsonPath('summary.pending', 1);
            $codes = array_column($response->json('data.0.blockers'), 'code');
            $this->assertSame($reason === null ? [] : ['EXAM_UNAVAILABLE'], $codes);
            $this->getJson($this->base().'/students/'.$this->fixture['studentId'])->assertOk()
                ->assertJsonPath('exam.entry_available', $reason === null);
            $this->assertSame($reason === null, ExamCollaborator::examIsAvailable($this->exam));
            if ($reason === null) {
                $token = $this->qr();
                $this->scan($token)->assertCreated();
                DB::table('exam_entries')->update(['status' => 'ANNULLED']);
                $this->manual()->assertCreated();
                DB::table('exam_entries')->update(['status' => 'ANNULLED']);
            } else {
                $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
                $this->assertCount(0, app(StudentQrService::class)->getExamsForStudent($this->fixture['studentId']));
            }
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-17', 'America/La_Paz'));
        foreach ($cases as $state => $reason) {
            $this->exam->update(['status' => $state]);
            $this->assertSame($reason ?? 'EXAM_ENDED', ExamEntryAvailability::reason($this->exam));
        }
        $this->getJson($this->base().'/entries')->assertOk()->assertJsonPath('meta.total', 6);
    }

    public function test_end_excluded_midnight_and_generation_window_use_la_paz_even_with_utc_config(): void
    {
        $this->exam->update(['start_time' => '23:30:00', 'duration_minutes' => 90]);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 23:29:59', 'America/La_Paz'));
        $list = app(StudentQrService::class)->getExamsForStudent($this->fixture['studentId']);
        $this->assertSame('UPCOMING', $list->first()['qr_status']);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 23:30:00', 'America/La_Paz'));
        $token = $this->qr();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 00:59:59', 'America/La_Paz'));
        $this->getJson($this->base().'/students')->assertOk()->assertJsonPath('exam.entry_available', true);
        $this->scan($token)->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-17 01:00:00', 'America/La_Paz'));
        $this->getJson($this->base().'/students')->assertOk()->assertJsonPath('exam.entry_unavailable_reason', 'EXAM_ENDED')
            ->assertJsonPath('data.0.entry_status', 'REGISTERED')->assertJsonPath('summary.registered', 1);
        $this->scan($token)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR');
        $this->assertSame('FINISHED', app(StudentQrService::class)->getExamsForStudent($this->fixture['studentId'])->first()['qr_status']);
        DB::table('exam_entries')->update(['status' => 'ANNULLED']);
        $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
        $this->expectException(\Exception::class);
        $this->qr();
    }

    public function test_hu18_cancel_revokes_qr_blocks_manual_and_preserves_historical_access(): void
    {
        $token = $this->qr();
        $this->postJson('/api/v1/teacher/exams/'.$this->exam->id.'/cancel',
            ['expected_updated_at' => $this->exam->fresh()->updated_at->toISOString()])->assertOk();
        $this->scan($token)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR');
        $this->manual()->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'EXAM_UNAVAILABLE');
        $this->getJson($this->base().'/entries')->assertOk();
        $this->assertDatabaseHas('student_qr_tokens', ['status' => 'REVOKED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXAM_CANCELLED']);
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_reprogramming_revokes_old_token_and_new_qr_uses_current_end(): void
    {
        $old = $this->qr();
        $this->edit(['exam_date' => '2026-10-18', 'start_time' => '23:30:00', 'duration_minutes' => 90])->assertOk();
        $this->exam->refresh();
        $this->scan($old)->assertUnprocessable()->assertJsonPath('result', 'INVALID_QR');
        $this->assertSame('UPCOMING', app(StudentQrService::class)->getExamsForStudent($this->fixture['studentId'])->first()['qr_status']);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 23:30:00', 'America/La_Paz'));
        $new = $this->qr();
        $claims = json_decode(base64_decode(strtr(explode('.', $new)[1], '-_', '+/')), true);
        $this->assertSame(CarbonImmutable::parse('2026-10-19 01:00:00', 'America/La_Paz')->timestamp, $claims['exp']);
        $this->assertSame(1, StudentQrToken::where('status', 'REVOKED')->count());
        $this->assertSame(1, StudentQrToken::where('status', 'ACTIVE')->count());
        $this->scan($new)->assertCreated();
    }

    public function test_room_change_keeps_token_but_old_room_is_rejected(): void
    {
        $token = $this->qr();
        $oldRoom = $this->exam->room_id;
        $room = Room::create(['code' => 'B-202', 'name' => 'Nueva aula', 'status' => 'ACTIVE']);
        $this->edit(['room_id' => $room->id])->assertOk();
        $this->exam->refresh();
        $this->scan($token, $oldRoom)->assertUnprocessable()->assertJsonPath('data.blockers.0.code', 'ROOM_MISMATCH');
        $this->assertSame(0, StudentQrToken::where('status', 'REVOKED')->count());
        $this->scan($token)->assertCreated()->assertJsonPath('data.entry.room_id', $room->id);
    }

    public function test_ineligible_external_label_and_collaborator_legacy_state_and_expiry(): void
    {
        DB::table('exam_eligibilities')->update(['status' => 'INELIGIBLE']);
        $item = app(StudentQrService::class)->getExamsForStudent($this->fixture['studentId'])->first();
        $this->assertSame('NOT_ELIGIBLE', $item['qr_status']);
        $this->assertFalse($item['is_qr_available']);
        $this->assertNull($item['token']);
        $this->exam->update(['status' => 'ACTIVE']);
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->postJson('/api/v1/exams/'.$this->exam->id.'/collaborators', ['user_id' => $user->id])->assertCreated();
        Sanctum::actingAs($user);
        $this->getJson($this->base().'/students')->assertOk();
        $this->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(1, 'data');
        $this->travelTo(CarbonImmutable::parse('2026-10-16 11:00:00', 'America/La_Paz'));
        $this->getJson($this->base().'/students')->assertForbidden();
        $this->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(0, 'data');
    }
}
