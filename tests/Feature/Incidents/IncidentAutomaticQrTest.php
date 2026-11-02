<?php

namespace Tests\Feature\Incidents;

use App\Models\SpecialCase;
use App\Models\User;
use App\Services\Entries\WrongExamAuditService;
use App\Services\Incidents\IncidentIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentAutomaticQrTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    private function event(array $overrides = []): int
    {
        return DB::table('audit_logs')->insertGetId($overrides + [
            'user_id' => $this->owner->id, 'action' => 'ENTRY_QR_WRONG_EXAM', 'entity_type' => 'Exam',
            'entity_id' => $this->exam->id, 'created_at' => now(),
            'new_values' => json_encode(['student_id' => $this->student->id, 'qr_exam_id' => 123,
                'method' => 'QR', 'reason' => 'WRONG_EXAM']),
        ]);
    }

    public function test_existing_wrong_exam_event_is_visible_without_creating_a_duplicate(): void
    {
        app(WrongExamAuditService::class)->record($this->owner->id, $this->exam->id, 123, $this->student->id);
        $id = (int) DB::table('audit_logs')->where('action', 'ENTRY_QR_WRONG_EXAM')->value('id');
        $before = DB::table('audit_logs')->get()->toJson();
        $this->actingAs($this->owner);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', IncidentIdentity::automatic($id))->assertJsonPath('data.0.origin', 'AUTOMATIC');
            $this->getJson($this->url().'/qr-'.$id)->assertOk()
                ->assertJsonPath('data.student_id', $this->student->id)
                ->assertJsonPath('data.exam_id', $this->exam->id)
                ->assertJsonPath('data.source_event_id', $id)->assertJsonPath('data.reason', 'WRONG_EXAM')
                ->assertJsonPath('data.qr_exam_id', 123)->assertJsonPath('data.status', 'REGISTERED')
                ->assertJsonPath('data.reported_by.id', $this->owner->id);
        }
        $this->assertSame($before, DB::table('audit_logs')->get()->toJson());
        $this->assertDatabaseCount('special_cases', 0);
        $this->assertDatabaseCount('infractions', 0);
    }

    public function test_mixed_results_have_distinct_ids_even_when_source_ids_collide(): void
    {
        $eventId = $this->event();
        $manual = SpecialCase::create(['exam_id' => $this->exam->id, 'student_id' => null,
            'type' => 'ENVIRONMENT', 'description' => 'Falla de iluminación.', 'reported_by' => $this->owner->id, 'status' => 'PENDING']);
        if ($manual->id !== $eventId) {
            DB::table('special_cases')->where('id', $manual->id)->update(['id' => $eventId]);
            $manual->id = $eventId;
        }
        $this->actingAs($this->owner)->getJson($this->url().'?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson($this->url().'?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url().'/'.$manual->id)->assertOk()->assertJsonPath('data.origin', 'MANUAL');
        $this->getJson($this->url().'/qr-'.$eventId)->assertOk()->assertJsonPath('data.origin', 'AUTOMATIC');
        $this->getJson($this->url().'?origin=MANUAL')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $manual->id);
        $this->getJson($this->url().'?origin=AUTOMATIC_QR')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', IncidentIdentity::automatic($eventId));
        $this->getJson($this->url().'?origin=INVALID')->assertUnprocessable()->assertJsonValidationErrors('origin');
    }

    public function test_filters_apply_to_automatic_events_and_separate_scans_are_preserved(): void
    {
        $this->event();
        $this->event();
        $this->event(['new_values' => json_encode(['qr_exam_id' => 123])]);
        $this->actingAs($this->owner)->getJson($this->url().'?type=WRONG_EXAM_QR&student_id='.$this->student->id)
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url().'?type=IDENTIFICATION')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url().'?origin=AUTOMATIC_QR')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_unrelated_audits_and_events_from_other_exams_are_not_exposed(): void
    {
        $foreign = $this->event(['entity_id' => $this->exam->id + 100]);
        $otherAction = $this->event(['action' => 'INCIDENT_REPORTED']);
        $otherEntity = $this->event(['entity_type' => 'SpecialCase']);
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 0);
        foreach ([$foreign, $otherAction, $otherEntity, 999999] as $id) {
            $this->getJson($this->url().'/qr-'.$id)->assertNotFound();
        }
    }

    public function test_automatic_events_require_exam_permission_and_reject_invalid_ids(): void
    {
        $id = $this->event();
        $this->getJson($this->url().'/qr-'.$id)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]))
            ->getJson($this->url().'/qr-'.$id)->assertForbidden();
        $this->actingAs($this->collaborator())->getJson($this->url().'/qr-'.$id)->assertOk();
        $this->actingAs($this->owner);
        foreach (['qr-0', 'qr--1', 'qr-text', 'qr-9999999999999999999999999', '9999999999999999999999999'] as $invalid) {
            $this->getJson($this->url().'/'.$invalid)->assertNotFound();
        }
    }

    public function test_legacy_event_with_missing_student_and_deleted_actor_can_be_consulted(): void
    {
        $id = $this->event(['user_id' => null, 'new_values' => null]);
        $this->actingAs($this->owner)->getJson($this->url().'/qr-'.$id)->assertOk()
            ->assertJsonPath('data.student_id', null)->assertJsonPath('data.reported_by', null)
            ->assertJsonPath('data.qr_exam_id', null);
    }
}
