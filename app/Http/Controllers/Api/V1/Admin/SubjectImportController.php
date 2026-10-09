<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subjects\PreviewSubjectImportRequest;
use App\Services\Subjects\SubjectImportService;
use Illuminate\Http\JsonResponse;

class SubjectImportController extends Controller
{
    public function preview(PreviewSubjectImportRequest $request, SubjectImportService $imports): JsonResponse
    {
        return response()->json(['data' => $imports->preview($request->file('file'), $request->user()->id)]);
    }
}
