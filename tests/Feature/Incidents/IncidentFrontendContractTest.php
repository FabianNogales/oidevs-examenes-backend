<?php

namespace Tests\Feature\Incidents;

use App\Services\Entries\WrongExamAuditService;
use App\Services\Incidents\IncidentIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentFrontendContractTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    public function test_empty_listing_supplies_context_and_post_permission_without_entry_time_restriction(): void
    {
        $this->exam->update(['exam_date' => now()->subDay()->toDateString(), 'status' => 'FINISHED']);
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()
            ->assertJsonPath('exam.id', $this->exam->id)->assertJsonPath('exam.name', $this->exam->name)
            ->assertJsonPath('exam.subject_name', 'Materia')->assertJsonPath('permissions.can_create', true)
            ->assertJsonPath('data', [])->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1);
        $this->postJson($this->url(), ['type' => 'ENVIRONMENT', 'description' => 'Problema del ambiente.', 'student_id' => null])
            ->assertCreated()->assertJsonPath('data.student', null);
        $this->getJson($this->url().'?page=2')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_manual_contract_preserves_profile_strings_and_persisted_metadata(): void
    {
        $this->student->update(['sis_code' => '00123', 'identity_number' => '0099']);
        DB::table('enrollments')->insert(['student_id' => $this->student->id,
            'course_offering_id' => $this->exam->course_offering_id, 'registered_by' => $this->owner->id, 'status' => 'ACTIVE']);
        $created = $this->actingAs($this->owner)->postJson($this->url(), [
            'type' => 'WRONG_EXAM', 'description' => '  QR correspondiente a otra evaluación.  ', 'student_id' => $this->student->id,
        ])->assertCreated()->assertJsonPath('data.type', 'WRONG_EXAM')->assertJsonPath('data.origin', 'MANUAL')
            ->assertJsonPath('data.status', 'REPORTED')->assertJsonPath('data.student.sis_code', '00123')
            ->assertJsonPath('data.student.identity_number', '0099')->assertJsonPath('data.reported_by.id', $this->owner->id)
            ->assertJsonPath('data.reported_by.name', 'Docente Responsable');
        $data = $created->json('data');
        $this->assertIsInt($data['id']);
        $this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:\d{2})$/', $data['created_at']);
        $this->getJson($this->url().'/'.$data['id'])->assertOk()->assertExactJson(['data' => $data]);
        $this->getJson($this->url().'?type=WRONG_EXAM')->assertOk()->assertJsonPath('data.0', $data);
        $this->assertDatabaseCount('infractions', 0);
    }

    public function test_manual_student_is_scoped_but_ineligible_student_is_allowed(): void
    {
        $payload = ['type' => 'INELIGIBILITY', 'description' => 'Estudiante no habilitado.', 'student_id' => $this->student->id];
        $this->actingAs($this->owner)->postJson($this->url(), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('student_id');
        $this->assertDatabaseCount('special_cases', 0);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'status' => 'INELIGIBLE', 'reason_code' => 'OTHER']);
        $this->postJson($this->url(), $payload)->assertCreated()->assertJsonPath('data.student.id', $this->student->id);
    }

    public function test_automatic_numeric_identity_is_stable_resolvable_and_disjoint_from_manual_ids(): void
    {
        $eventId = app(WrongExamAuditService::class)->record($this->owner->id, $this->exam->id, 123, $this->student->id);
        $id = IncidentIdentity::automatic($eventId);
        $this->assertGreaterThan(0, $id);
        $this->assertLessThanOrEqual(9007199254740991, $id);
        $this->actingAs($this->owner);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson($this->url().'/'.$id)->assertOk()->assertJsonPath('data.id', $id)
                ->assertJsonPath('data.type', 'WRONG_EXAM')->assertJsonPath('data.origin', 'AUTOMATIC')
                ->assertJsonPath('data.status', 'REGISTERED')->assertJsonPath('data.student.id', $this->student->id)
                ->assertJsonPath('data.reported_by.id', $this->owner->id);
            $this->getJson($this->url().'?origin=AUTOMATIC')->assertOk()->assertJsonPath('data.0.id', $id);
        }
        $other = $this->exam->replicate();
        $other->save();
        $this->getJson('/api/v1/exams/'.$other->id.'/incidents/'.$id)->assertNotFound();
        $this->assertDatabaseCount('special_cases', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ENTRY_QR_WRONG_EXAM')->count());
    }
}
