<?php

namespace App\Http\Controllers\Api\V1\TeacherDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherDashboardController extends Controller
{
    public function getAssignedSubjects(Request $request)
    {
        $teacher = DB::table('teachers')->where('user_id', $request->user()->id)->first();
        if (!$teacher) return response()->json(['data' => []]);

        $subjects = DB::table('course_offerings')
            ->join('subjects', 'course_offerings.subject_id', '=', 'subjects.id')
            ->join('academic_terms', 'course_offerings.academic_term_id', '=', 'academic_terms.id')
            ->where('course_offerings.teacher_id', $teacher->id)
            ->select(
                'course_offerings.id', 
                'subjects.code as subject_code', 
                'subjects.name as subject_name', 
                'academic_terms.name as term_name'
            )
            ->get()
            ->map(function($item) {
                return [
                    'course_offering_id' => $item->id,
                    'subject' => [
                        'code' => $item->subject_code,
                        'name' => $item->subject_name
                    ],
                    'academic_term' => [
                        'name' => $item->term_name
                    ]
                ];
            });

        return response()->json(['data' => $subjects]);
    }

    public function getUpcomingExams(Request $request)
    {
        $teacher = DB::table('teachers')->where('user_id', $request->user()->id)->first();
        if (!$teacher) return response()->json(['data' => []]);

        $exams = DB::table('exams')
            ->join('course_offerings', 'exams.course_offering_id', '=', 'course_offerings.id')
            ->join('subjects', 'course_offerings.subject_id', '=', 'subjects.id')
            ->join('rooms', 'exams.room_id', '=', 'rooms.id')
            ->where('course_offerings.teacher_id', $teacher->id)
            ->where('exams.exam_date', '>=', now()->toDateString())
            ->select(
                'exams.id', 'exams.name', 'exams.exam_date', 'exams.start_time', 
                'exams.duration_minutes', 'exams.evaluation_type', 'exams.status',
                'subjects.code as subject_code', 'subjects.name as subject_name',
                'rooms.id as room_id', 'rooms.code as room_code', 'rooms.name as room_name'
            )
            ->orderBy('exams.exam_date', 'asc')
            ->orderBy('exams.start_time', 'asc')
            ->get()
            ->map(function($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'subject_code' => $item->subject_code,
                    'subject_name' => $item->subject_name,
                    'exam_date' => $item->exam_date,
                    'start_time' => $item->start_time,
                    'duration_minutes' => $item->duration_minutes,
                    'room' => [
                        'id' => $item->room_id,
                        'code' => $item->room_code,
                        'name' => $item->room_name
                    ],
                    'evaluation_type' => $item->evaluation_type,
                    'status' => $item->status
                ];
            });

        return response()->json(['data' => $exams]);
    }
}