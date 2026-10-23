<?php

namespace Tests\Feature\Api\V1\Eligibility;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class EligibilityRevisionTest extends TestCase
{
    use RefreshDatabase, CourseOfferingFixture;

    private array $fixture;
    private Exam $exam;
    private ExamEligibility $eligibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $this->exam = Exam::create($this->examPayload($this->fixture['room']->id) + [
            'course_offering_id' => $this->fixture['offering']->id, 'created_by' => $this->fixture['user']->id,
            'status' => 'SCHEDULED',
        ]);
        $this->eligibility = ExamEligibility::create([
            'exam_id' => $this->exam->id, 'student_id' => $this->fixture['studentId'], 'status' => 'ELIGIBLE',
        ])->fresh();
        Sanctum::actingAs($this->fixture['user']);
    }

    private function base(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/eligibilities';
    }

    private function change(array $payload)
    {
        return $this->patchJson($this->base().'/'.$this->fixture['studentId'], $payload);
    }

    private function csv(string $rows)
    {
        return $this->postJson($this->base().'/bulk', ['file' => UploadedFile::fake()->createWithContent(
            'habilitaciones.csv', "sis_code,status,reason_code,observations\n".$rows
        )]);
    }

    public function test_migration_preserves_all_existing_values_and_initializes_revision_one(): void
    {
        $this->eligibility->update(['status' => 'INELIGIBLE', 'reason_code' => 'OTHER', 'reason' => 'Motivo previo',
            'observations' => 'Conservar', 'evaluated_by' => $this->fixture['user']->id, 'evaluated_at' => now()->subDay()]);
        $migration = require database_path('migrations/2026_10_20_000001_add_revision_to_exam_eligibilities.php');
        $before = $this->eligibility->fresh()->getRawOriginal();
        unset($before['revision']);
        $migration->down();
        $migration->up();
        $after = $this->eligibility->fresh()->getRawOriginal();
        $this->assertSame(1, $after['revision']);
        unset($after['revision']);
        $this->assertSame($before, $after);
        $this->getJson($this->base())->assertOk()->assertJsonPath('data.0.revision', 1);
    }

    public function test_same_second_stale_form_cannot_erase_manual_decision_and_current_form_can_update(): void
    {
        $this->assertSame(1, $this->eligibility->revision);
        $this->change(['status' => 'INELIGIBLE', 'reason_code' => 'OTHER', 'observations' => 'Decisión reciente', 'revision' => 1])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $before = $this->eligibility->fresh()->getRawOriginal();
        $this->change(['status' => 'ELIGIBLE', 'revision' => 1])
            ->assertConflict()->assertJsonPath('code', 'ELIGIBILITY_CONFLICT');
        $this->assertSame($before, $this->eligibility->fresh()->getRawOriginal());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ELIGIBILITY_UPDATED')->count());
        $this->change(['status' => 'ELIGIBLE', 'revision' => 2, 'evaluated_by' => 999, 'evaluated_at' => '2000-01-01'])
            ->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.reason', null);
        $after = $this->eligibility->fresh();
        $this->assertSame($before['updated_at'], $after->getRawOriginal('updated_at'));
        $this->assertSame($this->fixture['user']->id, $after->evaluated_by);
        $logs = AuditLog::where('action', 'EXAM_ELIGIBILITY_UPDATED')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(1, $logs[0]->old_values['revision']);
        $this->assertSame(2, $logs[0]->new_values['revision']);
        $this->assertSame(3, $logs[1]->new_values['revision']);
    }

    public function test_missing_revision_is_rejected_without_writes(): void
    {
        $before = $this->eligibility->getRawOriginal();
        $this->change(['status' => 'ELIGIBLE'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame($before, $this->eligibility->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_ELIGIBILITY_UPDATED')->count());
    }

    public static function invalidRevisions(): array
    {
        return [[null], [0], [-1], [1.5], ['1'], [true], [[]], [2147483648]];
    }

    #[DataProvider('invalidRevisions')]
    public function test_invalid_revision_is_rejected_without_partial_changes(mixed $revision): void
    {
        $before = $this->eligibility->getRawOriginal();
        $this->change(['status' => 'ELIGIBLE', 'revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame($before, $this->eligibility->fresh()->getRawOriginal());
    }

    public function test_client_cannot_select_a_future_revision(): void
    {
        $this->change(['status' => 'ELIGIBLE', 'revision' => 99])->assertConflict();
        $this->assertSame(1, $this->eligibility->fresh()->revision);
    }

    public function test_authorization_and_missing_record_are_checked_as_before(): void
    {
        $other = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $other->roles()->attach(DB::table('roles')->where('name', 'DOCENTE')->value('id'), ['status' => 'ACTIVE']);
        Sanctum::actingAs($other);
        $this->change(['status' => 'ELIGIBLE'])->assertForbidden();
        Sanctum::actingAs($this->fixture['user']);
        $this->patchJson($this->base().'/999999', ['status' => 'ELIGIBLE', 'revision' => 1])->assertNotFound();
    }

    public function test_csv_increments_only_accepted_rows_and_invalidates_old_forms_even_for_same_state(): void
    {
        $this->eligibility->forceFill(['revision' => 3])->save();
        $this->csv("202600001,ELIGIBLE,,\n999999999,INELIGIBLE,OTHER,Ajeno\n")
            ->assertOk()->assertExactJson(['message' => 'Carga de habilitaciones procesada.', 'data' => [
                'total_rows' => 2, 'updated_rows' => 1, 'failed_rows' => 1,
                'errors' => [['row' => 3, 'sis_code' => '999999999', 'messages' => [
                    'sis_code' => ['El estudiante no tiene una habilitación asociada a este examen.'],
                ]]],
            ]]);
        $this->assertSame(4, $this->eligibility->fresh()->revision);
        $before = $this->eligibility->fresh()->getRawOriginal();
        $this->change(['status' => 'ELIGIBLE', 'revision' => 3])->assertConflict();
        $this->csv("202600001,INELIGIBLE,INVALID,Rechazado\n")->assertOk()->assertJsonPath('data.updated_rows', 0);
        $this->assertSame($before, $this->eligibility->fresh()->getRawOriginal());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EXAM_ELIGIBILITY_UPDATED')->count());
        // HU12 has no preview endpoint; merely selecting a file or reading the list must not write.
        $this->getJson($this->base())->assertOk()->assertJsonPath('data.0.revision', 4);
        $this->assertSame($before, $this->eligibility->fresh()->getRawOriginal());
    }

    public static function persistenceFailures(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('persistenceFailures')]
    public function test_audit_failure_rolls_back_revision_decision_and_successful_audit(bool $csv): void
    {
        $before = $this->eligibility->getRawOriginal();
        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('log')->once()->andThrow(new \RuntimeException('Simulated audit failure'));
        });
        ($csv ? $this->csv("202600001,INELIGIBLE,OTHER,No persistir\n")
            : $this->change(['status' => 'INELIGIBLE', 'reason_code' => 'OTHER', 'revision' => 1]))->assertStatus(500);
        $this->assertSame($before, $this->eligibility->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'EXAM_ELIGIBILITY_UPDATED')->count());
    }

    public static function nonPositiveDatabaseValues(): array
    {
        return [[0], [-1], [null]];
    }

    #[DataProvider('nonPositiveDatabaseValues')]
    public function test_database_rejects_null_or_non_positive_revisions(?int $revision): void
    {
        try {
            DB::transaction(fn () => DB::table('exam_eligibilities')->where('id', $this->eligibility->id)->update(['revision' => $revision]));
            $this->fail('The revision constraint must reject this value.');
        } catch (QueryException) {
            $this->assertSame(1, $this->eligibility->fresh()->revision);
        }
    }
}
