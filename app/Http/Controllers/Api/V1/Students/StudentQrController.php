<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Services\Students\StudentQrService;
use App\Http\Resources\Students\QrTokenResource;
use Illuminate\Http\JsonResponse;
use Exception;

class StudentQrController extends Controller
{
    public function __construct(
        protected StudentQrService $qrService
    ) {}

    public function show(int $studentId, int $subjectId): JsonResponse
    {
        try {
            $qrToken = $this->qrService->getOrGenerateForSubject($studentId, $subjectId);

            return response()->json([
                'message' => 'QR obtenido exitosamente',
                'data'    => new QrTokenResource($qrToken)
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }
}