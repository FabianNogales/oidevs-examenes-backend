<?php

namespace App\Services\Eligibility\Automation;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Services\Audit\AuditLogService;
use App\Services\Eligibility\ExamEligibilityService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationSupport
{
    public function response(array $payload, int $status = 200): JsonResponse
    {
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) {
                return $value;
            }
            foreach ($value as $key => $item) {
                // Catalog parameters are a list of field schemas; historical selections use an object.
                $value[$key] = $key === 'parameters' && $item === [] && ! array_key_exists('mandatory_for_exam_types', $value)
                    ? (object) [] : $normalize($item);
            }

            return $value;
        };

        return response()->json($normalize($payload), $status);
    }

    public function offering(Request $request, int $id, bool $lock = false): CourseOffering
    {
        $offering = CourseOffering::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        abort_unless($request->user()?->isActive() && (int) $offering->teacher?->user_id === $request->user()->id, 403);

        return $offering;
    }

    public function exam(Request $request, int $id, bool $lock = false): Exam
    {
        $exam = app(ExamEligibilityService::class)->responsibleExam($request, $id);
        // Existing scheduling/enrollment services lock exam before offering.
        if ($lock) {
            $exam = Exam::whereKey($id)->lockForUpdate()->firstOrFail();
        }
        $this->offering($request, $exam->course_offering_id, $lock);

        return $exam;
    }

    public function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => 'AUTOMATION_CONFLICT', 'message' => $message], 409));
    }

    public function audit(Request $request, string $action, string $entity, int $id, ?array $old, array $new): void
    {
        app(AuditLogService::class)->log($request, $request->user()->id, $action, $entity, $id, $old, $new);
    }

    public function page(Request $request, $query): array
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'search' => ['sometimes', 'nullable', 'string', 'max:200']]);
        $page = $query->paginate($request->integer('per_page', 10));

        return ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }
}
