<?php

namespace Tests\Feature\Api\V1\Entries;

use App\Models\Exam;
use App\Services\Students\StudentQrService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class VerifierLockTest extends TestCase
{
    use RefreshDatabase, CourseOfferingFixture;

    private array $fixture;
    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create(array_replace($this->examPayload($this->fixture['room']->id), [
            'exam_date' => '2026-10-20', 'course_offering_id' => $this->fixture['offering']->id,
            'created_by' => $this->fixture['user']->id, 'status' => 'SCHEDULED',
        ]));
        DB::table('enrollments')->insert([
            'student_id' => $this->fixture['studentId'], 'course_offering_id' => $this->fixture['offering']->id,
            'status' => 'ACTIVE', 'registered_by' => $this->fixture['user']->id,
        ]);
        DB::table('exam_eligibilities')->insert([
            'exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE', 'revision' => 7,
        ]);
        Sanctum::actingAs($this->fixture['user']);
    }

    public static function entryMethods(): array
    {
        return ['manual' => [false], 'QR' => [true]];
    }

    private function entry(bool $qr)
    {
        $base = '/api/v1/exams/'.$this->exam->id.'/entry-control';
        if ($qr) {
            $token = app(StudentQrService::class)->getOrGenerateForExam($this->fixture['studentId'], $this->exam->id);

            return $this->postJson($base.'/qr', ['qr_token' => $token['token'], 'room_id' => $this->exam->room_id]);
        }

        return $this->postJson($base.'/students/'.$this->fixture['studentId'].'/entries', ['room_id' => $this->exam->room_id]);
    }

    #[DataProvider('entryMethods')]
    public function test_actual_verifier_query_uses_postgres_no_key_update_without_changing_other_locks(bool $qr): void
    {
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());
        $originalGrammar = $connection->getQueryGrammar();
        $grammar = new class extends SQLiteGrammar
        {
            public array $locks = [];

            protected function compileLock(Builder $query, $value)
            {
                // Compile the actual service queries with PostgreSQL grammar without
                // opening a PostgreSQL connection. SQLite execution proves no locking behavior.
                $this->locks[] = ['table' => $query->from, 'value' => $value,
                    'pgsql' => (new PostgresGrammar)->compileSelect($query)];

                return parent::compileLock($query, $value);
            }
        };
        $connection->setQueryGrammar($grammar);
        $before = DB::table('exam_eligibilities')->first();
        try {
            $this->entry($qr)->assertCreated()->assertJsonPath('result', 'AUTHORIZED')
                ->assertJsonPath('data.entry.verification_method', $qr ? 'QR' : 'MANUAL');
            $this->entry($qr)->assertConflict()->assertJsonPath('result', 'ALREADY_REGISTERED');
            $userLocks = array_values(array_filter($grammar->locks, fn ($lock) => $lock['table'] === 'users'));
            $this->assertCount(2, $userLocks, 'Each request must lock the verifier once, with no later upgrade.');
            foreach ($userLocks as $lock) {
                $this->assertSame('FOR NO KEY UPDATE', $lock['value']);
                $this->assertMatchesRegularExpression('/ FOR NO KEY UPDATE$/', $lock['pgsql']);
            }
            $otherTables = [];
            foreach ($grammar->locks as $lock) {
                if ($lock['table'] !== 'users') {
                    $this->assertTrue($lock['value'], 'Other row locks must remain FOR UPDATE.');
                    $otherTables[] = $lock['table'];
                }
            }
            foreach (['exams', 'exam_collaborators', 'students', 'enrollments', 'exam_eligibilities'] as $table) {
                $this->assertContains($table, $otherTables);
            }
            if ($qr) {
                $this->assertContains('student_qr_tokens', $otherTables);
            }
            $this->assertDatabaseCount('exam_entries', 1);
            $this->assertEquals($before, DB::table('exam_eligibilities')->first());
        } finally {
            $connection->setQueryGrammar($originalGrammar);
        }
    }

    #[DataProvider('entryMethods')]
    public function test_operator_deactivated_after_authentication_is_rejected_by_fresh_locked_user_read(bool $qr): void
    {
        // Keep the authenticated object active to reach the service's fresh check.
        DB::table('users')->where('id', $this->fixture['user']->id)->update(['status' => 'INACTIVE']);
        $this->assertTrue($this->fixture['user']->isActive());
        $this->entry($qr)->assertForbidden();
        $this->assertDatabaseCount('exam_entries', 0);
        $this->assertDatabaseHas('exam_eligibilities', ['exam_id' => $this->exam->id, 'status' => 'ELIGIBLE', 'revision' => 7]);
    }
}
