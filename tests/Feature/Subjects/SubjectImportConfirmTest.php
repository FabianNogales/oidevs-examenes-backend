<?php

namespace Tests\Feature\Subjects;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Role;
use App\Models\RoomImport;
use App\Models\Subject;
use App\Models\SubjectImport;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Subjects\SubjectImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubjectImportConfirmTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "codigo_materia,nombre_materia,codigo_carrera\n";
    private const ENDPOINT = '/api/v1/admin/subjects/import/confirm';
    private User $admin;
    private Career $career;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::create(['name' => 'ADMINISTRADOR', 'status' => 'ACTIVE']);
        $this->admin->roles()->attach($role->id, ['assigned_at' => now(), 'status' => 'ACTIVE']);
        $this->startSession();
        $this->admin->forceFill(['active_session_id' => $this->app['session']->getId()])->save();
        $this->actingAs($this->admin)->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
    }

    public function test_partial_import_creates_one_subject_for_multiple_careers_and_audits_once(): void
    {
        Career::create(['code' => 'INF', 'name' => 'Informática', 'status' => 'ACTIVE']);
        [$file, $id] = $this->preview("new,Programación,SIS\nNEW,Programación,INF\nNEW,Programación,SIS\nBAD,Otra materia,UNKNOWN\n");
        $response = $this->confirm($file, $id)->assertOk()
            ->assertJsonPath('data.preview_id', $id)
            ->assertJsonPath('data.summary', ['total' => 4, 'imported' => 2, 'failed' => 1, 'omitted' => 1])
            ->assertJsonPath('data.rows.0.status', 'IMPORTED')->assertJsonPath('data.rows.2.status', 'OMITTED')
            ->assertJsonPath('data.rows.3.status', 'ERROR');
        $this->assertSame($response->json('data.rows.0.subject_id'), $response->json('data.rows.1.subject_id'));
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('career_subject', 2);
        $this->assertDatabaseHas('subjects', ['code' => 'NEW', 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame('SUBJECT_CREATED', AuditLog::first()->action);
        $import = SubjectImport::where('preview_id', $id)->firstOrFail();
        $this->assertSame('COMPLETED', $import->status);
        $this->assertEquals($response->json('data'), $import->result_report);
        $this->assertNotNull($import->completed_at);
        $this->assertDatabaseCount('room_imports', 0);
    }

    public function test_existing_subject_only_adds_missing_associations(): void
    {
        $other = Career::create(['code' => 'INF', 'name' => 'Informática', 'status' => 'ACTIVE']);
        $subject = Subject::create(['code' => 'OLD', 'name' => 'Materia anterior', 'status' => 'ACTIVE']);
        $subject->careers()->attach($this->career->id);
        [$file, $id] = $this->preview("OLD,Materia anterior,SIS\nOLD,Materia anterior,INF\n");
        $this->confirm($file, $id)->assertOk()->assertJsonPath('data.summary.imported', 1)->assertJsonPath('data.summary.omitted', 1);
        $this->assertEqualsCanonicalizing([$this->career->id, $other->id], $subject->careers()->pluck('careers.id')->all());
        $audit = AuditLog::firstOrFail();
        $this->assertSame('SUBJECT_UPDATED', $audit->action);
        $this->assertSame([$this->career->id], $audit->old_values['career_ids']);
        $this->assertDatabaseCount('subjects', 1);
    }

    public function test_repeat_confirmation_returns_exact_persisted_result_even_after_expiry(): void
    {
        [$file, $id] = $this->preview("NEW,Programación,SIS\n");
        $first = $this->confirm($file, $id)->assertOk()->json();
        $this->travel(31)->minutes();
        $this->confirm($file, $id)->assertOk()->assertExactJson($first);
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('career_subject', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_invalid_identifiers_other_module_owner_hash_expiry_and_state_are_rejected(): void
    {
        [$file, $id] = $this->preview("NEW,Programación,SIS\n");
        $this->postJson(self::ENDPOINT, ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('preview_id');
        $this->confirm($file, 'not-a-uuid')->assertUnprocessable();
        $this->confirm($file, (string) Str::uuid())->assertStatus(409);
        $room = RoomImport::create(['preview_id' => (string) Str::uuid(), 'user_id' => $this->admin->id,
            'file_hash' => hash_file('sha256', $file->getRealPath()), 'status' => 'PENDING', 'expires_at' => now()->addMinutes(30), 'preview_report' => []]);
        $this->confirm($file, $room->preview_id)->assertStatus(409);
        $import = SubjectImport::where('preview_id', $id)->firstOrFail();
        $import->update(['user_id' => User::factory()->create()->id]);
        $this->confirm($file, $id)->assertForbidden();
        $import->update(['user_id' => $this->admin->id]);
        $changed = UploadedFile::fake()->createWithContent('subjects.csv', self::HEADER."OTHER,Programación,SIS\n");
        $this->confirm($changed, $id)->assertStatus(409);
        $import->update(['status' => 'PROCESSING']);
        $this->confirm($file, $id)->assertStatus(409);
        $import->update(['status' => 'PENDING', 'expires_at' => now()->subSecond()]);
        $this->confirm($file, $id)->assertStatus(410);
        $this->assertDatabaseCount('subjects', 0);
    }

    public function test_preview_without_valid_rows_cannot_be_confirmed(): void
    {
        [$file, $id] = $this->preview("BAD,Programación,UNKNOWN\n");
        $this->confirm($file, $id)->assertStatus(409);
        $this->assertSame('PENDING', SubjectImport::where('preview_id', $id)->first()->status);
    }

    public function test_revalidation_detects_changed_references_without_creating_orphans(): void
    {
        [$file, $id] = $this->preview("NEW,Programación,SIS\n");
        $this->career->update(['status' => 'INACTIVE']);
        $this->confirm($file, $id)->assertOk()->assertJsonPath('data.summary.failed', 1)->assertJsonPath('data.summary.imported', 0);
        $this->assertDatabaseCount('subjects', 0);
        $this->assertDatabaseCount('career_subject', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_revalidation_rejects_new_name_conflict_or_inactive_subject(): void
    {
        [$file, $id] = $this->preview("NEW,Programación,SIS\n");
        $subject = Subject::create(['code' => 'NEW', 'name' => 'Otro nombre', 'status' => 'ACTIVE']);
        $this->confirm($file, $id)->assertOk()->assertJsonPath('data.summary.failed', 1);
        $subject->update(['name' => 'Programación', 'status' => 'INACTIVE']);
        [$file, $id] = $this->preview("NEW,Programación,SIS\nOK,Otra materia,SIS\n");
        $this->confirm($file, $id)->assertOk()->assertJsonPath('data.summary.failed', 1)->assertJsonPath('data.summary.imported', 1);
        $this->assertSame('INACTIVE', $subject->refresh()->status);
        $this->assertSame('Programación', $subject->name);
    }

    public function test_association_added_after_preview_is_omitted_without_audit(): void
    {
        $subject = Subject::create(['code' => 'OLD', 'name' => 'Materia anterior', 'status' => 'ACTIVE']);
        [$file, $id] = $this->preview("OLD,Materia anterior,SIS\n");
        $subject->careers()->attach($this->career->id);
        $this->confirm($file, $id)->assertOk()->assertJsonPath('data.summary.omitted', 1)->assertJsonPath('data.summary.imported', 0);
        $this->assertDatabaseCount('career_subject', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_failure_rolls_back_all_changes_and_leaves_preview_retryable(): void
    {
        [$file, $id] = $this->preview("A,Primera materia,SIS\nB,Segunda materia,SIS\n");
        $calls = 0;
        $this->mock(AuditLogService::class)->shouldReceive('log')->twice()->andReturnUsing(function (...$args) use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('Audit unavailable');
            }

            return (new AuditLogService)->log(...$args);
        });
        $request = Request::create(self::ENDPOINT, 'POST');
        $request->setUserResolver(fn () => $this->admin);
        try {
            app(SubjectImportService::class)->confirm($file, $id, $request);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('subjects', 0);
        $this->assertDatabaseCount('career_subject', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $import = SubjectImport::where('preview_id', $id)->firstOrFail();
        $this->assertSame('PENDING', $import->status);
        $this->assertNull($import->result_report);
    }

    public function test_confirmation_requires_active_administrator(): void
    {
        [$file, $id] = $this->preview("NEW,Programación,SIS\n");
        $this->admin->roles()->detach();
        $this->confirm($file, $id)->assertForbidden();
        $this->assertDatabaseCount('subjects', 0);
    }

    private function preview(string $rows): array
    {
        $file = UploadedFile::fake()->createWithContent('subjects.csv', self::HEADER.$rows);
        $response = $this->postJson('/api/v1/admin/subjects/import/preview', ['file' => $file])->assertOk();

        return [$file, $response->json('data.preview_id')];
    }

    private function confirm(UploadedFile $file, string $id)
    {
        return $this->postJson(self::ENDPOINT, ['file' => $file, 'preview_id' => $id]);
    }
}
