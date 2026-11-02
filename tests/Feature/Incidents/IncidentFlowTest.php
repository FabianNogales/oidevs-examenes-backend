<?php

namespace Tests\Feature\Incidents;

use App\Models\StudentQrToken;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IncidentFixtures;
use Tests\TestCase;

class IncidentFlowTest extends TestCase
{
    use IncidentFixtures;
    use RefreshDatabase;

    public function test_real_wrong_exam_scan_is_rejected_and_consultable_without_copying_the_event(): void
    {
        $other = $this->exam->replicate();
        $other->name = 'Examen del QR';
        $other->save();
        $uuid = (string) Str::uuid();
        StudentQrToken::create(['exam_id' => $other->id, 'student_id' => $this->student->id,
            'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()]);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode([
            'v' => 2, 'exp' => now()->addHour()->timestamp, 'jti' => $uuid,
        ]);
        $token = $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/exams/'.$this->exam->id.'/entry-control/qr', ['qr_token' => $token, 'room_id' => $this->exam->room_id])
            ->assertUnprocessable()->assertJsonPath('result', 'WRONG_EXAM')->assertJsonPath('data', null)
            ->assertJsonPath('incident.exam_id', $this->exam->id);
        $incidentId = $response->json('incident.id');
        $this->assertIsInt($incidentId);
        $this->getJson($this->url().'/'.$incidentId)->assertOk()->assertJsonPath('data.id', $incidentId)
            ->assertJsonPath('data.origin', 'AUTOMATIC')->assertJsonPath('data.type', 'WRONG_EXAM');
        $this->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.origin', 'AUTOMATIC')->assertJsonPath('data.0.reason', 'WRONG_EXAM')
            ->assertJsonPath('data.0.qr_exam_id', $other->id);
        $this->getJson('/api/v1/exams/'.$other->id.'/incidents')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('special_cases', 0);
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertDatabaseCount('infractions', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ENTRY_QR_WRONG_EXAM')->count());
    }

    public function test_report_clock_and_responsible_are_preserved_from_creation_to_detail(): void
    {
        $clock = CarbonImmutable::parse('2026-11-01 09:20:30', 'America/La_Paz');
        $this->travelTo($clock);
        DB::table('enrollments')->insert(['course_offering_id' => $this->exam->course_offering_id, 'student_id' => $this->student->id, 'status' => 'ACTIVE', 'registered_by' => $this->owner->id]);
        $response = $this->actingAs($this->owner)->postJson($this->url(), [
            'type' => 'SUSPECTED_IMPERSONATION', 'description' => 'Identidad por verificar.', 'student_id' => $this->student->id,
        ])->assertCreated()->assertJsonPath('data.reported_at', $clock->utc()->toISOString());
        $id = $response->json('data.id');
        $this->travelTo($clock->addHour());
        $this->getJson($this->url().'/'.$id)->assertOk()->assertExactJson(['data' => $response->json('data')]);
        $this->assertDatabaseCount('infractions', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'INCIDENT_REPORTED')->count());
    }
}
