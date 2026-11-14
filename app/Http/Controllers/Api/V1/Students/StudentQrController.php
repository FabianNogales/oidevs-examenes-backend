<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Services\QrV3\QrV3Exception;
use App\Services\Students\StudentQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

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
        $student = $request->user()->student;
        if (! $student) {
            return $this->missingStudentProfileResponse();
        }

        $version = $request->query('version', '2');
        if (! in_array($version, ['2', '3'], true)) {
            return response()->json(['message' => 'La versión de QR debe ser 2 o 3.'], 422);
        }

        try {
            $qrData = $this->qrService->getOrGenerateForExam($student->id, $examId, (int) $version);

            return response()->json([
                'message' => 'Código QR generado exitosamente',
                'data'    => $qrData,
            ], 200)->header('Cache-Control', 'private, no-store');
        } catch (QrV3Exception) {
            return response()->json([
                'code' => 'QR_V3_UNAVAILABLE',
                'message' => 'El código QR para control sin conexión no está disponible.',
            ], 503)->header('Cache-Control', 'private, no-store');
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 403);
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
