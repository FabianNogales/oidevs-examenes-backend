<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
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
        $student = $request->user()->student ?? $request->user();

        $exams = $this->qrService->getExamsForStudent($student->id ?? 1);

        return response()->json([
            'message' => 'Exámenes obtenidos exitosamente',
            'data'    => $exams,
        ], 200);
    }

    public function show(Request $request, int $examId): JsonResponse
    {
        try {
            $student = $request->user()->student ?? $request->user();
            
            $qrData = $this->qrService->getOrGenerateForExam($student->id ?? 1, $examId);

            return response()->json([
                'message' => 'Código QR generado exitosamente',
                'data'    => $qrData,
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 403);
        }
    }
}