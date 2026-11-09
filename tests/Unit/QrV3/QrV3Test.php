<?php

namespace Tests\Unit\QrV3;

use App\Services\QrV3\QrV3Exception;
use App\Services\QrV3\QrV3KeyRing;
use App\Services\QrV3\QrV3Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\QrV3TestKeys;

class QrV3Test extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/qr-v3/rs256-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function vectors(): array
    {
        $cases = [];
        foreach (self::fixture()['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    #[DataProvider('vectors')]
    public function test_public_interoperability_vectors(array $case): void
    {
        $fixture = self::fixture();
        $verifier = new QrV3Service(new QrV3KeyRing([$fixture['kid'] => $fixture['public_key_pem']]),
            $fixture['issuer'], $fixture['audience']);
        if (! $case['valid']) {
            $this->expectException(QrV3Exception::class);
            $this->expectExceptionMessage($case['reason']);
        }
        $claims = $verifier->verify($case['token'], $case['student_id'], $case['exam_id'], $case['now']);
        $this->assertSame($fixture['claims'], $claims);
    }

    public function test_ephemeral_private_key_emits_a_token_verifiable_with_public_key_only(): void
    {
        $private = QrV3TestKeys::rsa();
        $public = openssl_pkey_get_details($private)['key'];
        $issuer = new QrV3Service(new QrV3KeyRing(['temporary' => $public], 'temporary', $private), 'issuer', 'audience');
        $verifier = new QrV3Service(new QrV3KeyRing(['temporary' => $public]), 'issuer', 'audience');
        $token = $issuer->issue('f1cf8459-ae26-4def-9a24-294629314c17', 101, 42, 1000, 1000, 2000);
        $claims = $verifier->verify($token, 101, 42, 1500);
        $this->assertSame(['v', 'jti', 'sub', 'exam_id', 'iss', 'aud', 'iat', 'nbf', 'exp'], array_keys($claims));
        $this->assertSame('101', $claims['sub']);
        $this->assertSame('42', $claims['exam_id']);
    }

    public function test_wrong_issuer_and_audience_are_rejected_even_with_valid_signature(): void
    {
        $fixture = self::fixture();
        foreach ([['wrong', $fixture['audience']], [$fixture['issuer'], 'wrong']] as [$issuer, $audience]) {
            $verifier = new QrV3Service(new QrV3KeyRing([$fixture['kid'] => $fixture['public_key_pem']]), $issuer, $audience);
            try {
                $verifier->verify($fixture['cases'][0]['token'], 101, 42, 1893456060);
                $this->fail('Unexpected accepted issuer/audience.');
            } catch (QrV3Exception $exception) {
                $this->assertSame('CLAIMS_INVALID', $exception->reason);
            }
        }
    }

    public function test_validly_signed_extra_claims_duplicate_keys_and_header_fields_are_rejected(): void
    {
        $private = QrV3TestKeys::rsa();
        $public = openssl_pkey_get_details($private)['key'];
        $service = new QrV3Service(new QrV3KeyRing(['test' => $public], 'test', $private), 'issuer', 'audience');
        $valid = $service->issue('f1cf8459-ae26-4def-9a24-294629314c17', 101, 42, 1000, 1000, 2000);
        [$h, $p] = explode('.', $valid);
        $decode = fn ($part) => base64_decode(strtr($part, '-_', '+/'));
        $header = json_decode($decode($h), true);
        $claims = json_decode($decode($p), true);
        $headers = json_encode($header, JSON_UNESCAPED_SLASHES);
        $payload = json_encode($claims, JSON_UNESCAPED_SLASHES);
        foreach ([
            [$headers, json_encode($claims + ['identity_number' => 'forbidden'])],
            ['{"alg":"RS256","alg":"RS256","kid":"test","typ":"eida-entry-qr+jwt"}', $payload],
            [json_encode($header + ['crit' => ['other']]), $payload],
            [$headers, str_replace('"v":3', '"v":3,"v":3', $payload)],
            [$headers, json_encode(array_replace($claims, ['exp' => '2000']))],
        ] as [$rawHeader, $rawPayload]) {
            $encode = fn ($bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            $input = $encode($rawHeader).'.'.$encode($rawPayload);
            openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256);
            try {
                $service->verify($input.'.'.$encode($signature), 101, 42, 1500);
                $this->fail('Unexpected accepted malformed signed token.');
            } catch (QrV3Exception $exception) {
                $this->assertContains($exception->reason, ['HEADER_INVALID', 'CLAIMS_INVALID', 'FORMAT_INVALID']);
            }
        }
    }

    public function test_public_only_key_cannot_sign_and_weak_rsa_key_is_rejected(): void
    {
        $fixture = self::fixture();
        $ring = new QrV3KeyRing([$fixture['kid'] => $fixture['public_key_pem']]);
        try {
            $ring->signingKey();
            $this->fail('Public key must not sign.');
        } catch (QrV3Exception $exception) {
            $this->assertSame('SIGNING_DISABLED', $exception->reason);
        }
        $weak = QrV3TestKeys::rsa(1024);
        $this->expectException(QrV3Exception::class);
        $this->expectExceptionMessage('KEY_CONFIGURATION_INVALID');
        new QrV3KeyRing(['weak' => openssl_pkey_get_details($weak)['key']]);
    }

    public function test_wrong_private_key_and_malformed_base64_are_rejected(): void
    {
        $fixture = self::fixture();
        $key = QrV3TestKeys::rsa();
        try {
            new QrV3KeyRing([$fixture['kid'] => $fixture['public_key_pem']], $fixture['kid'], $key);
            $this->fail('Mismatched private key.');
        } catch (QrV3Exception $exception) {
            $this->assertSame('KEY_CONFIGURATION_INVALID', $exception->reason);
        }
        $service = new QrV3Service(new QrV3KeyRing([$fixture['kid'] => $fixture['public_key_pem']]), $fixture['issuer'], $fixture['audience']);
        $this->expectException(QrV3Exception::class);
        $service->verify($fixture['cases'][0]['token'].'=', 101, 42, 1893456060);
    }
}
