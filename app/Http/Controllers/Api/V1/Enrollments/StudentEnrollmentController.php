<?php

namespace App\Http\Controllers\Api\V1\Enrollments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollments\StoreManualEnrollmentRequest;
use App\Http\Requests\Enrollments\StoreBulkEnrollmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Exception;

class StudentEnrollmentController extends Controller
{
    public function storeManual(StoreManualEnrollmentRequest $request, int $courseOfferingId): JsonResponse
    {
        $userId = $request->user()->id;
        $teacher = DB::table('teachers')->where('user_id', $userId)->first();

        // 1. Prevención IDOR actualizada a la nueva estructura
        $courseOffering = DB::table('course_offerings')->find($courseOfferingId);
        if (!$courseOffering || !$teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $sisCode = $request->input('sisCode');
        $student = DB::table('students')->where('sis_code', $sisCode)->first();

        if (!$student) {
            return response()->json(['message' => 'Student not found in the institutional registry.'], 404);
        }

        $exists = DB::table('enrollments')
            ->where('course_offering_id', $courseOfferingId)
            ->where('student_id', $student->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'The student is already enrolled in this course offering.'], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('enrollments')->insert([
                'course_offering_id' => $courseOfferingId,
                'student_id' => $student->id,
                'status' => 'ACTIVE',
                'registered_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'action' => 'WRITE',
                'entity_type' => 'Enrollment',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'A critical error occurred.'], 500);
        }

        return response()->json(['message' => 'Student enrolled successfully.'], 201);
    }

    public function storeBulk(StoreBulkEnrollmentRequest $request, int $courseOfferingId): JsonResponse
    {
        $userId = $request->user()->id;
        $teacher = DB::table('teachers')->where('user_id', $userId)->first();

        // 1. Prevención IDOR actualizada
        $courseOffering = DB::table('course_offerings')->find($courseOfferingId);
        if (!$courseOffering || !$teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        
        $header = fgetcsv($handle);
        $sisCodeIndex = array_search('sisCode', $header);

        $failedRecords = [];
        $successfulCount = 0;
        $totalProcessed = 0;
        $rowNum = 1; 
        
        $studentsToInsert = [];
        $processedSisCodes = []; 

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                
                if (empty(array_filter($row))) {
                    $failedRecords[] = ['row' => $rowNum, 'sisCode' => null, 'reason' => 'Empty row'];
                    continue;
                }
                
                $totalProcessed++;
                $sisCode = $row[$sisCodeIndex] ?? null;

                if (!$sisCode) {
                    $failedRecords[] = ['row' => $rowNum, 'sisCode' => null, 'reason' => 'Missing sisCode column data'];
                    continue;
                }

                if (in_array($sisCode, $processedSisCodes)) {
                    $failedRecords[] = ['row' => $rowNum, 'sisCode' => $sisCode, 'reason' => 'Duplicate sisCode in CSV file'];
                    continue;
                }
                $processedSisCodes[] = $sisCode;

                $student = DB::table('students')->where('sis_code', $sisCode)->first();
                
                if (!$student) {
                    $failedRecords[] = ['row' => $rowNum, 'sisCode' => $sisCode, 'reason' => 'Student not found in institutional registry'];
                    continue;
                }

                $exists = DB::table('enrollments')
                    ->where('course_offering_id', $courseOfferingId)
                    ->where('student_id', $student->id)
                    ->exists();

                if ($exists) {
                    $failedRecords[] = ['row' => $rowNum, 'sisCode' => $sisCode, 'reason' => 'Already enrolled in this course offering'];
                    continue;
                }

                $studentsToInsert[] = [
                    'course_offering_id' => $courseOfferingId,
                    'student_id' => $student->id,
                    'status' => 'ACTIVE',
                    'registered_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $successfulCount++;
            }

            if (!empty($studentsToInsert)) {
                DB::table('enrollments')->insert($studentsToInsert);
                
                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'WRITE',
                    'entity_type' => 'Enrollment',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            fclose($handle);
            return response()->json(['message' => 'A critical error occurred while processing the file.'], 500);
        }

        fclose($handle);

        return response()->json([
            'data' => [
                'totalProcessed' => $totalProcessed,
                'successfulRecords' => $successfulCount,
                'failedRecords' => $failedRecords
            ]
        ], 200);
    }
}