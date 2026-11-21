<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Services\QrV3\QrV3Exception;
use App\Services\Students\StudentQrService;
use App\Services\Students\StudentQrAccessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class StudentQrController extends Controller
{
    public function __construct(
        protected StudentQrService $qrService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $student = $request->user()->student;
        if (! $student) {
            return $this->missingStudentProfileResponse();
        }

        $exams = $this->qrService->getExamsForStudent($student->id);

        return response()->json([
            'message' => 'Exámenes obtenidos exitosamente',
            'data'    => $exams,
        ], 200)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $examId): JsonResponse
    {
        try {
            $student = $request->user()->student;
            if (! $student) {
                return $this->missingStudentProfileResponse();
            }

            $version = $request->query('version', '2');
            if (! in_array($version, ['2', '3'], true)) {
                return response()->json(['message' => 'La versión de QR debe ser 2 o 3.'], 422);
            }

            $qrData = $this->qrService->getOrGenerateForExam($student->id, $examId, (int) $version);

            return response()->json([
                'message' => 'Código QR generado exitosamente',
                'data'    => $qrData,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (StudentQrAccessException $e) {
            return response()->json([
                'code' => $e->reason,
                'message' => $e->getMessage(),
            ], 403)->header('Cache-Control', 'private, no-store');
        } catch (QrV3Exception $e) {
            $failureCode = $e->diagnosticCode ?? $e->reason;
            if (! in_array($failureCode, [
                'EXPERIMENT_DISABLED', 'CONFIGURATION_INVALID', 'EXAM_WINDOW_UNSUPPORTED',
                'CLAIMS_INVALID', 'SIGNING_FAILED', 'SIGNING_DISABLED', 'UNKNOWN_KID',
                'KEY_CONFIGURATION_INVALID', 'KID_INVALID', 'KEY_PAIR_MISMATCH',
                'PRIVATE_KEY_MISSING_OR_EMPTY', 'PUBLIC_KEY_MISSING_OR_EMPTY',
                'PRIVATE_KEY_PEM_INVALID', 'PUBLIC_KEY_PEM_INVALID',
                'PRIVATE_KEY_OPENSSL_REJECTED', 'PUBLIC_KEY_OPENSSL_REJECTED',
                'PRIVATE_KEY_RSA_INVALID', 'PUBLIC_KEY_RSA_INVALID',
            ], true)) {
                $failureCode = 'UNCLASSIFIED';
            }
            try {
                Log::channel('stderr')->error('qr_v3.issue_failed', [
                    'exam_id' => $examId,
                    'failure_code' => $failureCode,
                ]);
            } catch (Throwable) {
                // Preserve the QR response if the log channel fails.
            }

            return response()->json([
                'code' => 'QR_V3_UNAVAILABLE',
                'message' => 'El código QR para control sin conexión no está disponible.',
            ], 503)->header('Cache-Control', 'private, no-store');
        } catch (Throwable $e) {
            try {
                // Do not log messages, SQL, traces, request payloads, QR tokens or keys.
                Log::channel('stderr')->error('qr.issue_failed', [
                    'exam_id' => $examId,
                    'exception_class' => $e::class,
                ]);
            } catch (Throwable) {
                // Logging failure must not expose the original exception to the client.
            }

            return response()->json([
                'code' => 'QR_GENERATION_FAILED',
                'message' => 'No se pudo generar el código QR. Intenta nuevamente más tarde.',
            ], 500)->header('Cache-Control', 'private, no-store');
        }
    }

    private function missingStudentProfileResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'STUDENT_PROFILE_MISSING',
            'message' => 'Tu cuenta no tiene un perfil estudiantil asociado. Comunícate con administración.',
        ], 403);
    }
}
