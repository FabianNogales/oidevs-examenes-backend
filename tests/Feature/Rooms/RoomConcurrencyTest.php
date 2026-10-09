<?php

namespace Tests\Feature\Rooms;

use App\Models\Role;
use App\Models\Room;
use App\Models\RoomImport;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Rooms\RoomImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU17_PG_CONCURRENCY') !== '1' || DB::connection()->getDriverName() !== 'pgsql'
            || ! preg_match('/^hu17_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a dedicated hu17_test_* PostgreSQL database and HU17_PG_CONCURRENCY=1.');
        }
    }

    public function test_two_simultaneous_reservations_create_only_one_exam(): void
    {
        $user = $this->user();
        $teacher = Teacher::factory()->create(['user_id' => $user->id]);
        $suffix = bin2hex(random_bytes(4));
        $subject = DB::table('subjects')->insertGetId(['code' => 'CON-'.$suffix, 'name' => 'Concurrency', 'status' => 'ACTIVE']);
        $term = DB::table('academic_terms')->insertGetId(['name' => 'CON-'.$suffix, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'ACTIVE']);
        $offering = DB::table('course_offerings')->insertGetId(['subject_id' => $subject, 'academic_term_id' => $term, 'teacher_id' => $teacher->id, 'status' => 'ACTIVE']);
        $room = Room::create(['code' => 'CON-'.$suffix, 'name' => 'Concurrent '.$suffix, 'status' => 'ACTIVE']);
        $payload = ['_offering' => $offering, 'room_id' => $room->id, 'name' => 'Concurrent exam',
            'exam_date' => now('America/La_Paz')->addDays(5)->toDateString(), 'start_time' => '10:00:00',
            'duration_minutes' => 90, 'evaluation_type' => 'partial'];
        [$first, $second] = $this->workers('reserve', $user->id, (string) $room->id, $payload);
        $this->assertSame(201, $first['status']);
        $this->assertSame(422, $second['status']);
        $this->assertArrayHasKey('room_id', $second['data']['errors']);
        $this->assertSame(1, DB::table('exams')->where('room_id', $room->id)->count());
    }

    public function test_simultaneous_confirmations_return_same_report_and_import_once(): void
    {
        $user = $this->user();
        $suffix = bin2hex(random_bytes(4));
        $csv = "codigo,nombre,descripcion,capacidad,piso\nCON-$suffix,Concurrent $suffix,,50,PB\n";
        $file = UploadedFile::fake()->createWithContent('rooms.csv', $csv);
        $preview = app(RoomImportService::class)->preview($file, $user->id);
        [$first, $second] = $this->workers('import', $user->id, $preview['preview_id'],
            ['preview_id' => $preview['preview_id'], '_file' => $file->getRealPath()]);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertEquals($first['data'], $second['data']);
        $this->assertSame(1, Room::where('code', 'CON-'.$suffix)->count());
        $this->assertSame(1, $first['data']['data']['imported_rows']);
        $this->assertSame('COMPLETED', RoomImport::where('preview_id', $preview['preview_id'])->firstOrFail()->status);
        $room = Room::where('code', 'CON-'.$suffix)->firstOrFail();
        $this->assertSame(1, DB::table('audit_logs')->where('entity_id', $room->id)->where('action', 'ROOM_CREATED')->count());
    }

    private function user(): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        foreach (['ADMINISTRADOR', 'DOCENTE'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
            $user->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        }

        return $user;
    }

    private function workers(string $mode, int $user, string $target, array $payload): array
    {
        $payloadPath = tempnam(sys_get_temp_dir(), 'hu17_payload_');
        $ready = $payloadPath.'.ready';
        file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
        $processes = [];
        $pipes = [];
        try {
            foreach (['1', '0'] as $index => $hold) {
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/RoomConcurrencyWorker.php'),
                    $mode, (string) $user, $target, $ready, $payloadPath, $hold],
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
                try {
                    $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new \RuntimeException('Worker returned invalid JSON: '.substr($output, 0, 2500).' STDERR: '.substr($error, 0, 500), 0, $exception);
                }
            }

            return $results;
        } finally {
            @unlink($payloadPath);
            @unlink($ready);
        }
    }
}
