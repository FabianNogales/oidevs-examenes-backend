<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentType;
use App\Models\ExamCollaborator;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentRegistrationTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    private function payload(): array
    {
        return ['type' => 'SUSPECTED_IMPERSONATION', 'description' => '  Identidad por verificar.  '];
    }

    public function test_owner_can_report_every_type_without_student_and_server_sets_metadata(): void
    {
        $this->actingAs($this->owner);
        foreach (IncidentType::cases() as $type) {
            $response = $this->postJson($this->url(), array_replace($this->payload(), ['type' => $type->value]))
                ->assertCreated()->assertJsonPath('data.exam_id', $this->exam->id)
                ->assertJsonPath('data.student_id', null)->assertJsonPath('data.origin', 'MANUAL')
                ->assertJsonPath('data.reported_by', $this->owner->id)->assertJsonPath('data.status', 'PENDING')
                ->assertJsonPath('data.description', 'Identidad por verificar.');
            $this->assertNotEmpty($response->json('data.reported_at'));
            $this->assertDatabaseHas('special_cases', ['id' => $response->json('data.id'), 'type' => $type->value]);
        }
        $this->assertDatabaseCount('infractions', 0);
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertSame(5, DB::table('audit_logs')->where('action', 'INCIDENT_REPORTED')->count());
    }

    public function test_identified_student_is_preserved_even_without_enrollment(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), $this->payload() + ['student_id' => $this->student->id])
            ->assertCreated()->assertJsonPath('data.student_id', $this->student->id);
        $this->assertDatabaseHas('special_cases', ['student_id' => $this->student->id, 'reported_by' => $this->owner->id]);
    }

    public function test_authorized_collaborator_can_report(): void
    {
        $collaborator = $this->collaborator();
        $this->actingAs($collaborator)->postJson($this->url(), $this->payload())
            ->assertCreated()->assertJsonPath('data.reported_by', $collaborator->id);
        $this->assertDatabaseCount('infractions', 0);
    }

    public function test_revoked_and_expired_collaborators_cannot_report(): void
    {
        $collaborator = $this->collaborator();
        ExamCollaborator::query()->where('user_id', $collaborator->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->actingAs($collaborator)->postJson($this->url(), $this->payload())->assertForbidden();
        ExamCollaborator::query()->where('user_id', $collaborator->id)->update(['status' => 'ACTIVE', 'revoked_at' => null]);
        $this->exam->update(['exam_date' => now()->subDay()->toDateString()]);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('special_cases', 0);
    }

    public function test_direct_requests_from_unrelated_and_inactive_users_are_rejected(): void
    {
        $outsider = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->actingAs($outsider)->postJson($this->url(), $this->payload())->assertForbidden();
        $this->owner->update(['status' => 'INACTIVE']);
        $this->actingAs($this->owner)->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('special_cases', 0);
    }

    public function test_invalid_payload_cannot_create_partial_records(): void
    {
        $this->actingAs($this->owner)->postJson($this->url(), $this->payload() + ['reported_by' => 123])
            ->assertUnprocessable()->assertJsonValidationErrors('reported_by');
        $this->postJson($this->url(), ['description' => 'Sin tipo.'])->assertUnprocessable();
        $this->postJson($this->url(), $this->payload() + ['student_id' => 999999])->assertUnprocessable();
        $this->assertDatabaseCount('special_cases', 0);
    }

    public function test_audit_failure_rolls_back_the_manual_incident(): void
    {
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated persistence failure'));
        });
        $this->actingAs($this->owner)->postJson($this->url(), $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('special_cases', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'INCIDENT_REPORTED')->count());
    }

    public function test_guest_and_missing_exam_are_rejected(): void
    {
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->actingAs($this->owner)->postJson('/api/v1/exams/999999/incidents', $this->payload())->assertNotFound();
    }
}
