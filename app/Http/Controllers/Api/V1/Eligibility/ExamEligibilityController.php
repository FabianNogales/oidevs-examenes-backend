<?php

namespace App\Http\Controllers\Api\V1\Eligibility;

use App\Http\Controllers\Controller;
use App\Http\Requests\Eligibility\UpdateEligibilityRequest;
use App\Models\ExamEligibility;
use App\Services\Eligibility\ExamEligibilityService;
use App\Support\Eligibility\EligibilityRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamEligibilityController extends Controller
{
    public function __construct(protected ExamEligibilityService $service) {}

    private function data(ExamEligibility $eligibility): array
    {
        return [
            'id' => $eligibility->id,
            'exam_id' => $eligibility->exam_id,
            'student_id' => $eligibility->student_id,
            'status' => $eligibility->status,
            'reason_code' => $eligibility->reason_code,
            'reason' => $eligibility->reason,
            'observations' => $eligibility->observations,
            'evaluated_at' => $eligibility->evaluated_at?->toIso8601String(),
        ];
    }

    public function index(Request $request, int $examId): JsonResponse
    {
        $this->service->responsibleExam($request, $examId);
        $query = ExamEligibility::with(['student.user', 'evaluator'])->where('exam_id', $examId);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('search')) {
            $search = mb_strtolower(trim($request->query('search')));
            $query->whereHas('student', function ($query) use ($search) {
                foreach (['first_names', 'last_names', 'sis_code', 'identity_number'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ?", ["%{$search}%"]);
                }
            });
        }

        $data = $query->orderBy('id')->get()->map(function ($eligibility) {
            $student = $eligibility->student;
            $photo = $student?->user?->profile_photo;

            return $this->data($eligibility) + [
                'sis_code' => $student?->sis_code,
                'identity_number' => $student?->identity_number,
                'first_names' => $student?->first_names,
                'last_names' => $student?->last_names,
                'email' => $student?->user?->email,
                'profile_photo_url' => $photo
                    ? ((str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) ? $photo : asset('storage/'.$photo))
                    : null,
                'evaluated_by' => $eligibility->evaluator?->email,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function update(UpdateEligibilityRequest $request, int $examId, int $studentId): JsonResponse
    {
        $this->service->responsibleExam($request, $examId);
        $eligibility = DB::transaction(function () use ($request, $examId, $studentId) {
            $eligibility = ExamEligibility::where('exam_id', $examId)->where('student_id', $studentId)
                ->lockForUpdate()->firstOrFail();

            return $this->service->save($eligibility, $request->validated(), $request);
        });

        return response()->json([
            'message' => 'Estado de habilitación actualizado correctamente.',
            'data' => $this->data($eligibility),
        ]);
    }

    public function reasons(Request $request, int $examId): JsonResponse
    {
        $this->service->responsibleExam($request, $examId);

        return response()->json(['data' => collect(EligibilityRules::REASONS)
            ->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()]);
    }

    public function bulk(Request $request, int $examId): JsonResponse
    {
        $exam = $this->service->responsibleExam($request, $examId);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:5120']]);
        $result = $this->service->bulk($request->file('file'), $exam, $request);

        return response()->json(['message' => 'Carga de habilitaciones procesada.', 'data' => $result]);
    }
}
