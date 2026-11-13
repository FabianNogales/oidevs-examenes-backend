<?php

namespace Tests\Feature\Discipline;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ExpulsionFixtures;
use Tests\TestCase;
use App\Models\{DisciplineCase, Infraction};

class DisciplineCaseTest extends TestCase
{
    use RefreshDatabase, ExpulsionFixtures;

    private function endpoint(): string { return '/api/v1/exams/'.$this->exam->id.'/discipline-cases'; }
    private function payload(): array { return ['kind' => 'INCIDENT', 'type' => 'IDENTIFICATION', 'description' => 'Documento ilegible.', 'student_id' => $this->student->id, 'decision' => 'RECORD', 'request_key' => (string) Str::uuid()]; }

    public function test_single_case_with_measure_and_retry_preserves_one_record_and_original_evidence(): void
    {
        $payload = array_replace($this->payload(), ['decision' => 'EXPEL', 'confirmed' => true, 'reason' => 'Decisión justificada.']);
        $this->actingAs($this->owner);
        $id = $this->postJson($this->endpoint(), $payload)->assertCreated()->assertJsonPath('data.measure.reason', $payload['reason'])->json('data.id');
        $this->postJson($this->endpoint(), $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('special_cases', 1);
        $this->assertDatabaseCount('discipline_cases', 1);
        $this->assertDatabaseCount('expulsions', 1);
        $this->assertDatabaseHas('special_cases', ['status' => 'PENDING']);
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    }

    public function test_measure_failure_rolls_back_the_whole_registration(): void
    {
        $this->exam->update(['status' => 'CANCELLED']);
        $payload = array_replace($this->payload(), ['decision' => 'EXPEL', 'confirmed' => true, 'reason' => 'Motivo.']);
        $this->actingAs($this->owner)->postJson($this->endpoint(), $payload)->assertConflict();
        $this->assertDatabaseCount('special_cases', 0);
        $this->assertDatabaseCount('discipline_cases', 0);
        $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_general_incident_requires_identified_student_for_measure_and_explicit_confirmation(): void
    {
        $payload = array_replace($this->payload(), ['student_id' => null]);
        $this->actingAs($this->owner);
        $id = $this->postJson($this->endpoint(), $payload)->assertCreated()->json('data.id');
        $this->postJson($this->endpoint().'/'.$id.'/expulsion', ['reason' => 'Motivo.', 'confirmed' => true])->assertUnprocessable();
        $this->postJson($this->endpoint(), array_replace($payload, ['decision' => 'EXPEL', 'reason' => 'Motivo.']))->assertUnprocessable();
        $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_case_scope_and_reused_key_with_different_payload_are_rejected(): void
    {
        $this->actingAs($this->owner);
        $payload = $this->payload();
        $id = $this->postJson($this->endpoint(), $payload)->assertCreated()->json('data.id');
        $this->postJson($this->endpoint(), array_replace($payload, ['description' => 'Distinto']))->assertConflict();
        $foreign = $this->exam->replicate(); $foreign->save();
        $this->getJson('/api/v1/exams/'.$foreign->id.'/discipline-cases/'.$id)->assertNotFound();
        $this->actingAs($this->collaborator())->postJson($this->endpoint().'/'.$id.'/expulsion', ['reason' => 'Motivo.', 'confirmed' => true])->assertForbidden();
    }

    public function test_old_infraction_and_expulsion_are_one_case_and_filters_count_the_whole_query(): void
    {
        $this->actingAs($this->owner);
        $infraction = Infraction::create(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'type' => 'COPYING', 'description' => 'Copia.', 'reported_by' => $this->owner->id, 'occurred_at' => now(), 'status' => 'REPORTED']);
        $id = DisciplineCase::where('source_type', 'INFRACTION')->where('source_id', $infraction->id)->value('id');
        $this->postJson('/api/v1/exams/'.$this->exam->id.'/expulsions', $this->expulsionPayload() + ['infraction_id' => $infraction->id])->assertCreated();
        $this->postJson($this->endpoint(), $this->payload())->assertCreated();
        $this->getJson($this->endpoint().'?measure=EXPELLED&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson($this->endpoint().'?kind=INCIDENT')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('discipline_cases', 2);
    }
    public function test_observations_are_preserved_and_collaborator_cannot_read_measure_justification(): void
    {
        $this->actingAs($this->owner);
        $payload = array_replace($this->payload(), ['observations' => 'Conservar esta observación.', 'decision' => 'EXPEL', 'reason' => 'Justificación reservada.', 'confirmed' => true]);
        $id = $this->postJson($this->endpoint(), $payload)->assertCreated()->assertJsonPath('data.observations', $payload['observations'])->json('data.id');
        $this->actingAs($this->collaborator())->getJson($this->endpoint().'/'.$id)->assertOk()->assertJsonPath('data.measure', null)->assertJsonPath('data.measure_applied', true)->assertJsonPath('data.permissions.can_expel', false);
    }

    public function test_migration_preserves_legacy_evidence_and_links_measure_without_duplicate_case(): void
    {
        $this->actingAs($this->owner);
        $infraction = Infraction::create(['exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'type' => 'COPYING', 'description' => 'Evidencia histórica.', 'reported_by' => $this->owner->id, 'occurred_at' => now(), 'status' => 'REPORTED']);
        $measureId = $this->postJson('/api/v1/exams/'.$this->exam->id.'/expulsions', $this->expulsionPayload() + ['infraction_id' => $infraction->id])->assertCreated()->json('data.id');
        $migration = require database_path('migrations/2026_11_10_000001_unify_discipline_cases.php');
        $migration->down();
        $this->assertDatabaseHas('infractions', ['id' => $infraction->id, 'description' => 'Evidencia histórica.']);
        $migration->up();
        $this->assertDatabaseCount('discipline_cases', 1);
        $caseId = DisciplineCase::value('id');
        $this->assertDatabaseHas('expulsions', ['id' => $measureId, 'discipline_case_id' => $caseId]);
        $this->getJson($this->endpoint().'/resolve?source_type=EXPULSION&source_id='.$measureId)->assertOk()->assertJsonPath('data.id', $caseId);
    }
    public function test_exports_include_one_case_with_measure_and_respect_scope_and_filters(): void
    {
        $this->actingAs($this->owner);
        $payload = array_replace($this->payload(), ['description' => '=unsafe', 'decision' => 'EXPEL', 'confirmed' => true, 'reason' => 'Decisión documentada.']);
        $this->postJson($this->endpoint(), $payload)->assertCreated();
        $csv = $this->get($this->endpoint().'/export?format=csv&measure=EXPELLED')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
        $this->assertStringContainsString("'=unsafe", $csv);
        $this->assertStringContainsString('Decisión documentada.', $csv);
        $this->get($this->endpoint().'/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->collaborator())->getJson($this->endpoint().'/export?format=csv')->assertForbidden();
    }
}
