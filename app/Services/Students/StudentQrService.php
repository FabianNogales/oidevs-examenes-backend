<?php

namespace App\Services\Students;

use App\Models\StudentQrToken;
use App\Models\Subject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Exception;

class StudentQrService
{
    public function getOrGenerateForSubject(int $studentId, int $subjectId): StudentQrToken
    {
        // 1. Validar que la materia exista
        $subject = Subject::findOrFail($subjectId);

        // 2. Buscar token activo generado hace menos de 24 horas
        $existingToken = StudentQrToken::where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('generated_at', '>=', now()->subHours(24))
            ->first();

        if ($existingToken) {
            $generatedTimestamp = Carbon::parse($existingToken->generated_at)->timestamp;

            $existingToken->signed_qr_token = $this->generateSignedToken(
                $studentId, 
                $subjectId, 
                $existingToken->token, 
                $generatedTimestamp + (24 * 3600)
            );
            return $existingToken;
        }

        // 3. Revocar tokens previos del estudiante para esta materia
        StudentQrToken::where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('status', 'ACTIVE')
            ->update([
                'status'     => 'REVOKED',
                'revoked_at' => now(),
            ]);

        // 4. Generar UUID para columna PostgreSQL (tipo uuid)
        $uuidToken = (string) Str::uuid();
        $expiresAtTimestamp = now()->addHours(24)->timestamp;

        // 5. Generar token firmado (HMAC-SHA256)
        $signedToken = $this->generateSignedToken($studentId, $subjectId, $uuidToken, $expiresAtTimestamp);

        // 6. Guardar en PostgreSQL
        $qrRecord = StudentQrToken::create([
            'student_id'   => $studentId,
            'subject_id'   => $subjectId,
            'token'        => $uuidToken,
            'status'       => 'ACTIVE',
            'generated_at' => now(),
        ]);

        $qrRecord->signed_qr_token = $signedToken;

        return $qrRecord;
    }

    private function generateSignedToken(int $studentId, int $subjectId, string $uuid, int $expTimestamp): string
    {
        $payloadData = [
            'std' => $studentId,
            'sub' => $subjectId,
            'exp' => $expTimestamp,
            'jti' => $uuid,
        ];

        $payloadJson = json_encode($payloadData);
        $signature   = hash_hmac('sha256', $payloadJson, config('app.key'));

        return base64_encode($payloadJson) . '.' . $signature;
    }
}