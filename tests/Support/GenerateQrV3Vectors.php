<?php

// CLI-only interoperability fixture generator. Does not bootstrap Laravel or read .env.
// The ephemeral private key stays in memory and is never exported.
require __DIR__.'/../../vendor/autoload.php';

use App\Services\QrV3\QrV3KeyRing;
use App\Services\QrV3\QrV3Service;
use Tests\Support\QrV3TestKeys;

$key = QrV3TestKeys::rsa();
if (! $key) {
    throw new RuntimeException('RSA key generation failed.');
}
$public = openssl_pkey_get_details($key)['key'];
$kid = 'interop-rsa-2026-01';
$issuer = 'https://eida.example.test';
$audience = 'eida-control-qr';
$clock = 1893456000;
$service = new QrV3Service(new QrV3KeyRing([$kid => $public], $kid, $key), $issuer, $audience);
$token = $service->issue('f1cf8459-ae26-4def-9a24-294629314c17', 101, 42, $clock, $clock, $clock + 3600);
$encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$parts = explode('.', $token);
$claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
$header = ['alg' => 'RS256', 'kid' => $kid, 'typ' => QrV3Service::TYPE];
$changed = $claims;
$changed['exam_id'] = '43';
$tamperedPayload = $parts[0].'.'.$encode(json_encode($changed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)).'.'.$parts[2];
$signature = base64_decode(strtr($parts[2], '-_', '+/'));
$signature[0] = chr(ord($signature[0]) ^ 1);
$badSignature = $parts[0].'.'.$parts[1].'.'.$encode($signature);
$alterHeader = function (array $newHeader) use ($encode, $parts): string {
    return $encode(json_encode($newHeader, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)).'.'.$parts[1].'.'.$parts[2];
};
$cases = [];
$add = function (string $name, string $value, ?string $reason = null, int $student = 101, int $exam = 42, ?int $now = null) use (&$cases, $clock): void {
    $cases[] = ['name' => $name, 'token' => $value, 'student_id' => $student, 'exam_id' => $exam,
        'now' => $now ?? $clock + 60, 'valid' => $reason === null, 'reason' => $reason];
};
$add('valid', $token);
$add('tampered-payload', $tamperedPayload, 'SIGNATURE_INVALID');
$add('tampered-signature', $badSignature, 'SIGNATURE_INVALID');
$add('wrong-algorithm', $alterHeader(array_replace($header, ['alg' => 'HS256'])), 'HEADER_INVALID');
$add('unknown-kid', $alterHeader(array_replace($header, ['kid' => 'unknown'])), 'UNKNOWN_KID');
$add('unexpected-header', $alterHeader($header + ['jku' => 'https://untrusted.example.test']), 'HEADER_INVALID');
$add('wrong-student', $token, 'SUBJECT_EXAM_MISMATCH', 102);
$add('wrong-exam', $token, 'SUBJECT_EXAM_MISMATCH', 101, 43);
$add('expired-at-boundary', $token, 'TIME_INVALID', 101, 42, $clock + 3600);
$add('not-yet-valid', $token, 'TIME_INVALID', 101, 42, $clock - 1);
$output = ['format' => 'EIDA-QR-v3-RS256-interoperability-v1',
    'warning' => 'Synthetic public test material. Not a production credential. No private key is retained.',
    'algorithm' => 'RSASSA-PKCS1-v1_5 with SHA-256', 'kid' => $kid, 'issuer' => $issuer, 'audience' => $audience,
    'public_key_pem' => $public, 'public_key_sha256' => hash('sha256', $public),
    'signing_input' => $parts[0].'.'.$parts[1], 'claims' => $claims, 'cases' => $cases];
$directory = __DIR__.'/../Fixtures/qr-v3';
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create public fixture directory.');
}
file_put_contents($directory.'/rs256-vectors.json', json_encode($output,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo 'Wrote '.count($cases)." public QR v3 cases; private key was not exported.\n";
