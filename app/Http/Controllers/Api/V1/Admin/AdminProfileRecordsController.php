<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminProfileRecordsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProfileRecordsController extends Controller
{
    public function index(Request $request, string $profileType, int $profileId, AdminProfileRecordsService $records)
    {
        $filters = $request->validate(['section' => ['required', Rule::in(['subjects', 'subject-history', 'exams', 'exam-history', 'roles', 'collaborations'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'], 'state' => ['sometimes', Rule::in(['ACTIVE', 'COMPLETED'])]]);

        return response()->json($records->records($profileType, $profileId, $filters));
    }
}
