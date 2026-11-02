<?php

namespace Tests\Feature\Attendance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class AttendancePostgresSnapshotTest extends TestCase
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

    public function test_production_request_uses_readonly_repeatable_snapshot_without_outer_test_transaction(): void
    {
        $this->initialEntry();
        $modes = null;
        DB::listen(function (QueryExecuted $query) use (&$modes) {
            if (str_contains($query->sql, '"enrollments" as "en"')) {
                $modes = [DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
                    DB::selectOne('SHOW transaction_read_only')->transaction_read_only];
            }
        });
        $this->actingAs($this->owner)->getJson('/api/v1/exams/'.$this->exam->id.'/attendance/students')
            ->assertOk()->assertJsonPath('summary.registered', 1)->assertJsonPath('data.0.attendance_status', 'SERVER_CONFIRMED');
        $this->assertSame(['repeatable read', 'on'], $modes);
        $this->assertSame(0, DB::transactionLevel());
    }
}
