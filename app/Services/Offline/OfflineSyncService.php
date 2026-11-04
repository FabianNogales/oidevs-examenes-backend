<?php

namespace App\Services\Offline;

use App\Models\Exam;
use App\Models\ExamOfflineDistribution;
use App\Models\ExamOfflineList;
use App\Models\ExamOfflinePackage;
use App\Models\Student;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Entries\EntryControlService;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Entries\QrTokenValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineSyncService
{
    public function __construct(private OfflineExamAccess $access, private AuditLogService $audit,
        private EntryControlService $control, private QrTokenValidator $tokens) {}

    public function sync(int $examId, array $batch, Request $request): array
    {
        $results = [];
        foreach ($batch['events'] as $event) {
            // A committed result survives a connection failure or a later event's infrastructure failure.
            $results[] = DB::transaction(fn () => $this->process($examId, $batch, $event, $request), 3);
        }

        return ['exam_id' => $examId, 'results' => $results];
    }

    private function process(int $examId, array $batch, array $event, Request $request): array
    {
        // Same first lock as HU13/HU14, collaboration changes and exam edits/cancellation.
        $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
        $actor = User::whereKey($request->user()->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
        abort_unless($actor->isActive(), 403);
        $package = ExamOfflinePackage::find(strtolower($batch['package_id']));
        abort_unless($package && (int) $package->downloaded_by === $actor->id, 403, 'Paquete no disponible para este usuario.');
        $list = ExamOfflineList::findOrFail($package->list_id);
        $distribution = ExamOfflineDistribution::findOrFail($list->distribution_id);
        abort_unless((int) $distribution->exam_id === $examId && (int) $list->responsible_id === $actor->id,
            403, 'El paquete no corresponde al examen y responsable autenticado.');

        $eventId = strtolower($event['event_id']);
        $captured = CarbonImmutable::parse($event['captured_at']);
        $payloadHash = hash('sha256', json_encode([
            'exam_id' => $examId, 'list_id' => (int) $batch['list_id'], 'version' => (int) $batch['version'],
            'package_id' => $package->id, 'room_id' => (int) $batch['room_id'], 'actor_id' => $actor->id,
            'student_id' => (int) $event['student_id'], 'method' => $event['method'],
            'captured_at' => $captured->toISOString(), 'device_id' => $event['device_id'] ?? null,
            'qr_token' => $event['qr_token'] ?? null,
        ], JSON_THROW_ON_ERROR));
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
                        'status' => 'CONFLICT', 'code' => 'EVENT_ID_REUSED']);

                return $this->result($eventId, 'CONFLICT', 'EVENT_ID_REUSED', 'El identificador ya fue utilizado con otro contenido.');
            }

            return json_decode($stored->result, true, flags: JSON_THROW_ON_ERROR);
        }
        $decision = $this->validateAndRegister($exam, $actor, $distribution, $list, $package, $batch, $event, $student, $captured);
        $result = ['event_id' => $eventId] + $decision;
        DB::table('exam_offline_sync_events')->where('id', $stored->id)->update(['result' => json_encode($result, JSON_THROW_ON_ERROR)]);
        $this->audit->log($request, $actor->id, 'OFFLINE_EVENT_PROCESSED', 'ExamOfflineSyncEvent', $stored->id, null,
            ['exam_id' => $examId, 'student_id' => (int) $event['student_id'], 'event_id' => $eventId,
                'list_id' => $list->id, 'version' => (int) $batch['version'], 'package_id' => $package->id,
                'captured_at' => $captured->toISOString(), 'received_at' => CarbonImmutable::parse($stored->received_at)->toISOString(),
                'method' => $event['method'], 'device_id' => $event['device_id'] ?? null, 'result' => $decision]);

        return $result;
    }

    private function validateAndRegister(Exam $exam, User $actor, ExamOfflineDistribution $distribution,
        ExamOfflineList $list, ExamOfflinePackage $package, array $batch, array $event,
        ?Student $student, CarbonImmutable $captured): array
    {
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
            $claims = $this->tokens->decodeAuthentic($event['qr_token'] ?? '');
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

    private function decision(string $status, string $code, string $message, ?int $entryId = null): array
    {
        return ['status' => $status, 'code' => $code, 'message' => $message, 'entry_id' => $entryId];
    }
}
