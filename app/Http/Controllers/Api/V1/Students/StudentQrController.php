<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Http\Resources\Students\QrTokenResource;
use App\Models\Student;
use App\Services\Students\StudentQrService;
use Illuminate\Http\JsonResponse;

class StudentQrController extends Controller
{
    public function __construct(
        protected StudentQrService $qrService
    ) {}

    public function generate(Student $student): JsonResponse
    {
        $qrToken = $this->qrService->generateTokenForStudent($student);

        return response()->json([
            'message' => 'Código QR generado exitosamente.',
            'data' => new QrTokenResource($qrToken),
        ], 201);
    }
}