<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcademicTermConcurrencyTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU24_PG_CONCURRENCY') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu24_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires an isolated hu24_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
    }

    public function test_overlapping_creations_on_empty_catalog_are_serialized(): void
    {
        [$first, $second] = $this->workers($this->create('I/2027', '2027-01-01', '2027-06-30'), $this->create('Otro', '2027-06-30', '2027-12-31'));
        $this->assertSame(201, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame('ACADEMIC_TERM_OVERLAP', $second['data']['code']);
        $this->assertSame(1, AcademicTerm::count());
    }

    public function test_equivalent_names_are_rejected_under_concurrent_creation(): void
    {
        [$first, $second] = $this->workers($this->create('Gestión 2027', '2027-01-01', '2027-06-30'), $this->create(' gestión 2027 ', '2028-01-01', '2028-06-30'));
        $this->assertSame(201, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame('ACADEMIC_TERM_NAME_DUPLICATE', $second['data']['code']);
        $this->assertSame(1, AcademicTerm::count());
    }

    public function test_parallel_active_creations_leave_only_one_active(): void
    {
        [$first, $second] = $this->workers($this->create('I/2027', '2027-01-01', '2027-06-30', 'ACTIVE'), $this->create('I/2028', '2028-01-01', '2028-06-30', 'ACTIVE'));
        $this->assertSame(201, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame('ACADEMIC_TERM_ACTIVE_CONFLICT', $second['data']['code']);
        $this->assertSame(1, AcademicTerm::where('status', 'ACTIVE')->count());
    }

    public function test_parallel_activations_leave_only_one_active(): void
    {
        $firstTerm = AcademicTerm::create($this->create('I/2027', '2027-01-01', '2027-06-30')['payload']);
        $secondTerm = AcademicTerm::create($this->create('I/2028', '2028-01-01', '2028-06-30')['payload']);
        [$first, $second] = $this->workers($this->statusOperation($firstTerm->id, 'ACTIVE'), $this->statusOperation($secondTerm->id, 'ACTIVE'));
        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame(1, AcademicTerm::where('status', 'ACTIVE')->count());
        $this->assertSame('INACTIVE', $secondTerm->fresh()->status);
    }

    public function test_deactivation_winning_lock_rejects_new_offering(): void
    {
        [$term, $offer] = $this->offerFixture();
        [$first, $second] = $this->workers($this->statusOperation($term->id, 'INACTIVE'), $offer);
        $this->assertSame(200, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertSame('ACADEMIC_TERM_INACTIVE', $second['data']['code']);
        $this->assertSame(0, CourseOffering::count());
    }

    public function test_offering_winning_lock_is_kept_after_deactivation(): void
    {
        [$term, $offer] = $this->offerFixture();
        [$first, $second] = $this->workers($offer, $this->statusOperation($term->id, 'INACTIVE'));
        $this->assertSame(201, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, CourseOffering::where('academic_term_id', $term->id)->count());
        $this->assertSame('INACTIVE', $term->fresh()->status);
    }

    private function create(string $name, string $start, string $end, string $status = 'INACTIVE'): array
    {
        return ['mode' => 'http', 'method' => 'POST', 'path' => '/api/v1/admin/academic-terms',
            'payload' => ['name' => $name, 'start_date' => $start, 'end_date' => $end, 'status' => $status]];
    }

    private function statusOperation(int $id, string $status): array
    {
        return ['mode' => 'http', 'method' => 'PATCH', 'path' => '/api/v1/admin/academic-terms/'.$id.'/status', 'payload' => compact('status')];
    }

    private function offerFixture(): array
    {
        $term = AcademicTerm::create($this->create('I/2027', '2027-01-01', '2027-06-30', 'ACTIVE')['payload']);
        $subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
        $teacher = Teacher::factory()->create();

        return [$term, ['mode' => 'offer', 'payload' => ['academic_term_id' => $term->id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'status' => 'ACTIVE']]];
    }

    private function workers(array $first, array $second): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu24_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ([$first, $second] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu24_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/AcademicTermConcurrencyWorker.php'),
                    (string) $this->admin->id, $ready, $paths[$index], $index === 0 ? '1' : '0'],
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
