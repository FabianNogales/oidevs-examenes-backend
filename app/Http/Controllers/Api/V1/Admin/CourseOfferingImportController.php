<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CourseOfferings\ConfirmCourseOfferingImportRequest;
use App\Http\Requests\Api\V1\CourseOfferings\PreviewCourseOfferingImportRequest;
use App\Services\CourseOfferings\CourseOfferingImportService;
use Illuminate\Http\JsonResponse;

class CourseOfferingImportController extends Controller
{
    public function confirm(ConfirmCourseOfferingImportRequest $request, CourseOfferingImportService $imports): JsonResponse
    {
        return response()->json(['data' => $imports->confirm($request->file('file'), (int) $request->validated('academic_term_id'), $request->validated('preview_id'), $request)]);
    }

    public function preview(PreviewCourseOfferingImportRequest $request, CourseOfferingImportService $imports): JsonResponse
    {
        return response()->json(['data' => $imports->preview($request->file('file'), (int) $request->validated('academic_term_id'), $request->user()->id)]);
    }
}
