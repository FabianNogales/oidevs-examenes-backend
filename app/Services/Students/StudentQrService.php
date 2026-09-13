<?php

namespace App\Services\Students;

use App\Models\Exam;
use App\Models\StudentQrToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
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
                    ->where('status', 'ACTIVE')
                    ->whereHas('student', function ($sQuery) {
                        $sQuery->where('status', 'ACTIVE');
                    });
            })
            ->get()
            ->map(function ($exam) use ($studentId) {
                $examDateStr = $exam->exam_date instanceof Carbon 
                    ? $exam->exam_date->format('Y-m-d') 
                    : substr((string)$exam->exam_date, 0, 10);

                $examStart = Carbon::parse("{$examDateStr} {$exam->start_time}");
                $examEnd   = $examStart->copy()->addMinutes($exam->duration_minutes);
                
                $isAvailable = now()->gte($examStart->copy()->subHours(24)) 
                    && now()->lte($examEnd);

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
     * Genera u obtiene el QR del examen si el estudiante y la inscripción están en status ACTIVE.
     */
    public function getOrGenerateForExam(int $studentId, int $examId): array
    {
        // 1. Validar que el examen exista y que el estudiante esté inscrito y activo
        $exam = Exam::with(['courseOffering.subject'])
            ->where('status', 'ACTIVE')
            ->whereHas('courseOffering.enrollments', function ($query) use ($studentId) {
                $query->where('student_id', $studentId)
                    ->where('status', 'ACTIVE')
                    ->whereHas('student', function ($sQuery) {
                        $sQuery->where('status', 'ACTIVE');
                    });
            })
            ->findOrFail($examId);

        // 2. Validar ventana de tiempo (24h previas hasta fin del examen)
        $examDateStr = $exam->exam_date instanceof Carbon 
            ? $exam->exam_date->format('Y-m-d') 
            : substr((string)$exam->exam_date, 0, 10);

        $examStart     = Carbon::parse("{$examDateStr} {$exam->start_time}");
        $examEnd       = $examStart->copy()->addMinutes($exam->duration_minutes);
        $now           = now();
        $availableFrom = $examStart->copy()->subHours(24);

        if ($now->lt($availableFrom)) {
            throw new Exception("El código QR aún no está disponible. Se habilitará 24 horas antes del examen.");
        }

        if ($now->gt($examEnd)) {
            throw new Exception("El examen ya ha finalizado.");
        }

        // 3. Buscar o crear token en BD
        $existingToken = StudentQrToken::where('student_id', $studentId)
            ->where('exam_id', $examId)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->first();

        $tokenValue = $existingToken ? $existingToken->token : (string) Str::uuid();

        if (!$existingToken) {
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

        // 4. Generar JWT Estándar HS256 (3 partes)
        $expTimestamp = $examEnd->timestamp;
        $signedToken  = $this->generateStandardJwt($studentId, $examId, $tokenValue, $expTimestamp);

        // 5. Generar Matriz QR SVG Real escaneable mediante la librería
        $svgQr    = QrCode::format('svg')->size(200)->errorCorrection('H')->generate($signedToken);
        $qrBase64 = "data:image/svg+xml;base64," . base64_encode($svgQr);

        return [
            'exam_id'        => $exam->id,
            'subject'        => $exam->courseOffering->subject->name ?? 'N/A',
            'exam_title'     => $exam->name,
            'scheduled_at'   => $examStart->toDateTimeString(),
            'qr_code_base64' => $qrBase64,
            'token'          => $signedToken,
        ];
    }

    /**
     * Genera un JWT Estándar con algoritmo HS256 (Header.Payload.Signature)
     */
    private function generateStandardJwt(int $studentId, int $examId, string $uuid, int $expTimestamp): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'std' => $studentId,
            'exm' => $examId,
            'exp' => $expTimestamp,
            'jti' => $uuid,
        ];

        $base64UrlHeader  = $this->base64UrlEncode(json_encode($header));
        $base64UrlPayload = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, config('app.key'), true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}