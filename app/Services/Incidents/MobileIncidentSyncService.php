<?php

namespace App\Services\Incidents;

use App\Models\Exam;
use App\Models\ExamOfflineDistribution;
use App\Models\ExamOfflineList;
use App\Models\ExamOfflinePackage;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Offline\OfflineExamAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Evidence transport only. HU27 owns official reports; HU30/HU31 are never called. */
class MobileIncidentSyncService
{
    public function __construct(private IncidentRegistrationService $registration, private IncidentAccessService $access,
        private OfflineExamAccess $offline, private AuditLogService $audit) {}

    public function sync(int $examId, array $event, string $raw, string $hash, Request $request): array
    {
        abort_unless(Schema::hasTable('mobile_incident_sync_events'), 503, 'La migración de incidencias offline está pendiente.');
        return DB::transaction(function () use ($examId, $event, $raw, $hash, $request) {
            // Domain lock order: exam, actor, assignment. UUID PK serializes cross-exam collisions too.
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isActive() && $event['actor_id'] === (string) $actor->id && $event['exam_id'] === (string) $examId, 403);
            $package = ExamOfflinePackage::findOrFail($event['package_id']);
            $list = ExamOfflineList::findOrFail($package->list_id);
            $distribution = ExamOfflineDistribution::findOrFail($list->distribution_id);
            abort_unless((int) $package->downloaded_by === (int) $actor->id && (int) $list->responsible_id === (int) $actor->id
                && (int) $distribution->exam_id === $examId, 403);
            $snapshot = $package->snapshot['mobile'] ?? null;
            abort_unless(is_array($snapshot), 403, 'El paquete no contiene autorización móvil.');
            $manifest = json_decode($snapshot['manifest_json'], true, flags: JSON_THROW_ON_ERROR);
            $binding = $manifest['binding'];
            $token = $request->user()->currentAccessToken();
            $device = $token instanceof PersonalAccessToken ? MobileDevice::find($token->mobile_device_id) : null;
            abort_unless($device && $device->revoked_at === null && (int) $device->user_id === (int) $actor->id
                && $binding['user_id'] === $event['actor_id'] && $binding['device_id'] === $event['mobile_device_id']
                && (string) $device->id === $event['mobile_device_id'] && $device->installation_id === $event['installation_id']
                && $binding['installation_id'] === $event['installation_id'], 403);

            $received = now()->toISOString();
            $inserted = DB::table('mobile_incident_sync_events')->insertOrIgnore([
                'event_id' => $event['event_id'], 'exam_id' => $examId, 'actor_id' => $actor->id, 'package_id' => $package->id,
                'request_hash' => $hash, 'evidence_ciphertext' => Crypt::encryptString($raw), 'received_at' => now(),
            ]);
            $stored = DB::table('mobile_incident_sync_events')->where('event_id', $event['event_id'])->lockForUpdate()->first();
            abort_unless((int) $stored->actor_id === (int) $actor->id, 403);
            if (! $inserted) {
                if (! hash_equals($stored->request_hash, $hash)) {
                    $this->audit->log($request, $actor->id, 'MOBILE_INCIDENT_UUID_CONFLICT', 'MobileIncidentSyncEvent',
                        newValues: ['event_id' => $event['event_id'], 'request_hash' => $hash, 'original_hash' => $stored->request_hash]);
                    return $this->receipt($event['event_id'], $hash, 'CONFLICT', 'EVENT_ID_REUSED', null, true, $received);
                }
                // Original receipt survives response loss, exam end and later collaboration revocation.
                abort_if($stored->result === null, 503);
                return $this->ordered(json_decode($stored->result, true, flags: JSON_THROW_ON_ERROR));
            }

            $status = 'CONFLICT'; $code = 'OFFLINE_AUTHORIZATION_CHANGED'; $incidentId = null; $review = true;
            $captured = CarbonImmutable::parse($event['captured_at']);
            $time = $event['time_evidence'];
            $estimate = $time['serverMs'] + $time['capturedElapsed'] - $time['elapsed'];
            $captureValid = $time['capturedElapsed'] >= $time['elapsed']
                && abs($captured->getTimestampMs() - floor($estimate / 1000) * 1000) < 1
                && $time['serverMs'] >= CarbonImmutable::parse($manifest['server_time'])->getTimestampMs()
                && $captured->greaterThanOrEqualTo(CarbonImmutable::parse($manifest['exam']['entry_opens_at']))
                && $captured->lessThan(CarbonImmutable::parse($manifest['expires_at'])) && ! $captured->isFuture();
            $authorization = $this->offline->authorizationHash($exam, $actor);
            $member = $event['student_id'] === null || collect($manifest['students'])->contains('student_id', $event['student_id']);
            if (! $member) { $status = 'REJECTED'; $code = 'STUDENT_NOT_IN_PACKAGE'; }
            elseif (! $captureValid) { $code = 'CAPTURE_TIME_REQUIRES_REVIEW'; }
            elseif (! $authorization || ! hash_equals($list->authorization_hash, $authorization)) { /* evidence only */ }
            else {
                try {
                    $this->access->authorize($exam, $actor, lockAssignment: true);
                    // No changes to Team 1 validation/policy. Map the public alias before invoking HU27.
                    $incident = $this->registration->register($examId, [
                        'type' => $event['type'] === 'WRONG_EXAM' ? 'WRONG_EXAM_QR' : $event['type'],
                        'description' => trim($event['description']), 'student_id' => $event['student_id'],
                    ], $request);
                    $status = 'REGISTERED'; $code = 'INCIDENT_REPORTED'; $incidentId = (int) $incident->id;
                    // A late report remains a report pending review, never a disciplinary decision.
                    $review = now()->greaterThanOrEqualTo(CarbonImmutable::parse($manifest['expires_at']));
                } catch (ValidationException) { $status = 'REJECTED'; $code = 'INCIDENT_VALIDATION_FAILED'; }
                catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 403) throw $error;
                    $code = 'INCIDENT_AUTHORIZATION_REVOKED';
                }
            }
            $result = $this->receipt($event['event_id'], $hash, $status, $code, $incidentId, $review, $received);
            DB::table('mobile_incident_sync_events')->where('event_id', $event['event_id'])->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $this->audit->log($request, $actor->id, 'MOBILE_INCIDENT_PROCESSED', 'MobileIncidentSyncEvent',
                newValues: ['event_id' => $event['event_id'], 'exam_id' => $examId, 'request_hash' => $hash, 'status' => $status, 'incident_id' => $incidentId, 'review_required' => $review]);
            return $result;
        }, 3);
    }

    private function receipt(string $id, string $hash, string $status, string $code, ?int $incident, bool $review, string $received): array
    {
        return ['event_id' => $id, 'request_hash' => $hash, 'status' => $status, 'code' => $code,
            'incident_id' => $incident, 'review_required' => $review, 'received_at' => $received];
    }
    private function ordered(array $r): array
    {
        return $this->receipt($r['event_id'], $r['request_hash'], $r['status'], $r['code'], $r['incident_id'], $r['review_required'], $r['received_at']);
    }
}
