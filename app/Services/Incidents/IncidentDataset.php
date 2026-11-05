<?php

namespace App\Services\Incidents;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class IncidentDataset
{
    public function query(?int $examId = null): Builder
    {
        $studentJson = DB::getDriverName() === 'pgsql'
            ? "a.new_values->>'student_id'" : "CAST(json_extract(a.new_values, '$.student_id') AS TEXT)";
        $examJson = DB::getDriverName() === 'pgsql'
            ? "a.new_values->>'qr_exam_id'" : "CAST(json_extract(a.new_values, '$.qr_exam_id') AS TEXT)";
        $manual = DB::table('special_cases as m')->when($examId !== null, fn ($q) => $q->where('m.exam_id', $examId))
            ->selectRaw("m.id AS source_id, m.exam_id, CAST(m.student_id AS TEXT) AS student_id, m.type, m.description,
                m.reported_by, m.created_at, m.status, 'MANUAL' AS origin, NULL AS qr_exam_id");
        $automatic = DB::table('audit_logs as a')->where('a.action', 'ENTRY_QR_WRONG_EXAM')->where('a.entity_type', 'Exam')
            ->when($examId !== null, fn ($q) => $q->where('a.entity_id', $examId))
            ->selectRaw("a.id AS source_id, a.entity_id AS exam_id, {$studentJson} AS student_id,
                'WRONG_EXAM_QR' AS type, 'Intento de ingreso rechazado: QR de otro examen.' AS description,
                a.user_id AS reported_by, a.created_at, 'REJECTED' AS status, 'AUTOMATIC_QR' AS origin,
                {$examJson} AS qr_exam_id");

        return DB::query()->fromSub($manual->unionAll($automatic), 'incidents');
    }

    public function withContext(Builder $query): Builder
    {
        return $query->leftJoin('students as involved', DB::raw('CAST(involved.id AS TEXT)'), '=', 'incidents.student_id')
            ->join('exams as exam', 'exam.id', '=', 'incidents.exam_id')
            ->leftJoin('users as responsible', 'responsible.id', '=', 'incidents.reported_by')
            ->leftJoin('teachers as reporter_teacher', 'reporter_teacher.user_id', '=', 'responsible.id')
            ->leftJoin('students as reporter_student', 'reporter_student.user_id', '=', 'responsible.id')
            ->select('incidents.*', 'involved.first_names as student_first_names', 'involved.last_names as student_last_names',
                'involved.sis_code', 'involved.identity_number', 'exam.name as exam_name', 'exam.exam_date')
            ->selectRaw("COALESCE(NULLIF(TRIM(COALESCE(reporter_teacher.first_names, '') || ' ' || COALESCE(reporter_teacher.last_names, '')), ''),
                NULLIF(TRIM(COALESCE(reporter_student.first_names, '') || ' ' || COALESCE(reporter_student.last_names, '')), ''),
                responsible.email) AS reporter_name");
    }
}
