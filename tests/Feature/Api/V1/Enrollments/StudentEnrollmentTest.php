<?php

namespace Tests\Feature\Api\V1\Enrollments;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected int $teacherUserId;
    protected int $teacherProfileId;
    protected int $courseOfferingId;
    protected int $studentId;
    protected int $careerId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear el usuario con los middlewares evadidos
        $teacherUser = User::factory()->create([
            'status' => 'ACTIVE',
            'must_change_password' => false
        ]);
        $this->teacherUserId = $teacherUser->id;

        $roleId = DB::table('roles')->insertGetId([
            'name' => RoleName::DOCENTE->value,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $teacherUser->id,
            'role_id' => $roleId,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        // 2. Crear el perfil en la nueva tabla teachers
        $this->teacherProfileId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherUser->id,
            'institutional_code' => 'DOC-001',
            'identity_number' => '12345678',
            'first_names' => 'Carlos',
            'last_names' => 'Perez',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insert([
            'id' => 1, 'name' => '2026-I', 'start_date' => '2026-02-01', 'end_date' => '2026-07-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('subjects')->insert([
            'id' => 1, 'code' => 'CS101', 'name' => 'Introduction to Programming', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 3. Vincular la materia usando teacher_id
        $this->courseOfferingId = DB::table('course_offerings')->insertGetId([
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_id' => $this->teacherProfileId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->careerId = DB::table('careers')->insertGetId([
            'code' => 'SE-101', 'name' => 'Software Engineering', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $studentUser = User::factory()->create([
            'status' => 'ACTIVE',
            'must_change_password' => false
        ]);
        
        $this->studentId = DB::table('students')->insertGetId([
            'user_id' => $studentUser->id,
            'sis_code' => '202600001',
            'identity_number' => '1234567',
            'first_names' => 'John',
            'last_names' => 'Doe',
            'career_id' => $this->careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        Sanctum::actingAs($teacherUser, ['*']);
    }

    public function testManualEnrollmentRejectsNonExistentSisCode(): void
    {
        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '999999999'
        ]);

        $response->assertStatus(404)
                 ->assertJson(['message' => 'Student not found in the institutional registry.']);
    }

    public function testManualEnrollmentSucceedsAndPreventsDuplicates(): void
    {
        $response1 = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ]);

        $response1->assertStatus(201)
                  ->assertJson(['message' => 'Student enrolled successfully.']);

        $this->assertDatabaseHas('enrollments', [
            'course_offering_id' => $this->courseOfferingId,
            'student_id' => $this->studentId,
            'registered_by' => $this->teacherUserId,
            'status' => 'ACTIVE',
        ]);

        $response2 = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/manual", [
            'sisCode' => '202600001'
        ]);

        $response2->assertStatus(422)
                  ->assertJson(['message' => 'The student is already enrolled in this course offering.']);
    }

    public function testBulkCsvUploadValidatesFormatAndProcessesRecordsInTransaction(): void
    {
        $studentUser2 = User::factory()->create([
            'status' => 'ACTIVE',
            'must_change_password' => false
        ]);
        
        $student2Id = DB::table('students')->insertGetId([
            'user_id' => $studentUser2->id,
            'sis_code' => '202600002',
            'identity_number' => '7654321',
            'first_names' => 'Jane',
            'last_names' => 'Smith',
            'career_id' => $this->careerId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $csvContent = "sisCode\n202600002\n999999999\n\n202600002";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/bulk", [
            'file' => $file
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'totalProcessed',
                         'successfulRecords',
                         'duplicateRecords',
                         'failedCount',
                         'failedRecords' => [
                             '*' => ['row', 'sisCode', 'status', 'reason']
                         ]
                     ]
                 ]);

        $this->assertDatabaseHas('enrollments', [
            'course_offering_id' => $this->courseOfferingId,
            'student_id' => $student2Id,
            'registered_by' => $this->teacherUserId,
            'status' => 'ACTIVE',
        ]);
    }

    public static function bulkCounterCases(): array
    {
        // Exact uploaded bytes exercise fgetcsv, including LF/CRLF and EOF behavior.
        $cases = [
            'header only' => ["sisCode\n", [0, 0, 0, 0], []],
            'one empty LF record' => ["sisCode\n\n", [1, 0, 0, 1], ['empty']],
            'two empty CRLF records' => ["sisCode\r\n\r\n\r\n", [2, 0, 0, 2], ['empty', 'empty']],
            'valid then empty' => ["sisCode\n202600001\n\n", [2, 1, 0, 1], ['success', 'empty']],
            'empty then valid' => ["sisCode\n\n202600001\n", [2, 1, 0, 1], ['empty', 'success']],
            'valid duplicate and unknown' => ["sisCode\n202600001\n202600001\n999999999\n",
                [3, 1, 1, 1], ['success', 'duplicate', 'not_found']],
            'all columns empty' => ["sisCode,optional,another\n,,\n", [1, 0, 0, 1], ['empty']],
            'only spaces' => ["sisCode\n   \n", [1, 0, 0, 1], ['not_found']],
            'padded SIS' => ["sisCode\n 202600001 \n", [1, 0, 0, 1], ['not_found']],
            'missing SIS with another value' => ["sisCode,optional\n,comment\n", [1, 0, 0, 1], ['missing']],
            'single final newline is not another record' => ["sisCode\n202600001\n", [1, 1, 0, 0], ['success']],
        ];
        $datasets = [];
        foreach ([true, false] as $preview) {
            foreach ($cases as $name => [$csv, $counts, $results]) {
                $datasets[($preview ? 'preview: ' : 'confirm: ').$name] = [$preview, $csv, $counts, $results];
            }
        }

        return $datasets;
    }

    #[DataProvider('bulkCounterCases')]
    public function testBulkCountsEveryParsedRecordExactlyOnce(
        bool $preview, string $csv, array $counts, array $results,
    ): void {
        [$total, $successful, $duplicates, $failed] = $counts;
        $response = $this->postJson("/api/v1/course-offerings/{$this->courseOfferingId}/enrollments/bulk".($preview ? '/preview' : ''), [
            'file' => UploadedFile::fake()->createWithContent('enrollments.csv', $csv),
        ])->assertOk()->assertJsonPath('data.totalProcessed', $total)
            ->assertJsonPath('data.successfulRecords', $successful)
            ->assertJsonPath('data.duplicateRecords', $duplicates)
            ->assertJsonPath('data.failedCount', $failed);

        $data = $response->json('data');
        $this->assertSame($data['totalProcessed'],
            $data['successfulRecords'] + $data['duplicateRecords'] + $data['failedCount']);
        $this->assertCount($total, $data['records']);
        // failedRecords includes duplicates, in addition to failedCount.
        $this->assertCount($failed + $duplicates, $data['failedRecords']);
        foreach ($results as $index => $result) {
            $record = $data['records'][$index];
            $this->assertSame($index + 2, $record['row']);
            [$status, $reason] = match ($result) {
                'empty' => ['INVALID', 'La fila está vacía'],
                'missing' => ['INVALID', 'Missing sisCode column data'],
                'not_found' => ['NOT_FOUND', 'Student not found in institutional registry'],
                'duplicate' => ['DUPLICATE', 'Duplicate sisCode in CSV file'],
                'success' => [$preview ? 'VALID' : 'ADDED', $preview ? 'Listo para importar.' : 'Agregado correctamente.'],
            };
            $this->assertSame($status, $record['status']);
            $this->assertSame($reason, $record['reason']);
            if (in_array($result, ['empty', 'missing'], true)) {
                $this->assertNull($record['sisCode']);
            }
        }

        $persisted = $preview ? 0 : $successful;
        $this->assertDatabaseCount('enrollments', $persisted);
        $this->assertSame($persisted, DB::table('audit_logs')->where('entity_type', 'Enrollment')->where('action', 'WRITE')->count());
        $this->assertDatabaseCount('students', 1);
        if ($persisted > 0) {
            $this->assertDatabaseHas('enrollments', ['student_id' => $this->studentId,
                'course_offering_id' => $this->courseOfferingId, 'status' => 'ACTIVE', 'registered_by' => $this->teacherUserId]);
        }
    }
}
