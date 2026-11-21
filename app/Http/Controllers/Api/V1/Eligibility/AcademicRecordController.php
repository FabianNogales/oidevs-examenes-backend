<?php

namespace App\Http\Controllers\Api\V1\Eligibility;

use App\Http\Controllers\Controller;
use App\Models\AcademicRecord;
use App\Models\AuditLog;
use App\Services\Eligibility\Automation\AcademicDataService;
use App\Services\Eligibility\Automation\AutomationSupport;
use App\Services\Eligibility\Automation\CriteriaCatalog;
use Illuminate\Http\Request;

class AcademicRecordController extends Controller
{
    public function __construct(private AcademicDataService $data, private AutomationSupport $support) {}

    public function schema(Request $request, int $offering)
    {
        $this->support->offering($request, $offering);

        return $this->support->response(['data' => ['columns' => AcademicDataService::HEADER, 'assessment_keys' => CriteriaCatalog::ASSESSMENTS,
            'encoding' => 'UTF-8', 'delimiter' => ',', 'max_rows' => 1000, 'max_file_kb' => 5120,
            'score_min' => 0, 'score_max' => 100, 'score_decimal_places' => 2, 'was_taken_values' => ['true', 'false'],
            'default_mode' => 'CREATE_ONLY', 'modes' => ['CREATE_ONLY', 'MERGE'],
            'merge' => ['allowed_columns' => AcademicDataService::HEADER, 'required_columns' => ['sis'],
                'attendance_columns' => ['sis', 'attended_classes', 'total_classes'],
                'assessment_columns' => ['sis', 'assessment_key', 'was_taken', 'score'],
                'empty_values' => 'PRESERVE', 'reason_required_for_updates' => true,
                'reason_max_length' => 500, 'confirmation_revalidates_revisions' => true]]]);
    }

    public function preview(Request $request, int $offering)
    {
        $this->support->offering($request, $offering);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv', 'max:5120'],
            'mode' => ['sometimes', 'in:CREATE_ONLY,MERGE'], 'reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $preview = $this->data->preview($request, $offering, $request->file('file'));

        return $this->support->response(['data' => ['preview_id' => $preview->id, 'expires_at' => $preview->expires_at->toIso8601String(),
            'can_confirm' => ! $preview->preview['errors']] + $preview->preview]);
    }

    public function confirm(Request $request, int $offering, string $preview)
    {
        return $this->support->response(['data' => $this->data->confirm($request, $offering, $preview)]);
    }

    public function index(Request $request, int $offering)
    {
        $this->support->offering($request, $offering);
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:200']]);
        $query = AcademicRecord::join('students', 'students.id', '=', 'academic_records.student_id')->where('academic_records.course_offering_id', $offering)
            ->select('academic_records.*', 'students.sis_code', 'students.first_names', 'students.last_names');
        if ($request->filled('search')) {
            $search = '%'.mb_strtolower(trim($request->string('search'))).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(students.first_names) LIKE ?', [$search])->orWhereRaw('LOWER(students.last_names) LIKE ?', [$search])->orWhere('students.sis_code', 'like', $search));
        }

        return $this->support->response($this->support->page($request, $query->orderBy('academic_records.id')));
    }

    public function update(Request $request, int $offering, int $record)
    {
        return $this->support->response(['data' => $this->data->correct($request, $offering, $record)]);
    }

    public function history(Request $request, int $offering)
    {
        $this->support->offering($request, $offering);
        $ids = AcademicRecord::where('course_offering_id', $offering)->pluck('id');
        $query = AuditLog::where(fn ($q) => $q->where(fn ($q) => $q->where('entity_type', 'AcademicRecord')->whereIn('entity_id', $ids))
            ->orWhere(fn ($q) => $q->where('entity_type', 'CourseOffering')->where('entity_id', $offering)->whereIn('action', ['ACADEMIC_IMPORT_PREVIEWED', 'ACADEMIC_IMPORT_CONFIRMED'])));

        return $this->support->response($this->support->page($request, $query->select('id', 'user_id', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values', 'created_at')->orderByDesc('id')));
    }
}
