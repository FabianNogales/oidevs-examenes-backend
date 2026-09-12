<?php

namespace App\Services\Students;

use App\Models\Exam;
use App\Models\StudentQrToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Exception;

class StudentQrService
{
    /**
     * Obtiene los exámenes disponibles para el estudiante junto con su QR (si está en ventana de 24h).
     */
    public function getExamsForStudent(int $studentId): Collection
    {
        return Exam::with(['courseOffering.subject'])
            ->where('status', 'ACTIVE')
            ->whereHas('courseOffering.enrollments', function ($query) use ($studentId) {
                $query->where('student_id', $studentId)
                    ->where('status', 'ACTIVE');
            })
            ->get()
            ->map(function ($exam) use ($studentId) {
                $examDateStr = $exam->exam_date instanceof Carbon 
                    ? $exam->exam_date->format('Y-m-d') 
                    : substr((string)$exam->exam_date, 0, 10);

                $examStart = Carbon::parse("{$examDateStr} {$exam->start_time}");
                
                // Regla de 24 horas: Habilitado desde 24h antes hasta que finalice el examen
                $isAvailable = now()->gte($examStart->copy()->subHours(24)) 
                    && now()->lte($examStart->copy()->addMinutes($exam->duration_minutes));

                $qrData = null;
                if ($isAvailable) {
                    try {
                        $qrData = $this->getOrGenerateForExam($studentId, $exam->id);
                    } catch (Exception $e) {
                        $qrData = null;
                    }
                }

                return [
                    'exam_id'         => $exam->id,
                    'subject'         => $exam->courseOffering->subject->name ?? 'N/A',
                    'exam_title'      => $exam->name,
                    'scheduled_at'    => $examStart->toDateTimeString(),
                    'is_qr_available' => $isAvailable,
                    'qr_code_base64'  => $qrData['qr_code_base64'] ?? null,
                    'token'           => $qrData['token'] ?? null,
                ];
            });
    }

    /**
     * Genera u obtiene el QR del examen si faltan <= 24 horas.
     */
    public function getOrGenerateForExam(int $studentId, int $examId): array
    {
        $exam = Exam::with(['courseOffering.subject'])->findOrFail($examId);

        // 1. Validar regla de 24 horas previas
        $examDateStr = $exam->exam_date instanceof Carbon 
            ? $exam->exam_date->format('Y-m-d') 
            : substr((string)$exam->exam_date, 0, 10);

        $examStart = Carbon::parse("{$examDateStr} {$exam->start_time}");
        $now = now();
        $availableFrom = $examStart->copy()->subHours(24);

        if ($now->lt($availableFrom)) {
            throw new Exception("El código QR aún no está disponible. Se habilitará 24 horas antes del examen.");
        }

        if ($now->gt($examStart->copy()->addMinutes($exam->duration_minutes))) {
            throw new Exception("El examen ya ha finalizado.");
        }

        // 2. Buscar token activo existente
        $existingToken = StudentQrToken::where('student_id', $studentId)
            ->where('exam_id', $examId)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->first();

        $tokenValue = $existingToken ? $existingToken->token : (string) Str::uuid();

        if (!$existingToken) {
            // Revocar previos y crear uno nuevo
            StudentQrToken::where('student_id', $studentId)
                ->where('exam_id', $examId)
                ->update(['status' => 'REVOKED', 'revoked_at' => $now]);

            $existingToken = StudentQrToken::create([
                'student_id'   => $studentId,
                'exam_id'      => $examId,
                'token'        => $tokenValue,
                'status'       => 'ACTIVE',
                'generated_at' => $now,
            ]);
        }

        // 3. Generar JWT/Hash firmado
        $expTimestamp = $examStart->timestamp;
        $signedToken = $this->generateSignedToken($studentId, $examId, $tokenValue, $expTimestamp);

        // 4. Formatear Base64 SVG simulación de QR
        $qrBase64 = "data:image/svg+xml;base64," . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="100%" height="100%" fill="#eee"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="12">QR EXAM #'.$examId.'</text></svg>'
        );

        return [
            'exam_id'        => $exam->id,
            'subject'        => $exam->courseOffering->subject->name ?? 'N/A',
            'exam_title'     => $exam->name,
            'scheduled_at'   => $examStart->toDateTimeString(),
            'qr_code_base64' => $qrBase64,
            'token'          => $signedToken,
        ];
    }

    private function generateSignedToken(int $studentId, int $examId, string $uuid, int $expTimestamp): string
    {
        $payloadData = [
            'std' => $studentId,
            'exm' => $examId,
            'exp' => $expTimestamp,
            'jti' => $uuid,
        ];

        $payloadJson = json_encode($payloadData);
        $signature   = hash_hmac('sha256', $payloadJson, config('app.key'));

        return base64_encode($payloadJson) . '.' . $signature;
    }
}