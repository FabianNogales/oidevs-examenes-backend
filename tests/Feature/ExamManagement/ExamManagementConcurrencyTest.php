<?php

namespace Tests\Feature\ExamManagement;

use App\Models\Exam;
use App\Models\Role;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Services\Students\StudentQrService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class ExamManagementConcurrencyTest extends TestCase
{
    use CourseOfferingFixture;

    private array $fixture;

    private Exam $exam;

    private User $studentUser;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU18_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu18_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires an isolated hu18_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create(array_replace($this->examPayload($this->fixture['room']->id), ['exam_date' => '2026-10-16',
            'start_time' => '10:00:00', 'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED']));
        $this->version = $this->exam->fresh()->updated_at->toISOString();
        $this->studentUser = User::findOrFail(DB::table('students')->where('id', $this->fixture['studentId'])->value('user_id'));
        $role = Role::firstOrCreate(['name' => 'ESTUDIANTE'], ['status' => 'ACTIVE']);
        $this->studentUser->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        DB::table('enrollments')->insert(['course_offering_id' => $this->fixture['offering']->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id]);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE']);
    }

    public function test_two_cancellations_with_same_version_apply_once_and_audit_once(): void
    {
        [$first, $second] = $this->workers($this->cancel(), $this->cancel());
        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('EXAM_ALREADY_CANCELLED', $second['data']['code']);
        $this->assertCancelledOnce();
    }

    public function test_generation_winning_exam_lock_is_revoked_by_subsequent_cancellation(): void
    {
        [$first, $second] = $this->workers($this->generate(), $this->cancel());
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, StudentQrToken::count());
        $this->assertSame(0, StudentQrToken::where('status', 'ACTIVE')->count());
        $this->assertNotNull(StudentQrToken::firstOrFail()->revoked_at);
        $this->assertCancelledOnce();
    }

    public function test_cancellation_winning_exam_lock_prevents_generation_of_new_qr(): void
    {
        [$first, $second] = $this->workers($this->cancel(), $this->generate());
        $this->assertSame(200, $first['status']);
        $this->assertSame(403, $second['status']);
        $this->assertSame(0, StudentQrToken::count());
        $this->assertCancelledOnce();
    }

    public function test_cancellation_winning_exam_lock_rejects_old_signed_qr_entry(): void
    {
        $scan = $this->scan();
        [$first, $second] = $this->workers($this->cancel(), $scan);
        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame(0, DB::table('exam_entries')->count());
        $this->assertCancelledOnce();
    }

    public function test_cancellation_releases_room_before_waiting_new_exam_reservation(): void
    {
        [$first, $second] = $this->workers($this->cancel(), $this->reserve());
        $this->assertSame(200, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(2, Exam::count());
        $this->assertSame(1, Exam::where('status', 'SCHEDULED')->count());
        $this->assertCancelledOnce();
    }

    public function test_reservation_before_cancellation_still_respects_existing_occupation(): void
    {
        [$first, $second] = $this->workers($this->reserve(), $this->cancel());
        $this->assertSame(422, $first['status']);
        $this->assertArrayHasKey('room_id', $first['data']['errors']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, Exam::count());
        $this->assertCancelledOnce();
    }

    public function test_period_closure_winning_global_lock_blocks_cancellation_without_revoking_qr(): void
    {
        app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id);
        $close = $this->operation('PATCH', '/api/v1/admin/academic-terms/'.$this->fixture['term']->id.'/status', ['status' => 'INACTIVE'], 'global');
        unset($close['exam_id']);
        [$first, $second] = $this->workers($close, $this->cancel());
        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame('SCHEDULED', $this->exam->fresh()->status);
        $this->assertSame(1, StudentQrToken::where('status', 'ACTIVE')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->count());
    }

    private function assertCancelledOnce(): void
    {
        $this->assertSame('CANCELLED', $this->exam->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_CANCELLED')->count());
        $this->assertSame(1, DB::table('enrollments')->count());
        $this->assertSame(1, DB::table('exam_eligibilities')->where('exam_id', $this->exam->id)->count());
    }

    private function operation(string $method, string $path, array $payload = [], string $lock = 'exam'): array
    {
        return ['user_id' => $this->fixture['user']->id, 'method' => $method, 'path' => $path, 'payload' => $payload,
            'lock' => $lock, 'exam_id' => $this->exam->id, 'clock' => now()->toISOString()];
    }

    private function cancel(): array
    {
        return $this->operation('POST', '/api/v1/teacher/exams/'.$this->exam->id.'/cancel', ['expected_updated_at' => $this->version], 'global');
    }

    private function generate(): array
    {
        return array_replace($this->operation('GET', '/api/v1/students/exams/'.$this->exam->id.'/qr'), ['user_id' => $this->studentUser->id]);
    }

    private function scan(): array
    {
        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id);

        return $this->operation('POST', '/api/v1/exams/'.$this->exam->id.'/entry-control/qr', ['qr_token' => $qr['token'], 'room_id' => $this->fixture['room']->id]);
    }

    private function reserve(): array
    {
        return $this->operation('POST', '/api/v1/course-offerings/'.$this->fixture['offering']->id.'/exams', array_replace($this->examPayload($this->fixture['room']->id), ['exam_date' => '2026-10-16']), 'global');
    }

    private function workers(array $first, array $second): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu18_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu18_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/ExamManagementConcurrencyWorker.php'), $ready, $paths[$index], $index === 0 ? '1' : '0'],
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
            @unlink($ready.'.contender');
        }
    }
}
