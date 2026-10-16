<?php

namespace Tests\Feature\ExamManagement;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class ExamManagementReadTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private const BASE = '/api/v1/teacher/exams';

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        Sanctum::actingAs($this->fixture['user']);
    }

    public function test_empty_default_and_out_of_range_page_preserve_requested_metadata(): void
    {
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.total', 0);
        $this->exam();
        $this->getJson(self::BASE.'?page=3')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.total', 1);
    }

    public function test_upcoming_uses_end_in_la_paz_including_today_in_progress_and_overnight(): void
    {
        $ended = $this->exam(['exam_date' => '2026-10-16', 'start_time' => '08:00:00', 'duration_minutes' => 60]);
        $ongoing = $this->exam(['exam_date' => '2026-10-16', 'start_time' => '08:30:00', 'status' => 'IN_PROGRESS']);
        $overnight = $this->exam(['exam_date' => '2026-10-15', 'start_time' => '23:30:00', 'duration_minutes' => 600]);
        $legacy = $this->exam(['status' => 'ACTIVE']);
        $future = $this->exam(['exam_date' => '2026-10-22']);
        $this->exam(['status' => 'CANCELLED']);
        $this->exam(['status' => 'COMPLETED']);
        $this->exam(['status' => 'FINISHED']);
        $this->exam(['status' => 'UNKNOWN']);
        $response = $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame([$overnight->id, $ongoing->id, $legacy->id, $future->id], array_column($response->json('data'), 'id'));
        $this->assertNotContains($ended->id, array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.1.can_cancel', false)->assertJsonPath('data.2.can_edit', true);
    }

    public function test_past_includes_exams_ending_today_or_closed_by_state_and_excludes_cancelled(): void
    {
        $ended = $this->exam(['exam_date' => '2026-10-16', 'start_time' => '08:00:00']);
        $earlier = $this->exam(['exam_date' => '2026-10-15']);
        $completed = $this->exam(['status' => 'COMPLETED']);
        $finished = $this->exam(['status' => 'FINISHED', 'exam_date' => '2026-10-22']);
        $this->exam(['exam_date' => '2026-10-15', 'status' => 'CANCELLED']);
        $this->exam(['status' => 'IN_PROGRESS']);
        $this->exam();
        $response = $this->getJson(self::BASE.'?view=past')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame([$finished->id, $completed->id, $ended->id, $earlier->id], array_column($response->json('data'), 'id'));
    }

    public function test_cancelled_and_all_preserve_history_of_inactive_offering_subject_and_period(): void
    {
        $past = $this->exam(['status' => 'CANCELLED', 'exam_date' => '2025-01-01']);
        $future = $this->exam(['status' => 'CANCELLED']);
        $this->exam();
        foreach (['offering', 'subject', 'term'] as $key) {
            $this->fixture[$key]->update(['status' => 'INACTIVE']);
        }
        $response = $this->getJson(self::BASE.'?view=cancelled')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$future->id, $past->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.status', 'CANCELLED')->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.0.can_cancel', false);
        $this->getJson(self::BASE.'?view=all')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson(self::BASE.'/'.$past->id)->assertOk()->assertJsonPath('data.id', $past->id)->assertJsonPath('data.academic_term_name', 'I/2027');
    }

    public function test_search_is_trimmed_case_insensitive_parameterized_and_literal_for_wildcards(): void
    {
        $exact = $this->exam(['name' => 'Parcial 100%_! Redes']);
        $this->exam(['name' => 'Otro parcial']);
        foreach (['  REDES  ', '100%_!', 'INF-01', 'PROGRAMACIÓN'] as $term) {
            $response = $this->getJson(self::BASE.'?view=all&search='.rawurlencode($term))->assertOk();
            $this->assertContains($exact->id, array_column($response->json('data'), 'id'));
            $this->assertSame(in_array($term, ['INF-01', 'PROGRAMACIÓN']) ? 2 : 1, $response->json('meta.total'));
        }
        $this->getJson(self::BASE.'?search='.rawurlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_pagination_is_stable_counts_before_paging_and_never_returns_another_teachers_exam(): void
    {
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->exam()->id;
        }
        $other = $this->otherTeacherExam();
        $first = $this->getJson(self::BASE.'?per_page=10&page=1&teacher_id='.$other->courseOffering->teacher_id)->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.current_page', 1);
        $second = $this->getJson(self::BASE.'?page=2')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.current_page', 2);
        $third = $this->getJson(self::BASE.'?page=3')->assertOk()->assertJsonCount(1, 'data');
        $actual = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($third->json('data'), 'id'));
        $this->assertSame($ids, $actual);
        $this->assertCount(21, array_unique($actual));
        $this->assertNotContains($other->id, $actual);
        $all = $this->getJson(self::BASE.'?view=all')->assertOk();
        $this->assertSame(array_slice(array_reverse($ids), 0, 10), array_column($all->json('data'), 'id'));
    }

    public function test_detail_returns_exact_exam_complete_resource_and_does_not_write_audit(): void
    {
        $first = $this->exam(['name' => 'Primero']);
        $second = $this->exam(['name' => 'Segundo', 'rules' => null]);
        $auditCount = DB::table('audit_logs')->count();
        $this->getJson(self::BASE.'/'.$second->id)->assertOk()->assertJsonPath('data.id', $second->id)->assertJsonPath('data.name', 'Segundo')
            ->assertJsonPath('data.course_offering_id', $this->fixture['offering']->id)->assertJsonPath('data.subject_code', 'INF-01')
            ->assertJsonPath('data.subject_name', 'Programación I')->assertJsonPath('data.academic_term_name', 'I/2027')
            ->assertJsonPath('data.exam_date', '2026-10-21')->assertJsonPath('data.start_time', '10:00:00')
            ->assertJsonPath('data.duration_minutes', 60)->assertJsonPath('data.room.id', $this->fixture['room']->id)
            ->assertJsonPath('data.rules', null)->assertJsonPath('data.status', 'SCHEDULED')->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.edit_block_reason', null)->assertJsonPath('data.can_cancel', true)->assertJsonPath('data.cancel_block_reason', null)
            ->assertJsonPath('data.updated_at', $second->fresh()->updated_at->toISOString());
        $this->assertDatabaseCount('exams', 2);
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame('Primero', $first->fresh()->name);
    }

    public function test_foreign_or_missing_or_invalid_id_never_falls_back_to_an_owned_exam(): void
    {
        $this->exam();
        $other = $this->otherTeacherExam();
        foreach ([$other->id, 999999, 0, -1, 'abc', '1.5', '999999999999999999999999'] as $id) {
            $this->getJson(self::BASE.'/'.$id)->assertNotFound()->assertJsonMissingPath('data');
        }
    }

    public function test_collaborator_access_is_not_ownership_for_management_reads(): void
    {
        $other = $this->otherTeacherExam();
        DB::table('exam_collaborators')->insert(['exam_id' => $other->id, 'user_id' => $this->fixture['user']->id,
            'assigned_by' => $other->created_by, 'access_code_hash' => hash('sha256', 'collaborator'), 'status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->getJson(self::BASE.'/'.$other->id)->assertNotFound();
        $this->getJson(self::BASE.'?view=all')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_query_validation_rejects_unknown_views_and_bad_types(): void
    {
        foreach (['view=unknown', 'view[]=all', 'page=0', 'page=-1', 'page=1.5', 'page=abc', 'per_page=15', 'per_page=0', 'search[]=x', 'search='.str_repeat('x', 256)] as $query) {
            $this->getJson(self::BASE.'?'.$query)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['errors']);
        }
    }

    public function test_inactive_user_missing_teacher_role_and_initial_password_are_rejected(): void
    {
        $exam = $this->exam();
        $user = $this->fixture['user'];
        $user->update(['status' => 'INACTIVE']);
        Sanctum::actingAs($user->fresh());
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->getJson(self::BASE.'/'.$exam->id)->assertUnauthorized();
        $user->forceFill(['status' => 'ACTIVE', 'must_change_password' => true])->save();
        Sanctum::actingAs($user->fresh());
        $this->getJson(self::BASE)->assertForbidden();
        $user->forceFill(['must_change_password' => false])->save();
        $role = Role::where('name', 'DOCENTE')->firstOrFail();
        foreach (['pivot', 'role'] as $case) {
            $user->roles()->updateExistingPivot($role->id, ['status' => $case === 'pivot' ? 'INACTIVE' : 'ACTIVE']);
            $role->update(['status' => $case === 'role' ? 'INACTIVE' : 'ACTIVE']);
            Sanctum::actingAs($user->fresh());
            $this->getJson(self::BASE)->assertForbidden();
            $this->getJson(self::BASE.'/'.$exam->id)->assertForbidden();
        }
    }

    public function test_unauthenticated_and_replaced_session_are_rejected(): void
    {
        auth()->forgetGuards();
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->getJson(self::BASE.'/1')->assertUnauthorized();
        config(['session.driver' => 'file']);
        Session::setDefaultDriver('file');
        $this->startSession();
        $this->fixture['user']->forceFill(['active_session_id' => 'another-session'])->save();
        $this->actingAs($this->fixture['user'])->withCredentials()->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->getJson(self::BASE)->assertUnauthorized()->assertJsonPath('code', 'SESSION_REPLACED');
    }

    public function test_eager_loading_keeps_query_count_bounded_for_a_full_page(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->exam();
        }
        DB::enableQueryLog();
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(10, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(14, count($queries));
    }

    private function exam(array $overrides = []): Exam
    {
        return Exam::create(array_replace($this->examPayload($this->fixture['room']->id) + ['course_offering_id' => $this->fixture['offering']->id,
            'status' => 'SCHEDULED', 'created_by' => $this->fixture['user']->id], $overrides));
    }

    private function otherTeacherExam(): Exam
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $teacher = Teacher::factory()->create(['user_id' => $user->id]);
        $offering = CourseOffering::create(['teacher_id' => $teacher->id, 'subject_id' => $this->fixture['subject']->id,
            'academic_term_id' => $this->fixture['term']->id, 'status' => 'ACTIVE']);

        return $this->exam(['course_offering_id' => $offering->id, 'created_by' => $user->id, 'name' => 'Examen ajeno']);
    }
}
