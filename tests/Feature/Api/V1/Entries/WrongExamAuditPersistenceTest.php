<?php

namespace Tests\Feature\Api\V1\Entries;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\StudentQrToken;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class WrongExamAuditPersistenceTest extends TestCase
{
    // No enclosing test transaction: verify actual commits after the HTTP request.
    use DatabaseMigrations, CourseOfferingFixture;

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('This commit-boundary test requires isolated SQLite :memory:.');
        }
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function () {
            // Discard this memory database rather than exercising unrelated migration down() paths.
            DB::purge();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_wrong_exam_audit_commits_separately_and_survives_subsequent_requests(): void
    {
        $fixture = $this->offeringFixture();
        $exam = Exam::create($this->examPayload($fixture['room']->id) + [
            'course_offering_id' => $fixture['offering']->id,
            'created_by' => $fixture['user']->id, 'status' => 'SCHEDULED',
        ]);
        $other = $exam->replicate();
        $other->save();
        $uuid = (string) Str::uuid();
        StudentQrToken::create(['student_id' => $fixture['studentId'], 'exam_id' => $other->id,
            'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()]);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'
            .$encode(['v' => 2, 'exp' => now()->addHour()->timestamp, 'jti' => $uuid]);
        $jwt = $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');

        $commits = [];
        Event::listen(TransactionCommitted::class, function () use (&$commits) {
            $commits[] = ['level' => DB::transactionLevel(),
                'events' => AuditLog::where('action', 'ENTRY_QR_WRONG_EXAM')->count()];
        });
        $this->assertSame(0, DB::transactionLevel());
        $this->actingAs($fixture['user']);
        $endpoint = '/api/v1/exams/'.$exam->id.'/entry-control';
        $this->postJson($endpoint.'/qr', ['qr_token' => $jwt, 'room_id' => $exam->room_id])
            ->assertUnprocessable()->assertJsonPath('result', 'WRONG_EXAM')->assertJsonPath('data', null);

        $this->assertSame([['level' => 0, 'events' => 0], ['level' => 0, 'events' => 1]], $commits);
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $event = AuditLog::where('action', 'ENTRY_QR_WRONG_EXAM')->sole();
        $this->assertSame($exam->id, $event->entity_id);
        $this->assertSame($other->id, $event->new_values['qr_exam_id']);
        $this->getJson($endpoint.'/entries')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame($event->id, AuditLog::where('action', 'ENTRY_QR_WRONG_EXAM')->sole()->id);
        $this->assertDatabaseCount('exam_entries', 0);
    }
}
