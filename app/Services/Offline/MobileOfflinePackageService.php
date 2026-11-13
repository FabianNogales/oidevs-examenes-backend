<?php

namespace App\Services\Offline;

use App\Models\Exam;
use App\Models\ExamOfflineDistribution;
use App\Models\ExamOfflineList;
use App\Models\ExamOfflinePackage;
use App\Models\MobileDevice;
use App\Models\Student;
use App\Models\User;
use App\Services\Entries\EntryControlService;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Images\DatabaseImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Additive mobile snapshot. Legacy package and HU37 wire contracts stay unchanged. */
class MobileOfflinePackageService
{
    public function __construct(
        private OfflineExamAccess $access,
        private OfflineAssignmentService $assignments,
        private OfflinePackageService $packages,
        private DatabaseImageService $images,
    ) {}

    private function binding(Request $request): array
    {
        $token = $request->user()->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->mobile_device_id) {
            $this->access->fail('MOBILE_TOKEN_REQUIRED', 'Se requiere una credencial móvil vinculada al dispositivo.', 403);
        }
        $device = MobileDevice::whereKey($token->mobile_device_id)->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')->firstOrFail();
        return ['user_id' => (string) $request->user()->id, 'device_id' => (string) $device->id,
            'installation_id' => $device->installation_id];
    }

    public function context(int $examId, Request $request): array
    {
        $this->binding($request);
        $exam = Exam::findOrFail($examId);
        abort_unless($this->access->authorizationHash($exam, $request->user()), 403);
        $owner = $this->access->owner($exam, $request->user());
        $distribution = ExamOfflineDistribution::where('exam_id', $examId)->first();
        $lists = $distribution ? ExamOfflineList::where('distribution_id', $distribution->id)->orderBy('position')
            ->when(! $owner, fn ($q) => $q->where('responsible_id', $request->user()->id))->get() : collect();
        return ['exam' => app(EntryControlService::class)->examData($exam), 'can_prepare' => $owner,
            'unavailable_reason' => ExamEntryAvailability::preparationReason($exam),
            'distribution_id' => $distribution ? (string) $distribution->id : null,
            'version' => $distribution?->version, 'frozen' => $distribution !== null,
            'stale' => $distribution ? $this->assignments->isStale($exam, $distribution) : false,
            'prepared_at' => $distribution?->prepared_at->toISOString(),
            'own_list_id' => ($own = $lists->firstWhere('responsible_id', $request->user()->id)) ? (string) $own->id : null,
            'lists' => $lists->map(function ($list) {
                $members = DB::table('exam_offline_members as m')->join('students as s', 's.id', '=', 'm.student_id')
                    ->where('m.list_id', $list->id)->orderBy('m.position')->pluck('s.last_names');
                return ['list_id' => (string) $list->id, 'responsible_id' => (string) $list->responsible_id,
                    'responsible_name' => User::find($list->responsible_id)?->display_name,
                    'student_count' => $members->count(), 'surname_range' => ['from' => $members->first(), 'to' => $members->last()]];
            })->all()];
    }

    public function download(int $examId, Request $request): array
    {
        return DB::transaction(function () use ($examId, $request) {
            $binding = $this->binding($request);
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            abort_unless($this->access->authorizationHash($exam, $request->user()), 403);
            $distribution = ExamOfflineDistribution::where('exam_id', $examId)->firstOrFail();
            if ($this->assignments->isStale($exam, $distribution)) {
                $this->access->fail('DISTRIBUTION_STALE', 'Los datos de la distribución congelada cambiaron. Requiere revisión del responsable.');
            }
            $legacy = $this->packages->download($examId, $request);
            $refs = [];
            $students = [];
            foreach ($legacy['students'] as $row) {
                $student = Student::with('user')->findOrFail($row['student_id']);
                $photos = [];
                foreach (['profile', 'historical'] as $kind) {
                    $id = $kind === 'profile' ? $student->user?->profile_image_id : $student->historical_image_id;
                    $path = $kind === 'profile' ? $student->user?->profile_photo : $student->historical_photo;
                    if (! $id && ! $path) {
                        $photos[] = ['kind' => $kind, 'state' => 'ABSENT'];
                        continue;
                    }
                    // Missing/corrupt references are errors, never legitimate absence.
                    $metadata = $this->images->metadata($id, $kind, $student->id, $path);
                    $photos[] = [...$metadata, 'kind' => $kind, 'state' => 'PRESENT',
                        'revision' => $kind === 'historical' ? (string) $student->historical_photo_revision : ($id ?? $metadata['sha256']),
                        'path' => 'exams/'.$examId.'/offline/packages/'.$legacy['package_id'].'/photos/'.$student->id.'/'.$kind];
                    $refs[(string) $student->id][$kind] = ['id' => $id, 'legacy_path' => $path];
                }
                $students[] = [...$row, 'student_id' => (string) $row['student_id'], 'photos' => $photos];
            }
            $manifest = ['schema_version' => 1, 'package_id' => $legacy['package_id'], 'exam_id' => (string) $examId,
                'distribution_id' => (string) $distribution->id, 'list_id' => (string) $legacy['list_id'],
                'version' => $legacy['version'], 'exam' => $legacy['exam'], 'binding' => $binding,
                'authorization_id' => $legacy['package_id'], // Opaque reference; NEVER authorization_hash.
                'responsible_name' => $request->user()->display_name, 'prepared_at' => $legacy['prepared_at'],
                'server_time' => now()->toISOString(), 'expires_at' => $legacy['expires_at'], 'timezone' => $legacy['timezone'],
                'room_id' => (string) $legacy['room_id'], 'surname_range' => $legacy['surname_range'],
                'students' => $students, 'qr' => $this->publicQrMaterial()];
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mobile = ['schema_version' => 1, 'manifest_json' => $json, 'manifest_sha256' => hash('sha256', $json)];
            $package = ExamOfflinePackage::findOrFail($legacy['package_id']);
            $package->snapshot = [...$package->snapshot, 'mobile' => $mobile, 'mobile_photo_refs' => $refs];
            $package->save();
            return [...$legacy, 'mobile' => $mobile];
        }, 3);
    }

    /** Reauthorize every resume/photo against actor, device, episode, exam and own list. */
    private function authorized(int $examId, string $packageId, Request $request): array
    {
        $binding = $this->binding($request);
        $exam = Exam::findOrFail($examId);
        $package = ExamOfflinePackage::findOrFail($packageId);
        $list = ExamOfflineList::findOrFail($package->list_id);
        $distribution = ExamOfflineDistribution::findOrFail($list->distribution_id);
        abort_unless((int) $distribution->exam_id === $examId && (int) $list->responsible_id === $request->user()->id
            && (int) $package->downloaded_by === $request->user()->id, 403);
        $authorization = $this->access->authorizationHash($exam, $request->user());
        abort_unless($authorization && hash_equals($list->authorization_hash, $authorization), 403);
        $mobile = $package->snapshot['mobile'] ?? null;
        abort_unless($mobile, 404);
        $manifest = json_decode($mobile['manifest_json'], true, flags: JSON_THROW_ON_ERROR);
        abort_unless($manifest['binding'] === $binding, 403);
        if ($reason = ExamEntryAvailability::preparationReason($exam)) {
            $this->access->fail($reason, 'El paquete ya no permite nuevas descargas.');
        }
        if ($this->assignments->isStale($exam, $distribution)) {
            $this->access->fail('DISTRIBUTION_STALE', 'La distribución requiere revisión.');
        }
        abort_unless(now()->lessThan($package->expires_at), 409);
        return [$package, $manifest];
    }

    public function resume(int $examId, string $packageId, Request $request): array
    {
        [$package] = $this->authorized($examId, $packageId, $request);
        return ['mobile' => $package->snapshot['mobile'], 'server_time' => now()->toISOString()];
    }

    public function photo(int $examId, string $packageId, int $studentId, string $kind, Request $request): StreamedResponse
    {
        [$package, $manifest] = $this->authorized($examId, $packageId, $request);
        abort_unless(in_array($kind, ['profile', 'historical'], true), 404);
        abort_unless(DB::table('exam_offline_members')->where('list_id', $package->list_id)->where('student_id', $studentId)->exists(), 403);
        $student = collect($manifest['students'])->firstWhere('student_id', (string) $studentId);
        $photo = collect($student['photos'] ?? [])->firstWhere('kind', $kind);
        $ref = $package->snapshot['mobile_photo_refs'][(string) $studentId][$kind] ?? null;
        abort_unless($ref && $photo && $photo['state'] === 'PRESENT', 404);
        $image = $this->images->read($ref['id'], $kind, $studentId, $ref['legacy_path']);
        if (! hash_equals($photo['sha256'], $image['hash']) || strlen($image['bytes']) !== $photo['size'] || $image['mime'] !== $photo['mime']) {
            $this->access->fail('PHOTO_CHANGED', 'La fotografía cambió. Descarga un snapshot nuevo.');
        }
        return response()->stream(static function () use ($image) { echo $image['bytes']; }, 200,
            ['Content-Type' => $image['mime'], 'Content-Length' => (string) strlen($image['bytes']),
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function publicQrMaterial(): array
    {
        $unavailable = ['available' => false, 'reason' => 'PRODUCTION_QR_V3_NOT_CONFIRMED',
            'issuer' => null, 'audience' => null, 'keys' => [], 'revoked_jtis' => null];
        if (! config('mobile_offline.qr_v3_issued') || ! config('qr_v3.enabled')) return $unavailable;
        $path = config('qr_v3.public_key_path');
        $pem = is_string($path) && is_readable($path) ? file_get_contents($path) : false;
        // Only an explicit PUBLIC KEY PEM. Never load the private key or APP_KEY.
        if (! is_string($pem) || ! str_starts_with(trim($pem), '-----BEGIN PUBLIC KEY-----')) return $unavailable;
        $key = openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        $kid = config('qr_v3.kid');
        if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048
            || ! is_string($kid) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $kid)
            || ! config('qr_v3.issuer') || ! config('qr_v3.audience')) return $unavailable;
        return ['available' => true, 'reason' => null, 'issuer' => config('qr_v3.issuer'), 'audience' => config('qr_v3.audience'),
            'keys' => [['kid' => $kid, 'algorithm' => 'RS256', 'pem' => $details['key']]],
            'revoked_jtis' => null]; // No revocation feed exists: null means UNKNOWN, never an empty guarantee.
    }
}
