<?php

namespace Tests\Feature\Incidents;

use App\Models\ExamCollaborator;
use App\Models\SpecialCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentReadTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    private function incident(array $attributes = []): SpecialCase
    {
        return SpecialCase::create($attributes + [
            'exam_id' => $this->exam->id, 'student_id' => null, 'type' => 'IDENTIFICATION',
            'description' => 'Documento ilegible.', 'reported_by' => $this->owner->id, 'status' => 'PENDING',
        ]);
    }

    public function test_empty_catalogue_and_missing_details(): void
    {
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson($this->url().'/999999')->assertNotFound();
        $this->getJson('/api/v1/exams/999999/incidents')->assertNotFound();
    }

    public function test_owner_can_read_paginated_reports_with_stable_order_and_filters(): void
    {
        $first = $this->incident();
        $second = $this->incident(['type' => 'ENVIRONMENT']);
        $third = $this->incident(['student_id' => $this->student->id]);
        DB::table('special_cases')->update(['created_at' => now()->toDateTimeString()]);

        $this->actingAs($this->owner)->getJson($this->url().'?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $third->id)->assertJsonPath('data.1.id', $second->id);
        $this->getJson($this->url().'?per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
        $this->getJson($this->url().'?type=ENVIRONMENT')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $second->id);
        $this->getJson($this->url().'?type=IDENTIFICATION&student_id='.$this->student->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $third->id);
        $this->getJson($this->url().'?page=10')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_detail_preserves_report_and_optional_student_without_mutating_data(): void
    {
        $incident = $this->incident(['student_id' => $this->student->id]);
        $before = DB::table('special_cases')->get()->toJson();
        $auditCount = DB::table('audit_logs')->count();
        $this->actingAs($this->owner)->getJson($this->url().'/'.$incident->id)->assertOk()
            ->assertJsonPath('data.id', $incident->id)->assertJsonPath('data.origin', 'MANUAL')
            ->assertJsonPath('data.description', 'Documento ilegible.')
            ->assertJsonPath('data.student_id', $this->student->id)
            ->assertJsonPath('data.reported_by', $this->owner->id)
            ->assertJsonStructure(['data' => ['type', 'reported_at', 'status']]);
        $this->assertSame($before, DB::table('special_cases')->get()->toJson());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_other_exam_reports_are_excluded_and_cannot_be_read_by_replacing_id(): void
    {
        $otherExam = $this->exam->replicate();
        $otherExam->name = 'Otro examen';
        $otherExam->save();
        $foreign = $this->incident(['exam_id' => $otherExam->id]);
        $local = $this->incident();
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $local->id);
        $this->getJson($this->url().'/'.$foreign->id)->assertNotFound();
    }

    public function test_valid_collaborator_can_read_but_revoked_and_expired_assignments_are_denied(): void
    {
        $incident = $this->incident();
        $collaborator = $this->collaborator();
        $this->actingAs($collaborator)->getJson($this->url())->assertOk();
        $this->getJson($this->url().'/'.$incident->id)->assertOk();
        // Reject inconsistent ACTIVE assignments that already have a revocation timestamp too.
        ExamCollaborator::where('user_id', $collaborator->id)->update(['revoked_at' => now()]);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url().'/'.$incident->id)->assertForbidden();
        ExamCollaborator::where('user_id', $collaborator->id)->update(['revoked_at' => null, 'status' => 'REVOKED']);
        $this->getJson($this->url())->assertForbidden();
        ExamCollaborator::where('user_id', $collaborator->id)->update(['status' => 'ACTIVE']);
        $this->exam->update(['exam_date' => now()->subDay()->toDateString()]);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url().'/'.$incident->id)->assertForbidden();
    }

    public function test_owner_can_consult_history_after_exam_finished(): void
    {
        $incident = $this->incident();
        $this->exam->update(['status' => 'FINISHED', 'exam_date' => now()->subDay()->toDateString()]);
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url().'/'.$incident->id)->assertOk();
    }

    public function test_guest_and_unrelated_user_cannot_read_any_report(): void
    {
        $incident = $this->incident();
        $this->getJson($this->url())->assertUnauthorized();
        $this->getJson($this->url().'/'.$incident->id)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]))
            ->getJson($this->url())->assertForbidden();
        $this->getJson($this->url().'/'.$incident->id)->assertForbidden();
    }

    public function test_invalid_filters_and_identifiers_are_rejected(): void
    {
        $this->actingAs($this->owner);
        foreach (['page=0', 'page=2147483648', 'per_page=101', 'per_page=0', 'type=INVALID', 'student_id=-1', 'student_id=text'] as $query) {
            $this->getJson($this->url().'?'.$query)->assertUnprocessable();
        }
        foreach (['0', '-1', 'text'] as $id) {
            $this->getJson($this->url().'/'.$id)->assertNotFound();
        }
    }
}
