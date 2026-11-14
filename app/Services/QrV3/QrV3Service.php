<?php

namespace App\Services\QrV3;

class QrV3Service
{
    public const TYPE = 'eida-entry-qr+jwt';

    private const CLAIMS = ['v', 'jti', 'sub', 'exam_id', 'iss', 'aud', 'iat', 'nbf', 'exp'];

    private const MAX_TIMESTAMP = 253402300799;

    public function __construct(private QrV3KeyRing $keys, private string $issuer,
        private string $audience, private int $maxLifetimeSeconds = 172800)
    {
        if ($issuer === '' || $audience === '' || strlen($issuer) > 200 || strlen($audience) > 100
            || $maxLifetimeSeconds < 1 || $maxLifetimeSeconds > 172800) {
            throw new QrV3Exception('CONFIGURATION_INVALID');
        }
    }

    public function publicSigningMaterial(int $availableFrom, int $expiresAt, bool $checkSignature = false): array
    {
        if ($availableFrom < 0 || $expiresAt > self::MAX_TIMESTAMP || $expiresAt <= $availableFrom
            || $expiresAt - $availableFrom > $this->maxLifetimeSeconds) {
            throw new QrV3Exception('EXAM_WINDOW_UNSUPPORTED');
        }

        $private = $this->keys->signingKey();
        $kid = $this->keys->signingKid();
        $public = $this->keys->verificationKey($kid);
        $details = openssl_pkey_get_details($public);
        // Bound the size of the production QR and verify JSON is encodable before publishing.
        if (! $details || $details['bits'] > 4096
            || ! preg_match('/^[\x21-\x7e]+$/D', $this->issuer) || ! preg_match('/^[\x21-\x7e]+$/D', $this->audience)) {
            throw new QrV3Exception('CONFIGURATION_INVALID');
        }
        if ($checkSignature) {
            $probe = 'EIDA QR v3 signing readiness';
            if (! @openssl_sign($probe, $signature, $private, OPENSSL_ALGO_SHA256)
                || openssl_verify($probe, $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
                throw new QrV3Exception('SIGNING_FAILED');
            }
        }

        return [
            'issuer' => $this->issuer,
            'audience' => $this->audience,
            'keys' => [['kid' => $kid, 'algorithm' => 'RS256', 'pem' => $details['key']]],
        ];
    }

    public function issue(string $jti, int $studentId, int $examId, int $issuedAt, int $notBefore, int $expiresAt): string
    {
        $claims = ['v' => 3, 'jti' => $jti, 'sub' => (string) $studentId, 'exam_id' => (string) $examId,
            'iss' => $this->issuer, 'aud' => $this->audience, 'iat' => $issuedAt, 'nbf' => $notBefore, 'exp' => $expiresAt];
        $this->validateClaims($claims);
        $header = ['alg' => 'RS256', 'kid' => $this->keys->signingKid(), 'typ' => self::TYPE];
        $input = $this->encode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)).'.'
            .$this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (! @openssl_sign($input, $signature, $this->keys->signingKey(), OPENSSL_ALGO_SHA256)) {
            throw new QrV3Exception('SIGNING_FAILED');
        }

        return $input.'.'.$this->encode($signature);
    }

    public function verify(string $token, int $expectedStudentId, int $expectedExamId, int $now): array
    {
        $claims = $this->verifyAuthentic($token, $expectedStudentId, $expectedExamId);
        $this->ensureCurrent($claims, $now);

        return $claims;
    }

    /** Online callers must bind these claims to the token registry before registering an entry. */
    public function verifyCurrent(string $token, int $now): array
    {
        $claims = $this->authenticate($token);
        $this->ensureCurrent($claims, $now);

        return $claims;
    }

    private function ensureCurrent(array $claims, int $now): void
    {
        if ($now < 0 || $claims['iat'] > $now || $claims['nbf'] > $now || $claims['exp'] <= $now) {
            throw new QrV3Exception('TIME_INVALID');
        }
    }

    /** Authenticity only: callers MUST classify temporal validity; never authorizes an entry alone. */
    public function verifyAuthentic(string $token, int $expectedStudentId, int $expectedExamId): array
    {
        $claims = $this->authenticate($token);
        if ($claims['sub'] !== (string) $expectedStudentId || $claims['exam_id'] !== (string) $expectedExamId) {
            throw new QrV3Exception('SUBJECT_EXAM_MISMATCH');
        }

        return $claims;
    }

    private function authenticate(string $token): array
    {
        if (strlen($token) > 4096 || count($parts = explode('.', $token)) !== 3) {
            throw new QrV3Exception('FORMAT_INVALID');
        }
        $header = $this->jsonObject($this->decode($parts[0]));
        if (! $this->exactKeys($header, ['alg', 'kid', 'typ']) || ($header['alg'] ?? null) !== 'RS256'
            || ($header['typ'] ?? null) !== self::TYPE || ! is_string($header['kid'] ?? null)
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $header['kid'])) {
            throw new QrV3Exception('HEADER_INVALID');
        }
        $key = $this->keys->verificationKey($header['kid']);
        $signature = $this->decode($parts[2]);
        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new QrV3Exception('SIGNATURE_INVALID');
        }
        $claims = $this->jsonObject($this->decode($parts[1]));
        $this->validateClaims($claims);

        return $claims;
    }

    private function validateClaims(array $claims): void
    {
        if (! $this->exactKeys($claims, self::CLAIMS) || ($claims['v'] ?? null) !== 3
            || ! is_string($claims['jti'] ?? null)
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $claims['jti'])
            || ! $this->identifier($claims['sub'] ?? null) || ! $this->identifier($claims['exam_id'] ?? null)
            || ($claims['iss'] ?? null) !== $this->issuer || ($claims['aud'] ?? null) !== $this->audience) {
            throw new QrV3Exception('CLAIMS_INVALID');
        }
        foreach (['iat', 'nbf', 'exp'] as $name) {
            if (! is_int($claims[$name] ?? null) || $claims[$name] < 0 || $claims[$name] > self::MAX_TIMESTAMP) {
                throw new QrV3Exception('CLAIMS_INVALID');
            }
        }
        if ($claims['nbf'] < $claims['iat'] || $claims['exp'] <= $claims['nbf']
            || $this->maxLifetimeSeconds < $claims['exp'] - $claims['iat']) {
            throw new QrV3Exception('CLAIMS_INVALID');
        }
    }

    private function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value)
            && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0);
    }

    private function exactKeys(array $values, array $expected): bool
    {
        return count($values) === count($expected) && array_diff($expected, array_keys($values)) === [];
    }

    private function jsonObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            // Compact emitter encoding also rejects duplicate keys, whitespace tricks
            // and alternate encodings. This narrow profile is documented in the vectors.
            if (! is_array($decoded) || array_is_list($decoded)
                || json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== $json) {
                throw new QrV3Exception('FORMAT_INVALID');
            }

            return $decoded;
        } catch (\JsonException) {
            throw new QrV3Exception('FORMAT_INVALID');
        }
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decode(string $part): string
    {
        if ($part === '' || ! preg_match('/^[A-Za-z0-9_-]+$/D', $part) || strlen($part) % 4 === 1) {
            throw new QrV3Exception('FORMAT_INVALID');
        }
        $bytes = base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        if ($bytes === false || $this->encode($bytes) !== $part) {
            throw new QrV3Exception('FORMAT_INVALID');
        }

        return $bytes;
    }
}
