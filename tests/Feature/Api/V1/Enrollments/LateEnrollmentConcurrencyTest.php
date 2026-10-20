<?php

namespace Tests\Feature\Api\V1\Enrollments;

use App\Models\Exam;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class LateEnrollmentConcurrencyTest extends TestCase
{
    use CourseOfferingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EIDA_FIX014_PG_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Pending: explicitly enabled, isolated PostgreSQL is required.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertNull(config('database.connections.pgsql.url'));
        $this->assertNull(env('DATABASE_URL'));
        $this->assertMatchesRegularExpression('/^hu18_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName());
        $this->assertSame('pgsql', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame(DB::connection()->getDatabaseName(), DB::selectOne('select current_database() as name')->name);
        // Reuse the existing worker's isolated database convention. Never migrate an unverified connection.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'America/La_Paz'));
    }

    public static function orderings(): array
    {
        return ['scheduling first' => [true], 'enrollment first' => [false]];
    }

    #[DataProvider('orderings')]
    public function test_concurrent_scheduling_and_enrollment_create_exactly_one_eligibility(bool $scheduleFirst): void
    {
        $fixture = $this->offeringFixture();
        $base = '/api/v1/course-offerings/'.$fixture['offering']->id;
        $common = ['user_id' => $fixture['user']->id, 'method' => 'POST', 'lock' => 'global', 'clock' => now()->toISOString()];
        $schedule = $common + ['path' => $base.'/exams', 'payload' => array_replace(
            $this->examPayload($fixture['room']->id), ['exam_date' => '2026-10-20']
        )];
        $enroll = $common + ['path' => $base.'/enrollments/manual', 'payload' => ['sisCode' => '202600001']];
        $results = $this->workers(...($scheduleFirst ? [$schedule, $enroll] : [$enroll, $schedule]));
        foreach ($results as $result) {
            $this->assertSame(201, $result['status'], json_encode($result));
        }
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseCount('exams', 1);
        $this->assertDatabaseCount('exam_eligibilities', 1);
        $this->assertDatabaseHas('exam_eligibilities', [
            'exam_id' => Exam::firstOrFail()->id, 'student_id' => $fixture['studentId'], 'status' => 'ELIGIBLE',
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', 'Enrollment')->count());
    }

    private function workers(array $first, array $second): array
    {
        $ready = tempnam(sys_get_temp_dir(), 'fix014_ready_');
        unlink($ready);
        $paths = [];
        $processes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[] = $path = tempnam(sys_get_temp_dir(), 'fix014_payload_');
                file_put_contents($path, json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[] = $process = new Process([
                    PHP_BINARY, base_path('tests/Support/ExamManagementConcurrencyWorker.php'),
                    $ready, $path, $index === 0 ? '1' : '0',
                ], base_path(), null, null, 20);
                $process->start();
            }
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput().' '.$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach (array_merge($paths, [$ready, $ready.'.contender']) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
