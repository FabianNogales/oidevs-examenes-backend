<?php

namespace App\Services\Offline;

use App\Models\Exam;
use App\Models\ExamOfflineDistribution;
use App\Models\ExamOfflineList;
use App\Models\ExamOfflinePackage;
use App\Models\Student;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Models\MobileDevice;
use App\Services\Audit\AuditLogService;
use App\Services\Entries\EntryControlService;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Entries\QrTokenValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class OfflineSyncService
{
    public function __construct(private OfflineExamAccess $access, private AuditLogService $audit,
        private EntryControlService $control, private QrTokenValidator $tokens, private OfflineQrEvidence $evidence) {}

    public function capabilities(int $examId, Request $request): array
    {
        $exam = Exam::findOrFail($examId);
        $actor = $request->user();
        // Historical ownership remains sufficient to submit evidence after revocation or exam end.
        $historical = DB::table('exam_offline_packages as p')->join('exam_offline_lists as l', 'l.id', '=', 'p.list_id')
            ->join('exam_offline_distributions as d', 'd.id', '=', 'l.distribution_id')
            ->where('d.exam_id', $examId)->where('p.downloaded_by', $actor->id)->exists();
        abort_unless($actor->isActive() && ($historical || $this->access->authorizationHash($exam, $actor)), 403);
        if (! Schema::hasColumns('exam_offline_sync_events', ['evidence_ciphertext', 'evidence_schema_version', 'review_required'])) {
            $this->access->fail('SYNC_MIGRATION_REQUIRED', 'La extensión HU37 requiere aplicar su migración antes de recibir evidencia.', 503);
        }
        return ['protocol_versions' => [1, 2], 'max_events' => 100,
            'qr_versions' => $this->evidence->supportsV3() ? [2, 3] : [2],
            'late_evidence' => true, 'late_policy' => 'REVIEW_ONLY', 'time_evidence' => 'DEVICE_DECLARED_NOT_ATTESTED'];
    }

    public function sync(int $examId, array $batch, Request $request): array
    {
        $results = [];
        foreach ($batch['events'] as $event) {
            // A committed result survives a connection failure or a later event's infrastructure failure.
            $results[] = DB::transaction(fn () => $this->process($examId, $batch, $event, $request), 3);
        }

        return ['exam_id' => $examId, 'results' => $results]
            + ((int) ($batch['protocol_version'] ?? 1) === 2 ? ['protocol_version' => 2] : []);
    }

    private function process(int $examId, array $batch, array $event, Request $request): array
    {
        // Same first lock as HU13/HU14, collaboration changes and exam edits/cancellation.
        $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
        $actor = User::whereKey($request->user()->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
        abort_unless($actor->isActive(), 403);
        $package = ExamOfflinePackage::find(strtolower($batch['package_id']));
        if (! $package || (int) $package->downloaded_by !== $actor->id) {
            $this->access->fail('PACKAGE_NOT_OWNED', 'Paquete no disponible para este usuario.', 403);
        }
        $list = ExamOfflineList::findOrFail($package->list_id);
        $distribution = ExamOfflineDistribution::findOrFail($list->distribution_id);
        abort_unless((int) $distribution->exam_id === $examId && (int) $list->responsible_id === $actor->id,
            403, 'El paquete no corresponde al examen y responsable autenticado.');

        $eventId = strtolower($event['event_id']);
        $captured = CarbonImmutable::parse($event['captured_at']);
        $hashedPayload = [
            'exam_id' => $examId, 'list_id' => (int) $batch['list_id'], 'version' => (int) $batch['version'],
            'package_id' => $package->id, 'room_id' => (int) $batch['room_id'], 'actor_id' => $actor->id,
            'student_id' => (int) $event['student_id'], 'method' => $event['method'],
            'captured_at' => $captured->toISOString(), 'device_id' => $event['device_id'] ?? null,
            'qr_token' => $event['qr_token'] ?? null,
        ];
        $protocol = (int) ($batch['protocol_version'] ?? 1);
        if ($protocol === 2) {
            $t = $event['time_evidence'];
            $hashedPayload['protocol_version'] = 2;
            // Canonical field order; JSON object key ordering is not event identity.
            $hashedPayload['time_evidence'] = ['serverMs' => (int) $t['serverMs'], 'boot' => $t['boot'],
                'elapsed' => (int) $t['elapsed'], 'capturedElapsed' => (int) $t['capturedElapsed'], 'source' => $t['source']];
        }
        $payloadHash = hash('sha256', json_encode($hashedPayload, JSON_THROW_ON_ERROR));
        $student = Student::whereKey($event['student_id'])->lockForUpdate()->first();
        // ON CONFLICT DO NOTHING handles global UUID races across different exams without aborting PostgreSQL's transaction.
        $inserted = DB::table('exam_offline_sync_events')->insertOrIgnore([
            'event_id' => $eventId, 'exam_id' => $examId, 'list_id' => $list->id, 'package_id' => $package->id,
            'synced_by' => $actor->id, 'student_id' => $student?->id, 'list_version' => $batch['version'],
            'payload_hash' => $payloadHash, 'captured_at' => $captured->toIso8601String(),
            'received_at' => now()->toIso8601String(), 'method' => $event['method'], 'device_id' => $event['device_id'] ?? null,
        ]);
        $stored = DB::table('exam_offline_sync_events')->where('event_id', $eventId)->lockForUpdate()->first();
        if (! $inserted) {
            if ((int) $stored->synced_by !== $actor->id || ! hash_equals($stored->payload_hash, $payloadHash)) {
                $this->audit->log($request, $actor->id, 'OFFLINE_EVENT_ID_CONFLICT', 'ExamOfflineList', $list->id,
                    null, ['exam_id' => $examId, 'event_id' => $eventId,
                        'student_id' => (int) $event['student_id'], 'package_id' => $package->id,
                        'requested_list_id' => (int) $batch['list_id'], 'version' => (int) $batch['version'],
                        'captured_at' => $captured->toISOString(), 'received_at' => now()->toISOString(),
                        'method' => $event['method'], 'device_id' => $event['device_id'] ?? null,
                        'status' => 'CONFLICT', 'code' => 'EVENT_ID_REUSED', 'attempt_payload_hash' => $payloadHash,
                        'attempt_evidence_ciphertext' => Crypt::encryptString(json_encode(['batch' => array_diff_key($batch, ['events' => true]),
                            'event' => $event, 'authenticated_actor_id' => $actor->id], JSON_THROW_ON_ERROR))]);

                $conflict = $this->result($eventId, 'CONFLICT', 'EVENT_ID_REUSED', 'El identificador ya fue utilizado con otro contenido.');
                return $protocol === 2 ? $conflict + ['review_required' => true, 'received_at' => now()->toISOString()] : $conflict;
            }

            return $this->storedReceipt($stored->result);
        }
        $assessment = $protocol === 2 && $event['method'] === 'QR'
            ? $this->evidence->assess($event['qr_token'] ?? '', (int) $event['student_id'], $examId) : null;
        $decision = $this->validateAndRegister($exam, $actor, $distribution, $list, $package, $batch, $event, $student, $captured, $request, $assessment);
        $result = ['event_id' => $eventId] + $decision;
        $review = $decision['status'] === 'CONFLICT';
        if ($protocol === 2) $result += ['review_required' => $review, 'received_at' => CarbonImmutable::parse($stored->received_at)->toISOString()];
        $token = $request->user()->currentAccessToken();
        $original = ['schema_version' => 1, 'protocol_version' => $protocol,
            'exam_id' => $examId, 'package_id' => $package->id, 'list_id' => $batch['list_id'],
            'version' => $batch['version'], 'room_id' => $batch['room_id'], 'event' => $event,
            'authenticated_actor_id' => $actor->id,
            'authenticated_device_id' => $token instanceof PersonalAccessToken ? $token->mobile_device_id : null,
            'server_qr_assessment' => $assessment, 'time_assurance' => 'DEVICE_DECLARED_NOT_ATTESTED'];
        DB::table('exam_offline_sync_events')->where('id', $stored->id)->update([
            'result' => json_encode($result, JSON_THROW_ON_ERROR), 'review_required' => $review,
            'evidence_schema_version' => 1, 'evidence_ciphertext' => Crypt::encryptString(json_encode($original, JSON_THROW_ON_ERROR)),
        ]);
        $this->audit->log($request, $actor->id, 'OFFLINE_EVENT_PROCESSED', 'ExamOfflineSyncEvent', $stored->id, null,
            ['exam_id' => $examId, 'student_id' => (int) $event['student_id'], 'event_id' => $eventId,
                'list_id' => $list->id, 'version' => (int) $batch['version'], 'package_id' => $package->id,
                'captured_at' => $captured->toISOString(), 'received_at' => CarbonImmutable::parse($stored->received_at)->toISOString(),
                'method' => $event['method'], 'device_id' => $event['device_id'] ?? null, 'result' => $decision]);
        if ($review) {
            $this->audit->log($request, $actor->id, 'OFFLINE_EVIDENCE_REVIEW_REQUIRED', 'ExamOfflineSyncEvent', $stored->id,
                null, ['event_id' => $eventId, 'exam_id' => $examId, 'code' => $decision['code'],
                    'evidence_schema_version' => 1, 'time_assurance' => 'DEVICE_DECLARED_NOT_ATTESTED']);
        }

        return $result;
    }

    private function validateAndRegister(Exam $exam, User $actor, ExamOfflineDistribution $distribution,
        ExamOfflineList $list, ExamOfflinePackage $package, array $batch, array $event,
        ?Student $student, CarbonImmutable $captured, Request $request, ?array $assessment): array
    {
        if ((int) ($batch['protocol_version'] ?? 1) === 2 && isset($package->snapshot['mobile'])) {
            $binding = json_decode($package->snapshot['mobile']['manifest_json'], true, flags: JSON_THROW_ON_ERROR)['binding'];
            $credential = $request->user()->currentAccessToken();
            $device = $credential instanceof PersonalAccessToken ? MobileDevice::find($credential->mobile_device_id) : null;
            if (! $device || $device->revoked_at !== null || (int) $device->user_id !== $actor->id
                || (string) $device->id !== $binding['device_id'] || $device->installation_id !== $binding['installation_id']
                || ($event['device_id'] ?? null) !== $binding['installation_id']) {
                return $this->decision('CONFLICT', 'DEVICE_BINDING_MISMATCH', 'La instalación no corresponde al paquete original. Requiere revisión.');
            }
        }
        if ((int) $batch['list_id'] !== $list->id || (int) $batch['version'] !== $distribution->version
            || $package->snapshot['version'] !== $distribution->version) {
            return $this->decision('CONFLICT', 'LIST_VERSION_MISMATCH', 'La lista o su versión no corresponde al paquete.');
        }
        $authorization = $this->access->authorizationHash($exam, $actor);
        if ($authorization === null || ! hash_equals($list->authorization_hash, $authorization)) {
            return $this->decision('CONFLICT', 'AUTHORIZATION_CHANGED', 'La autorización fue revocada o cambió. Requiere revisión.');
        }
        if (! $student || ! DB::table('exam_offline_members')->where('list_id', $list->id)
            ->where('exam_id', $exam->id)->where('student_id', $student->id)->exists()) {
            return $this->decision('REJECTED', 'STUDENT_NOT_ASSIGNED', 'El estudiante no pertenece a esta lista.');
        }
        if ((int) $batch['room_id'] !== $exam->room_id || $package->snapshot['room_id'] !== $exam->room_id) {
            return $this->decision('REJECTED', 'ROOM_MISMATCH', 'El ambiente no corresponde al examen.');
        }
        if (! hash_equals($distribution->exam_hash, $this->access->examHash($exam))
            || ! hash_equals($package->snapshot['exam_hash'], $distribution->exam_hash)) {
            return $this->decision('CONFLICT', 'EXAM_CHANGED', 'El examen cambió desde la preparación.');
        }
        if ($reason = ExamEntryAvailability::reason($exam)) {
            return $this->decision('CONFLICT', $reason, 'El examen no admite ingresos actualmente; la hora local no demuestra el momento de captura.');
        }
        if (now()->greaterThanOrEqualTo($package->expires_at) || $captured->greaterThan(CarbonImmutable::now())
            || $captured->lessThan($package->prepared_at) || $captured->lessThan(ExamEntryAvailability::startsAt($exam))
            || $captured->greaterThanOrEqualTo(ExamEntryAvailability::endsAt($exam))) {
            return $this->decision('CONFLICT', 'CAPTURE_TIME_UNVERIFIABLE', 'El tiempo declarado no es compatible con la vigencia del paquete y el examen.');
        }
        $enrollment = DB::table('enrollments')->where('student_id', $student->id)
            ->where('course_offering_id', $exam->course_offering_id)->lockForUpdate()->first();
        $eligibility = DB::table('exam_eligibilities')->where('student_id', $student->id)
            ->where('exam_id', $exam->id)->lockForUpdate()->first();
        if ($student->status !== 'ACTIVE' || $enrollment?->status !== 'ACTIVE' || $eligibility?->status !== 'ELIGIBLE') {
            return $this->decision('REJECTED', 'ENTRY_CONDITIONS_NOT_MET', 'Se requiere estudiante activo, inscripción activa y habilitación ELIGIBLE.');
        }
        $snapshot = collect($package->snapshot['students'])->firstWhere('student_id', $student->id);
        if (! $snapshot || $snapshot['eligibility_status'] !== $eligibility->status
            || $snapshot['eligibility_revision'] !== (int) $eligibility->revision
            || $snapshot['student_status'] !== $student->status || $snapshot['enrollment_status'] !== $enrollment->status
            || $snapshot['sis_code'] !== $student->sis_code || $snapshot['identity_number'] !== $student->identity_number
            || $snapshot['first_names'] !== $student->first_names || $snapshot['last_names'] !== $student->last_names) {
            return $this->decision('CONFLICT', 'STUDENT_DATA_CHANGED', 'La identificación, inscripción o habilitación cambió desde la descarga.');
        }
        if ($event['method'] === 'QR') {
            if ($assessment !== null && ! $assessment['authentic']) {
                $unavailable = in_array($assessment['code'], ['QR_V3_UNAVAILABLE', 'QR_VERIFICATION_UNAVAILABLE'], true);
                return $this->decision($unavailable ? 'CONFLICT' : 'REJECTED', $assessment['code'],
                    $unavailable ? 'No está disponible la verificación pública requerida; se conserva para revisión.' : 'La evidencia QR no es auténtica o no corresponde al estudiante y examen.');
            }
            $claims = $assessment !== null ? $assessment['claims'] : $this->tokens->decodeAuthentic($event['qr_token'] ?? '');
            if ($claims === null) {
                return $this->decision('REJECTED', 'INVALID_QR', 'La evidencia QR es inválida.');
            }
            $token = StudentQrToken::where('token', $claims['jti'])->lockForUpdate()->first();
            if (! $token || (int) $token->exam_id !== $exam->id || (int) $token->student_id !== $student->id) {
                return $this->decision('REJECTED', 'QR_MISMATCH', 'El QR no corresponde al estudiante y examen.');
            }
            if ($token->status !== 'ACTIVE' || $token->revoked_at !== null) {
                return $this->decision('CONFLICT', 'QR_REVOKED', 'La autorización del QR cambió. Requiere revisión.');
            }
            if ($claims['exp'] <= now()->timestamp) {
                return $this->decision('CONFLICT', 'QR_EXPIRED_UNVERIFIABLE', 'El QR expiró; la hora local no demuestra que se escaneó durante su vigencia.');
            }
            if (($assessment['version'] ?? null) === 3 && ($claims['iat'] > now()->timestamp || $claims['nbf'] > now()->timestamp
                || $captured->timestamp < $claims['iat'] || $captured->timestamp < $claims['nbf'] || $captured->timestamp >= $claims['exp'])) {
                return $this->decision('CONFLICT', 'CAPTURE_TIME_UNVERIFIABLE', 'La evidencia temporal del QR no permite confirmar el ingreso.');
            }
            // StudentQrService writes generated_at as institutional wall time into a timestamp without timezone.
            $generatedAt = CarbonImmutable::parse($token->getRawOriginal('generated_at'), ExamEntryAvailability::TIMEZONE);
            if ($captured->lessThan($generatedAt)) {
                return $this->decision('CONFLICT', 'CAPTURE_TIME_UNVERIFIABLE', 'La captura declarada es anterior a la generación del QR.');
            }
        }
        $entry = DB::table('exam_entries')->where('exam_id', $exam->id)->where('student_id', $student->id)
            ->where('status', 'VALID')->first();
        if ($entry) {
            return $this->decision('DUPLICATE', 'ALREADY_REGISTERED', 'Ya existe un ingreso válido.', (int) $entry->id);
        }
        // Reuse HU13's current blockers, in addition to version/evidence checks specific to offline operation.
        if ($this->control->show($exam, $student->id, ['room_id' => $batch['room_id']])['data']['blockers'] !== []) {
            return $this->decision('REJECTED', 'ENTRY_CONDITIONS_NOT_MET', 'No se cumplen las condiciones vigentes de ingreso.');
        }
        $inserted = DB::table('exam_entries')->insertOrIgnore([
            'exam_id' => $exam->id, 'student_id' => $student->id, 'room_id' => $exam->room_id,
            'verified_by' => $actor->id, 'verification_method' => $event['method'],
            // Official attendance uses server time. The untrusted capture time remains in the event and audit.
            'entered_at' => now(), 'status' => 'VALID', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry = DB::table('exam_entries')->where('exam_id', $exam->id)->where('student_id', $student->id)
            ->where('status', 'VALID')->firstOrFail();

        return $inserted
            ? $this->decision('ACCEPTED', 'ENTRY_REGISTERED', 'Ingreso registrado.', (int) $entry->id)
            : $this->decision('DUPLICATE', 'ALREADY_REGISTERED', 'Ya existe un ingreso válido.', (int) $entry->id);
    }

    private function result(string $eventId, string $status, string $code, string $message): array
    {
        return ['event_id' => $eventId] + $this->decision($status, $code, $message);
    }

    private function storedReceipt(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        // PostgreSQL jsonb reorders object keys. Keep replay serialization stable without changing values.
        $ordered = [];
        foreach (['event_id', 'status', 'code', 'message', 'entry_id', 'review_required', 'received_at'] as $key) {
            if (array_key_exists($key, $decoded)) $ordered[$key] = $decoded[$key];
        }
        return $ordered + $decoded;
    }

    private function decision(string $status, string $code, string $message, ?int $entryId = null): array
    {
        return ['status' => $status, 'code' => $code, 'message' => $message, 'entry_id' => $entryId];
    }
}
