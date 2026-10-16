<?php

namespace Tests\Feature\CourseOfferings;

use App\Models\CourseOffering;
use App\Models\CourseOfferingImport;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CourseOfferings\CourseOfferingImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class CourseOfferingConcurrencyTest extends TestCase
{
    use CourseOfferingFixture;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU19_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu19_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires an isolated hu19_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->fixture = $this->offeringFixture();
    }

    public function test_two_manual_assignments_create_one_offering_and_one_audit(): void
    {
        extract($this->fixture);
        $subject = Subject::create(['code' => 'INF-02', 'name' => 'Segunda', 'status' => 'ACTIVE']);
        $operation = $this->operation('POST', '/api/v1/admin/course-offerings/bulk', ['teacher_id' => $teacher->id, 'academic_term_id' => $term->id, 'subject_ids' => [$subject->id]]);
        [$first, $second] = $this->workers($operation, $operation);
        $this->assertSame(201, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame(1, CourseOffering::where('subject_id', $subject->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'COURSE_OFFERING_CREATED')->count());
    }

    public function test_simultaneous_csv_confirmations_return_identical_persisted_report(): void
    {
        extract($this->fixture);
        Subject::create(['code' => 'INF-02', 'name' => 'Segunda', 'status' => 'ACTIVE']);
        $file = UploadedFile::fake()->createWithContent('offerings.csv', "correo_docente,codigo_materia\n".$user->email.",INF-02\n");
        $preview = app(CourseOfferingImportService::class)->preview($file, $term->id, $user->id);
        $operation = $this->operation('POST', '/api/v1/admin/course-offerings/import/confirm', ['academic_term_id' => $term->id, 'preview_id' => $preview['preview_id']]);
        $operation['file'] = $file->getRealPath();
        [$first, $second] = $this->workers($operation, $operation);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertEquals($first['data'], $second['data']);
        $this->assertIsInt($second['data']['data']['rows'][0]['course_offering_id']);
        $this->assertSame(1, $first['data']['data']['summary']['imported']);
        $this->assertSame(2, CourseOffering::count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'COURSE_OFFERING_CREATED')->count());
        $this->assertSame('COMPLETED', CourseOfferingImport::firstOrFail()->status);
    }

    public function test_enrollment_winning_lock_prevents_reassignment_of_history(): void
    {
        [$first, $second] = $this->workers($this->enroll(), $this->edit());
        $this->assertSame(201, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('COURSE_OFFERING_HAS_HISTORY', $second['data']['code']);
        $this->assertSame($this->fixture['teacher']->id, $this->fixture['offering']->fresh()->teacher_id);
        $this->assertSame(1, DB::table('enrollments')->count());
    }

    public function test_reassignment_winning_lock_rechecks_old_teacher_ownership_before_enrolling(): void
    {
        [$first, $second] = $this->workers($this->edit(), $this->enroll());
        $this->assertSame(200, $first['status']);
        $this->assertSame(403, $second['status']);
        $this->assertSame(0, DB::table('enrollments')->count());
        $this->assertNotSame($this->fixture['teacher']->id, $this->fixture['offering']->fresh()->teacher_id);
    }

    public function test_exam_winning_lock_prevents_reassignment_of_history(): void
    {
        [$first, $second] = $this->workers($this->exam(), $this->edit());
        $this->assertSame(201, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('COURSE_OFFERING_HAS_HISTORY', $second['data']['code']);
        $this->assertSame(1, DB::table('exams')->count());
        $this->assertSame($this->fixture['teacher']->id, $this->fixture['offering']->fresh()->teacher_id);
    }

    public function test_reassignment_winning_lock_rechecks_old_teacher_ownership_before_exam_creation(): void
    {
        [$first, $second] = $this->workers($this->edit(), $this->exam());
        $this->assertSame(200, $first['status']);
        $this->assertSame(403, $second['status']);
        $this->assertSame(0, DB::table('exams')->count());
    }

    public function test_period_closure_winning_lock_blocks_enrollment_and_keeps_offering(): void
    {
        [$first, $second] = $this->workers($this->closePeriod(), $this->enroll());
        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame(0, DB::table('enrollments')->count());
        $this->assertSame(1, CourseOffering::count());
    }

    public function test_exam_winning_lock_is_preserved_when_period_closes(): void
    {
        [$first, $second] = $this->workers($this->exam(), $this->closePeriod());
        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, DB::table('exams')->count());
        $this->assertSame('INACTIVE', $this->fixture['term']->fresh()->status);
    }

    public function test_period_closure_winning_lock_blocks_exam_creation(): void
    {
        [$first, $second] = $this->workers($this->closePeriod(), $this->exam());
        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame(0, DB::table('exams')->count());
    }

    public function test_enrollment_winning_lock_is_preserved_when_period_closes(): void
    {
        [$first, $second] = $this->workers($this->enroll(), $this->closePeriod());
        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, DB::table('enrollments')->count());
        $this->assertSame('INACTIVE', $this->fixture['term']->fresh()->status);
    }

    public function test_offering_deactivation_winning_lock_blocks_new_exam(): void
    {
        $deactivate = $this->operation('PATCH', '/api/v1/admin/course-offerings/'.$this->fixture['offering']->id.'/status', ['status' => 'INACTIVE']);
        [$first, $second] = $this->workers($deactivate, $this->exam());
        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame(0, DB::table('exams')->count());
    }

    private function operation(string $method, string $path, array $payload): array
    {
        return ['user_id' => $this->fixture['user']->id, 'method' => $method, 'path' => $path, 'payload' => $payload];
    }

    private function enroll(): array
    {
        return $this->operation('POST', '/api/v1/course-offerings/'.$this->fixture['offering']->id.'/enrollments/manual', ['sisCode' => '202600001']);
    }

    private function exam(): array
    {
        return $this->operation('POST', '/api/v1/course-offerings/'.$this->fixture['offering']->id.'/exams', $this->examPayload($this->fixture['room']->id));
    }

    private function closePeriod(): array
    {
        return $this->operation('PATCH', '/api/v1/admin/academic-terms/'.$this->fixture['term']->id.'/status', ['status' => 'INACTIVE']);
    }

    private function edit(): array
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = DB::table('roles')->where('name', 'DOCENTE')->value('id');
        $user->roles()->attach($role, ['status' => 'ACTIVE']);
        $teacher = Teacher::factory()->create(['user_id' => $user->id]);

        return $this->operation('PUT', '/api/v1/admin/course-offerings/'.$this->fixture['offering']->id,
            ['teacher_id' => $teacher->id, 'subject_id' => $this->fixture['subject']->id, 'academic_term_id' => $this->fixture['term']->id]);
    }

    private function workers(array $first, array $second): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu19_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu19_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/CourseOfferingConcurrencyWorker.php'), $ready, $paths[$index], $index === 0 ? '1' : '0'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$index], base_path());
                fclose($pipes[$index][0]);
            }
            $results = [];
            foreach ($processes as $index => $process) {
                $output = stream_get_contents($pipes[$index][1]);
                $error = stream_get_contents($pipes[$index][2]);
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                $this->assertSame(0, proc_close($process), $error.' '.$output);
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            @unlink($ready);
        }
    }
}
