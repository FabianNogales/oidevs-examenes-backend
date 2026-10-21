<?php

namespace App\Http\Controllers\Api\V1\Enrollments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollments\StoreManualEnrollmentRequest;
use App\Http\Requests\Enrollments\StoreBulkEnrollmentRequest;
use App\Services\Audit\AuditLogService;
use App\Services\Eligibility\ExamEligibilityInitializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class StudentEnrollmentController extends Controller
{
    // GET de estudiantes inscritos
    public function index(Request $request, int $courseOfferingId): JsonResponse
    {
        $userId = $request->user()->id;
        $teacher = DB::table('teachers')->where('user_id', $userId)->first();
        $courseOffering = DB::table('course_offerings')->find($courseOfferingId);

        // Prevención IDOR
        if (!$courseOffering || !$teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $students = DB::table('enrollments')
            ->join('students', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.course_offering_id', $courseOfferingId)
            ->where('enrollments.status', 'ACTIVE')
            ->select('students.id', 'students.sis_code', 'students.first_names', 'students.last_names', 'students.status')
            ->get();

        return response()->json(['data' => $students], 200);
    }

    // MEJORA: Respuesta de inscripción manual
    public function storeManual(StoreManualEnrollmentRequest $request, int $courseOfferingId): JsonResponse
    {
        $userId = $request->user()->id;
        $teacher = DB::table('teachers')->where('user_id', $userId)->first();
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
            $enrollmentId = DB::table('enrollments')->insertGetId([
                'course_offering_id' => $courseOfferingId,
                'student_id' => $student->id,
                'status' => 'ACTIVE',
                'registered_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(ExamEligibilityInitializer::class)->forNewEnrollments($courseOfferingId, [$student->id], $userId);

            app(AuditLogService::class)->log($request, $userId, 'WRITE', 'Enrollment', $enrollmentId,
                newValues: [
                    'source' => 'APPLICATION', 'operation' => 'ENROLLMENT_CREATED', 'outcome' => 'CONFIRMED',
                    'scope' => 'ENROLLMENT', 'mode' => 'MANUAL', 'course_offering_id' => $courseOfferingId,
                    'student_ids' => [$student->id],
                    'summary' => ['totalProcessed' => 1, 'successfulRecords' => 1, 'duplicateRecords' => 0, 'failedCount' => 0],
                ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'A critical error occurred.'], 500);
        }

        return response()->json([
            'message' => 'Student enrolled successfully.',
            'data' => [
                'id' => $student->id,
                'sisCode' => $student->sis_code,
                'firstNames' => $student->first_names,
                'lastNames' => $student->last_names,
                'status' => $student->status
            ]
        ], 201);
    }

    public function storeBulk(StoreBulkEnrollmentRequest $request, int $courseOfferingId): JsonResponse
    {
        return $this->processBulk($request, $courseOfferingId, false);
    }

    public function previewBulk(StoreBulkEnrollmentRequest $request, int $courseOfferingId): JsonResponse
    {
        return $this->processBulk($request, $courseOfferingId, true);
    }

    private function processBulk(StoreBulkEnrollmentRequest $request, int $courseOfferingId, bool $preview): JsonResponse
    {
        $userId = $request->user()->id;
        $teacher = DB::table('teachers')->where('user_id', $userId)->first();
        $courseOffering = DB::table('course_offerings')->find($courseOfferingId);

        if (!$courseOffering || !$teacher || $courseOffering->teacher_id !== $teacher->id) {
            return response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403);
        }

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        
        $header = fgetcsv($handle);
        $sisCodeIndex = array_search('sisCode', $header);

        $records = []; 
        $successfulCount = 0;
        $duplicateCount = 0;
        $failedCount = 0;
        $totalProcessed = 0;
        $rowNum = 1; 
        
        $studentsToInsert = [];
        $processedSisCodes = []; 
        $rowResults = [];

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                $totalProcessed++;
                
                if (empty(array_filter($row))) {
                    $records[] = [
                        'row' => $rowNum, 
                        'sisCode' => null, 
                        'status' => 'INVALID', 
                        'reason' => 'La fila está vacía'
                    ];
                    $failedCount++;
                    continue;
                }
                
                $sisCode = $row[$sisCodeIndex] ?? null;

                if (!$sisCode) {
                    $records[] = ['row' => $rowNum, 'sisCode' => null, 'status' => 'INVALID', 'reason' => 'Missing sisCode column data'];
                    $failedCount++;
                    continue;
                }

                if (in_array($sisCode, $processedSisCodes)) {
                    $records[] = ['row' => $rowNum, 'sisCode' => $sisCode, 'status' => 'DUPLICATE', 'reason' => 'Duplicate sisCode in CSV file'];
                    $duplicateCount++;
                    continue;
                }
                $processedSisCodes[] = $sisCode;

                $student = DB::table('students')->where('sis_code', $sisCode)->first();
                
                if (!$student) {
                    $records[] = ['row' => $rowNum, 'sisCode' => $sisCode, 'status' => 'NOT_FOUND', 'reason' => 'Student not found in institutional registry'];
                    $failedCount++;
                    continue;
                }

                $exists = DB::table('enrollments')
                    ->where('course_offering_id', $courseOfferingId)
                    ->where('student_id', $student->id)
                    ->exists();

                if ($exists) {
                    $records[] = ['row' => $rowNum, 'sisCode' => $sisCode, 'status' => 'DUPLICATE', 'reason' => 'Already enrolled in this course offering'];
                    $duplicateCount++;
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
                $rowResults[] = [
                    'row' => $rowNum,
                    'sisCode' => $sisCode,
                    'status' => $preview ? 'VALID' : 'ADDED',
                    'reason' => $preview ? 'Listo para importar.' : 'Agregado correctamente.',
                ];
                $successfulCount++;
            }

            if (!$preview && !empty($studentsToInsert)) {
                DB::table('enrollments')->insert($studentsToInsert);
                app(ExamEligibilityInitializer::class)->forNewEnrollments(
                    $courseOfferingId, array_column($studentsToInsert, 'student_id'), $userId
                );
                
                // A batch has no single enrollment id; its offering and confirmed students identify its scope.
                app(AuditLogService::class)->log($request, $userId, 'WRITE', 'Enrollment',
                    newValues: [
                        'source' => 'APPLICATION', 'operation' => 'ENROLLMENTS_IMPORTED', 'outcome' => 'CONFIRMED',
                        'scope' => 'COURSE_OFFERING', 'mode' => 'CSV', 'course_offering_id' => $courseOfferingId,
                        'student_ids' => array_column($studentsToInsert, 'student_id'),
                        'summary' => ['totalProcessed' => $totalProcessed, 'successfulRecords' => $successfulCount,
                            'duplicateRecords' => $duplicateCount, 'failedCount' => $failedCount],
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
                'duplicateRecords' => $duplicateCount,
                'failedCount' => $failedCount,
                'failedRecords' => $records,
                'records' => collect(array_merge($rowResults, $records))->sortBy('row')->values()->all(),
            ]
        ], 200);
    }
}
