<?php

namespace Tests\Feature\Attendance;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class AttendanceConcurrencyTest extends TestCase
{
    use EntryAnnulmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU32_PG_SNAPSHOT') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu32_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a disposable hu32_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->createIncidentFixtures();
    }

    private function base(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/attendance';
    }

    private function operation(string $path, string $method = 'GET', array $payload = []): array
    {
        return ['user_id' => $this->owner->id, 'clock' => now()->toISOString(), 'path' => $path, 'method' => $method, 'payload' => $payload];
    }

    public function test_entry_committed_between_counter_and_list_is_visible_only_on_next_snapshot(): void
    {
        [$reader, $writer] = $this->workers($this->operation($this->base().'/students'),
            $this->operation($this->manualPath(), 'POST', ['room_id' => $this->exam->room_id]));
        $this->assertSame(201, $writer['status']);
        $this->assertSame(200, $reader['status']);
        $this->assertSame(0, $reader['data']['summary']['registered']);
        $this->assertSame('PENDING', $reader['data']['data'][0]['attendance_status']);
        $this->actingAs($this->owner)->getJson($this->base().'/students')->assertOk()
            ->assertJsonPath('summary.registered', 1)->assertJsonPath('data.0.attendance_status', 'SERVER_CONFIRMED');
    }

    public function test_annulment_committed_between_counter_and_list_cannot_mix_old_and_new_data(): void
    {
        $id = $this->initialEntry();
        [$reader, $writer] = $this->workers($this->operation($this->base().'/students'),
            $this->operation($this->annulPath($id), 'POST', ['confirmed' => true, 'reason' => 'Registro incorrecto.']));
        $this->assertSame(200, $writer['status']);
        $this->assertSame(200, $reader['status']);
        $this->assertSame(1, $reader['data']['summary']['registered']);
        $this->assertSame('SERVER_CONFIRMED', $reader['data']['data'][0]['attendance_status']);
        $this->actingAs($this->owner)->getJson($this->base().'/students')->assertOk()
            ->assertJsonPath('summary.registered', 0)->assertJsonPath('data.0.attendance_status', 'PENDING');
    }

    public function test_enrollment_change_during_snapshot_keeps_denominator_and_list_consistent(): void
    {
        $change = $this->operation('/_test/roster');
        $change += ['direct' => 'deactivate_enrollment', 'offering_id' => $this->exam->course_offering_id, 'student_id' => $this->student->id];
        [$reader, $writer] = $this->workers($this->operation($this->base().'/students'), $change);
        $this->assertSame(200, $writer['status']);
        $this->assertSame(200, $reader['status']);
        $this->assertSame(1, $reader['data']['summary']['total_students']);
        $this->assertSame(1, $reader['data']['meta']['total']);
        $this->assertCount(1, $reader['data']['data']);
        $this->actingAs($this->owner)->getJson($this->base().'/students')->assertOk()
            ->assertJsonPath('summary.total_students', 0)->assertJsonCount(0, 'data');
    }

    public function test_collaborator_activity_and_global_summary_use_same_snapshot_during_annulment(): void
    {
        $id = $this->initialEntry();
        [$reader, $writer] = $this->workers($this->operation($this->base().'/collaborators'),
            $this->operation($this->annulPath($id), 'POST', ['confirmed' => true, 'reason' => 'Corregir registro.']));
        $this->assertSame(200, $writer['status']);
        $this->assertSame(200, $reader['status']);
        $this->assertSame(1, $reader['data']['summary']['registered']);
        $this->assertSame(1, $reader['data']['collaborators']['data'][0]['valid_registered']);
        $this->actingAs($this->owner)->getJson($this->base().'/collaborators')->assertOk()
            ->assertJsonPath('summary.registered', 0)->assertJsonPath('collaborators.data.0.valid_registered', 0);
    }

    private function workers(array $reader, array $writer): array
    {
        $ready = tempnam(sys_get_temp_dir(), 'hu32_snapshot_');
        unlink($ready);
        $paths = [];
        $processes = [];
        $pipes = [];
        try {
            foreach ([$reader, $writer] as $index => $operation) {
                $paths[$index] = tempnam(sys_get_temp_dir(), 'hu32_payload_');
                file_put_contents($paths[$index], json_encode($operation, JSON_THROW_ON_ERROR));
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/AttendanceConcurrencyWorker.php'),
                    $ready, $paths[$index], $index === 0 ? 'reader' : 'writer'],
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
            @unlink($ready.'.writer_done');
        }
    }
}
