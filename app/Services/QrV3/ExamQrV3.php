<?php

namespace App\Services\QrV3;

use App\Models\Exam;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Offline\OfflineSyncService;
use Carbon\CarbonInterface;

class ExamQrV3
{
    private const UNAVAILABLE = [
        'available' => false,
        'reason' => 'PRODUCTION_QR_V3_NOT_CONFIRMED',
        'issuer' => null,
        'audience' => null,
        'keys' => [],
        'revoked_jtis' => null,
    ];

    public function __construct(private QrV3Factory $factory) {}

    public function issue(Exam $exam, int $studentId, string $jti, CarbonInterface $now): string
    {
        $service = $this->factory->make();
        [$availableFrom, $expiresAt] = $this->window($exam);
        $service->publicSigningMaterial($availableFrom, $expiresAt);

        return $service->issue($jti, $studentId, $exam->id, $now->timestamp, $now->timestamp, $expiresAt);
    }

    public function manifest(Exam $exam): array
    {
        if (config('mobile_offline.qr_v3_issued') !== true || config('offline_sync.qr_v3_verify_enabled') !== true
            || config('qr_v3.enabled') !== true || ! OfflineSyncService::hasEvidenceSchema()) {
            return self::UNAVAILABLE;
        }

        try {
            $service = $this->factory->make();
            [$availableFrom, $expiresAt] = $this->window($exam);

            return [
                'available' => true,
                'reason' => null,
                ...$service->publicSigningMaterial($availableFrom, $expiresAt, checkSignature: true),
                'revoked_jtis' => null, // No revocation feed is available for offline devices.
            ];
        } catch (QrV3Exception) {
            return self::UNAVAILABLE;
        }
    }

    private function window(Exam $exam): array
    {
        return [
            ExamEntryAvailability::startsAt($exam)->subHours(24)->timestamp,
            ExamEntryAvailability::endsAt($exam)->timestamp,
        ];
    }
}
