<?php

namespace Tests\Feature\Subjects;

use App\Models\Career;
use App\Models\Role;
use App\Models\Subject;
use App\Models\SubjectImport;
use App\Models\User;
use App\Services\Subjects\SubjectImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU23_PG_CONCURRENCY') !== '1' || DB::connection()->getDriverName() !== 'pgsql'
            || ! preg_match('/^hu23_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a dedicated hu23_test_* PostgreSQL database.');
        }
    }

    public function test_simultaneous_confirmation_returns_same_report_and_imports_once(): void
    {
        [$user, $suffix, $careers] = $this->fixtures();
        [$file, $preview] = $this->preview($user, $suffix, $careers[0]);
        $payload = ['preview_id' => $preview, '_file' => $file->getRealPath()];
        [$first, $second] = $this->workers('import', $user->id, [$payload, $payload]);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertEquals($first['data'], $second['data']);
        $subject = Subject::where('code', 'CON-'.$suffix)->sole();
        $this->assertSame(1, $subject->careers()->count());
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', Subject::class)->where('entity_id', $subject->id)->count());
        $this->assertSame('COMPLETED', SubjectImport::where('preview_id', $preview)->first()->status);
    }

    public function test_different_previews_preserve_both_associations_for_same_subject(): void
    {
        [$user, $suffix, $careers] = $this->fixtures();
        $payloads = [];
        foreach ($careers as $career) {
            [$file, $preview] = $this->preview($user, $suffix, $career);
            $payloads[] = ['preview_id' => $preview, '_file' => $file->getRealPath()];
            $files[] = $file;
        }
        [$first, $second] = $this->workers('import-race', $user->id, $payloads);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, Subject::where('code', 'CON-'.$suffix)->count());
        $this->assertSame(2, Subject::where('code', 'CON-'.$suffix)->sole()->careers()->count());
    }

    public function test_concurrent_manual_creation_translates_database_uniqueness_into_field_error(): void
    {
        [$user, $suffix, $careers] = $this->fixtures();
        $payloads = array_map(fn ($career) => ['code' => 'CON-'.$suffix, 'name' => 'Concurrent subject', 'career_ids' => [$career->id]], $careers);
        [$first, $second] = $this->workers('create', $user->id, $payloads);
        $this->assertSame(422, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertArrayHasKey('code', $first['data']['errors']);
        $subject = Subject::where('code', 'CON-'.$suffix)->sole();
        $this->assertSame(1, $subject->careers()->count());
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', Subject::class)->where('entity_id', $subject->id)->count());
    }

    private function fixtures(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $careers = [];
        foreach (['A', 'B'] as $prefix) {
            $careers[] = Career::create(['code' => $prefix.$suffix, 'name' => $prefix.$suffix, 'status' => 'ACTIVE']);
        }

        return [$user, $suffix, $careers];
    }

    private function preview(User $user, string $suffix, Career $career): array
    {
        $file = UploadedFile::fake()->createWithContent('subjects.csv', "codigo_materia,nombre_materia,codigo_carrera\nCON-$suffix,Concurrent subject,$career->code\n");
        $preview = app(SubjectImportService::class)->preview($file, $user->id);

        return [$file, $preview['preview_id']];
    }

    private function workers(string $mode, int $userId, array $payloads): array
    {
        $paths = [];
        $ready = tempnam(sys_get_temp_dir(), 'hu23_ready_');
        unlink($ready);
        $processes = [];
        $pipes = [];
        try {
            foreach ($payloads as $index => $payload) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu23_payload_');
                file_put_contents($paths[$index], json_encode($payload, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/SubjectConcurrencyWorker.php'),
                    $mode, (string) $userId, $ready, $paths[$index], $index === 0 ? '1' : '0'],
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
