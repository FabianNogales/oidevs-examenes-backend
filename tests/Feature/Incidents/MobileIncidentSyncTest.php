<?php

namespace Tests\Feature\Incidents;

use App\Models\ExamCollaborator;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\MobileDisciplinePermissions;
use App\Services\Auth\MobileTokenPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IsolatedMobileDatabase;
use Tests\Support\OfflineExamFixture;
use Tests\TestCase;

/** Written only. Positive HTTP cases activate the proposed map ONLY in the isolated test container. */
class MobileIncidentSyncTest extends TestCase
{
    use IsolatedMobileDatabase, OfflineExamFixture;

    private string $bearer;
    private MobileDevice $device;

    protected function setUp(): void
    {
        parent::setUp(); $this->refreshDatabase(); $this->createOfflineFixture();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('i', 32))]);
        $this->credential($this->fixture['user']);
    }

    private function credential(User $actor): void
    {
        $this->device = MobileDevice::create(['user_id' => $actor->id, 'installation_id' => (string) Str::uuid(), 'name' => 'Synthetic incident test', 'last_login_at' => now()]);
        $token = $actor->createToken('eida-control:incident-test', [...MobileTokenPermissions::ABILITIES, ...MobileDisciplinePermissions::ABILITIES], now()->addHours(8));
        $token->accessToken->forceFill(['mobile_device_id' => $this->device->id])->save();
        $this->bearer = $token->plainTextToken;
        $this->app['auth']->forgetGuards(); $this->withHeader('Authorization', 'Bearer '.$this->bearer);
    }

    private function reviewedMap(): void
    {
        $current = new MobileTokenPermissions(); $proposal = new MobileDisciplinePermissions();
        $this->mock(MobileTokenPermissions::class, function ($mock) use ($current, $proposal) {
            $mock->shouldReceive('requiredAbility')->andReturnUsing(fn ($request) => $current->requiredAbility($request)
                ?? $proposal->requiredAbility($request->method().' '.$request->route()->uri()));
        });
    }

    private function package(bool $prepare = true): array
    {
        if ($prepare) $this->postJson($this->offlineBase().'/prepare')->assertOk();
        $data = $this->getJson($this->offlineBase().'/package?schema_version=2')->assertOk()->json('data');
        return json_decode($data['mobile']['manifest_json'], true, flags: JSON_THROW_ON_ERROR);
    }
    private function event(array $m, array $extra = []): array
    {
        return array_replace(['v' => 1, 'event_id' => (string) Str::uuid(), 'exam_id' => (string) $this->exam->id,
            'package_id' => $m['package_id'], 'actor_id' => $m['binding']['user_id'], 'mobile_device_id' => $m['binding']['device_id'],
            'installation_id' => $m['binding']['installation_id'], 'student_id' => null, 'type' => 'ENVIRONMENT',
            'description' => 'Descripción sintética protegida', 'captured_at' => now()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'time_evidence' => ['serverMs' => CarbonImmutable::parse($m['server_time'])->getTimestampMs(), 'boot' => '3',
                'elapsed' => 100000, 'capturedElapsed' => 100000, 'source' => 'SERVER_ANCHORED_ESTIMATE']], $extra);
    }
    private function sync(array $event)
    {
        $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->app['auth']->forgetGuards();
        return $this->postJson($this->offlineBase().'/incidents', ['event_json' => $json, 'event_sha256' => hash('sha256', $json)]);
    }

    public function test_central_mobile_policy_remains_closed_until_team_review(): void
    {
        $event = $this->event($this->package());
        $this->sync($event)->assertForbidden()->assertJsonPath('code', 'MOBILE_ABILITY_REQUIRED');
        $this->assertDatabaseCount('mobile_incident_sync_events', 0);
        $this->assertDatabaseCount('special_cases', 0);
    }

    public function test_teacher_registers_general_report_once_with_encrypted_immutable_original_and_stable_receipt(): void
    {
        $this->reviewedMap(); $event = $this->event($this->package());
        $first = $this->sync($event)->assertOk()->assertJsonPath('data.status', 'REGISTERED')->json();
        $this->sync($event)->assertExactJson($first); // Simulates recovering a lost response with original UUID.
        $this->assertDatabaseCount('special_cases', 1);
        $this->assertDatabaseCount('infractions', 0); $this->assertDatabaseCount('exam_entries', 0);
        $record = DB::table('mobile_incident_sync_events')->first();
        $this->assertStringNotContainsString($event['description'], $record->evidence_ciphertext);
        $this->assertSame($event, json_decode(Crypt::decryptString($record->evidence_ciphertext), true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('special_cases', ['id' => $first['data']['incident_id'], 'student_id' => null, 'status' => 'PENDING']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'INCIDENT_REPORTED')->count());
        $changed = array_replace($event, ['description' => 'Contenido alterado']);
        $this->sync($changed)->assertOk()->assertJsonPath('data.status', 'CONFLICT')->assertJsonPath('data.code', 'EVENT_ID_REUSED');
        $this->assertSame($record->evidence_ciphertext, DB::table('mobile_incident_sync_events')->value('evidence_ciphertext'));
        $this->sync($event)->assertExactJson($first);
    }

    public function test_assigned_student_public_alias_and_read_contract_are_preserved(): void
    {
        $this->reviewedMap(); $m = $this->package();
        $event = $this->event($m, ['type' => 'WRONG_EXAM', 'student_id' => $m['students'][0]['student_id']]);
        $id = $this->sync($event)->assertOk()->assertJsonPath('data.status', 'REGISTERED')->json('data.incident_id');
        $this->getJson('/api/v1/exams/'.$this->exam->id.'/incidents/'.$id)->assertOk()
            ->assertJsonPath('data.type', 'WRONG_EXAM')->assertJsonPath('data.status', 'REPORTED');
        $this->assertDatabaseHas('special_cases', ['id' => $id, 'type' => 'WRONG_EXAM_QR']);
        $this->sync($this->event($m, ['student_id' => '999999']))->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_contextual_collaborator_can_report_but_cannot_infract_expel_or_annul(): void
    {
        $this->reviewedMap(); $collaborator = $this->offlineCollaborator(); $this->addOfflineStudent();
        $this->postJson($this->offlineBase().'/prepare')->assertOk();
        $this->credential($collaborator); $m = $this->package(false);
        $this->sync($this->event($m))->assertOk()->assertJsonPath('data.status', 'REGISTERED');
        $base = '/api/v1/exams/'.$this->exam->id;
        $this->getJson($base.'/discipline-context')->assertOk()->assertJsonPath('data.can_report_incidents', true)->assertJsonPath('data.can_manage_discipline', false);
        $this->postJson($base.'/infractions', ['student_id' => $this->fixture['studentId'], 'type' => 'COPYING', 'description' => 'Prueba'])->assertForbidden();
        $this->postJson($base.'/expulsions', ['student_id' => $this->fixture['studentId'], 'reason' => 'Prueba'])->assertForbidden();
        $this->postJson($base.'/entry-control/entries/999/annulment', ['reason' => 'Prueba'])->assertForbidden();
        $this->assertDatabaseCount('infractions', 0); $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_revoked_collaboration_retains_conflict_evidence_without_creating_report(): void
    {
        $this->reviewedMap(); $collaborator = $this->offlineCollaborator(); $this->addOfflineStudent();
        $this->postJson($this->offlineBase().'/prepare')->assertOk(); $this->credential($collaborator);
        $event = $this->event($this->package(false));
        ExamCollaborator::where('user_id', $collaborator->id)->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->sync($event)->assertOk()->assertJsonPath('data.status', 'CONFLICT');
        $this->assertDatabaseCount('special_cases', 0); $this->assertDatabaseCount('mobile_incident_sync_events', 1);
    }

    public function test_late_owner_report_is_received_as_report_for_review_not_as_sanction(): void
    {
        $this->reviewedMap(); $event = $this->event($this->package()); $this->travel(3)->hours();
        $this->sync($event)->assertOk()->assertJsonPath('data.status', 'REGISTERED')->assertJsonPath('data.review_required', true);
        $this->assertDatabaseHas('special_cases', ['status' => 'PENDING']);
        $this->assertDatabaseCount('infractions', 0); $this->assertDatabaseCount('expulsions', 0);
    }

    public function test_inactive_account_and_revoked_device_cannot_send_or_access_another_actor(): void
    {
        $this->reviewedMap(); $event = $this->event($this->package());
        $this->sync(array_replace($event, ['actor_id' => '999999']))->assertForbidden();
        $this->device->update(['revoked_at' => now()]);
        $this->sync($event)->assertUnauthorized();
        $this->device->update(['revoked_at' => null]); $this->fixture['user']->update(['status' => 'INACTIVE']);
        $this->sync($event)->assertUnauthorized();
        $this->assertDatabaseCount('special_cases', 0); $this->assertDatabaseCount('mobile_incident_sync_events', 0);
    }

    public function test_another_legitimate_actor_cannot_recover_the_original_receipt_by_reusing_uuid(): void
    {
        $this->reviewedMap(); $other = $this->offlineCollaborator(); $this->addOfflineStudent();
        $original = $this->event($this->package());
        $this->sync($original)->assertOk()->assertJsonPath('data.status', 'REGISTERED');
        $this->credential($other);
        $forged = $this->event($this->package(false), ['event_id' => $original['event_id']]);
        $this->sync($forged)->assertForbidden();
        $this->assertDatabaseCount('special_cases', 1); $this->assertDatabaseCount('mobile_incident_sync_events', 1);
    }

    public function test_invalid_type_empty_description_tampered_hash_and_extra_fields_are_422(): void
    {
        $this->reviewedMap(); $m = $this->package();
        foreach ([['type' => 'FAKE'], ['description' => ' '], ['reported_by' => 1], ['student_id' => -1]] as $extra) {
            $this->sync($this->event($m, $extra))->assertUnprocessable();
        }
        $raw = json_encode($this->event($m), JSON_THROW_ON_ERROR);
        $this->postJson($this->offlineBase().'/incidents', ['event_json' => $raw, 'event_sha256' => str_repeat('0', 64)])->assertUnprocessable();
        $this->assertDatabaseCount('special_cases', 0); $this->assertDatabaseCount('mobile_incident_sync_events', 0);
    }

    public function test_audit_failure_rolls_back_report_evidence_and_receipt_together(): void
    {
        $this->reviewedMap(); $event = $this->event($this->package());
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('Synthetic audit failure')));
        $this->sync($event)->assertStatus(500);
        $this->assertDatabaseCount('special_cases', 0); $this->assertDatabaseCount('mobile_incident_sync_events', 0);
    }
}
