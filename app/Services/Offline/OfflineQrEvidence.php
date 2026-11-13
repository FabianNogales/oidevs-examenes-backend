<?php

namespace App\Services\Offline;

use App\Services\Entries\QrTokenValidator;
use App\Services\QrV3\QrV3Exception;
use App\Services\QrV3\QrV3KeyRing;
use App\Services\QrV3\QrV3Service;

class OfflineQrEvidence
{
    public function __construct(private QrTokenValidator $v2) {}

    /** No private keys, no client-supplied trust roots and no trust in a phone's verdict. */
    public function assess(string $token, int $studentId, int $examId): array
    {
        if ($claims = $this->v2->decodeAuthentic($token)) {
            return ['authentic' => true, 'version' => 2, 'claims' => $claims, 'code' => null];
        }
        $parts = explode('.', $token);
        $header = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true) : null;
        // Header is used for routing only. QrV3Service independently validates the complete signed token.
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') return $this->invalid('INVALID_QR');
        if (! config('offline_sync.qr_v3_verify_enabled')) return $this->invalid('QR_V3_UNAVAILABLE');
        try {
            $service = $this->verifier();
        } catch (QrV3Exception) {
            return $this->invalid('QR_VERIFICATION_UNAVAILABLE');
        }
        try {
            $claims = $service->verifyAuthentic($token, $studentId, $examId);
            return ['authentic' => true, 'version' => 3, 'claims' => $claims, 'code' => null];
        } catch (QrV3Exception $e) {
            return $this->invalid($e->reason === 'SUBJECT_EXAM_MISMATCH' ? 'QR_MISMATCH' : 'INVALID_QR');
        }
    }

    private function verifier(): QrV3Service
    {
        $path = realpath((string) config('qr_v3.public_key_path'));
        if (! $path || ! is_file($path) || ! is_readable($path)) throw new QrV3Exception('KEY_CONFIGURATION_INVALID');
        $pem = file_get_contents($path);
        $ring = new QrV3KeyRing([(string) config('qr_v3.kid') => $pem]);
        return new QrV3Service($ring, (string) config('qr_v3.issuer'), (string) config('qr_v3.audience'),
            (int) config('qr_v3.max_lifetime_seconds'));
    }

    public function supportsV3(): bool
    {
        if (! config('offline_sync.qr_v3_verify_enabled')) return false;
        try { $this->verifier(); return true; } catch (QrV3Exception) { return false; }
    }

    private function invalid(string $code): array
    {
        return ['authentic' => false, 'version' => null, 'claims' => null, 'code' => $code];
    }
}
