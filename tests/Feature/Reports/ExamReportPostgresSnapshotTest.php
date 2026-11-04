<?php

namespace Tests\Feature\Reports;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class ExamReportPostgresSnapshotTest extends TestCase
{
    use EntryAnnulmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('HU33_PG_SNAPSHOT') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! preg_match('/^hu33_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Requires a disposable hu33_test_* PostgreSQL database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        config(['exam_reports.cache_store' => 'array']);
        $this->createIncidentFixtures();
    }

    public function test_generation_reads_data_and_totals_from_one_readonly_repeatable_snapshot(): void
    {
        $this->initialEntry();
        $modes = [];
        DB::listen(function (QueryExecuted $query) use (&$modes) {
            if (str_contains($query->sql, '"exam_entries" as "e"') || str_contains($query->sql, 'count(*) as aggregate from "enrollments"')) {
                $modes[] = [DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
                    DB::selectOne('SHOW transaction_read_only')->transaction_read_only];
            }
        });
        $this->actingAs($this->owner)->getJson('/api/v1/exams/'.$this->exam->id.'/reports/ATTENDANCE')->assertOk()
            ->assertJsonPath('report.total_records', 1)->assertJsonPath('report.total_participants', 1);
        $this->assertCount(2, $modes);
        $this->assertSame([['repeatable read', 'on'], ['repeatable read', 'on']], $modes);
        $this->assertSame(0, DB::transactionLevel());
    }
}
