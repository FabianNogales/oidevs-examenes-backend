<?php

namespace Tests\Feature\Attendance;

use App\Models\ExamCollaborator;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\AttendanceCollaboratorActivity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EntryAnnulmentFixtures;
use Tests\TestCase;

class AttendanceContractTest extends TestCase
{
    use EntryAnnulmentFixtures;
    use RefreshDatabase;

    private function endpoint(string $view = 'students'): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/attendance/'.$view;
    }

    private function extraStudent(): Student
    {
        $student = $this->student->replicate();
        $student->user_id = User::factory()->create()->id;
        $student->sis_code = 'HU32-'.(Student::max('id') + 1);
        $student->identity_number = 'CI-'.$student->sis_code;
        $student->save();
        DB::table('enrollments')->insert(['student_id' => $student->id,
            'course_offering_id' => $this->exam->course_offering_id, 'registered_by' => $this->owner->id, 'status' => 'ACTIVE']);

        return $student;
    }

    private function assertSnapshot(array $snapshot): void
    {
        $this->assertSame($this->exam->id, $snapshot['exam']['id']);
        $this->assertSame('SERVER', $snapshot['source']);
        $this->assertTrue($snapshot['is_current']);
        $this->assertSame('ACTIVE_ENROLLMENTS', $snapshot['roster_scope']);
        $summary = $snapshot['summary'];
        $this->assertSame($summary['total_students'], $summary['registered'] + $summary['pending']);
        $expected = $summary['total_students'] === 0 ? 0 : round($summary['registered'] * 100 / $summary['total_students'], 2);
        $this->assertEquals($expected, $summary['attendance_percentage']);
        $this->assertGreaterThanOrEqual(5, $snapshot['poll_after_seconds']);
        $this->assertLessThanOrEqual(60, $snapshot['poll_after_seconds']);
        $this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:\d{2})$/', $snapshot['last_updated_at']);
        $this->assertSame(['pending_count' => null, 'available_on_server' => false,
            'included_in_official_attendance' => false], $snapshot['offline']);
        $this->assertSame(['available' => false, 'reason' => 'EXCLUSIVE_LISTS_NOT_CONFIGURED'], $snapshot['exclusive_lists']);
    }

    public function test_both_endpoints_return_the_complete_common_contract_and_preserve_string_identifiers(): void
    {
        $this->student->update(['sis_code' => '00123', 'identity_number' => '00099']);
        $entry = $this->initialEntry();
        $pending = $this->extraStudent();
        $students = $this->actingAs($this->owner)->getJson($this->endpoint().'?per_page=10')->assertOk()
            ->assertJsonPath('data.0.sis_code', '00123')->assertJsonPath('data.0.identity_number', '00099')
            ->assertJsonPath('data.0.attendance_status', 'SERVER_CONFIRMED')->assertJsonPath('data.0.entry.id', $entry)
            ->assertJsonPath('data.0.entry.verified_by', $this->owner->id)->assertJsonPath('data.0.entry.verification_method', 'QR')
            ->assertJsonPath('data.1.identity_number', $pending->identity_number)->assertJsonPath('data.1.attendance_status', 'PENDING')
            ->assertJsonPath('data.1.entry', null)->json();
        $this->assertSnapshot($students);
        $activity = $this->getJson($this->endpoint('collaborators'))->assertOk()->json();
        $this->assertSnapshot($activity);
        $this->assertSame($students['summary'], $activity['summary']);
        $this->assertSame($activity['summary']['registered'], array_sum(array_column($activity['collaborators']['data'], 'valid_registered')));
    }

    public function test_summary_is_global_while_pagination_search_and_view_only_restrict_rows(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->extraStudent();
        }
        $this->initialEntry();
        $all = $this->actingAs($this->owner)->getJson($this->endpoint().'?per_page=10')->assertOk()
            ->assertJsonPath('meta.total', 12)->assertJsonPath('meta.last_page', 2)->assertJsonCount(10, 'data')->json();
        $second = $this->getJson($this->endpoint().'?per_page=10&page=2')->assertOk()->assertJsonCount(2, 'data')->json();
        $this->assertSame($all['summary'], $second['summary']);
        $this->assertSame([], array_intersect(array_column($all['data'], 'student_id'), array_column($second['data'], 'student_id')));
        $pending = $this->getJson($this->endpoint().'?view=pending&per_page=10')->assertOk()->assertJsonPath('meta.total', 11)->json();
        $this->assertSame($all['summary'], $pending['summary']);
        $registered = $this->getJson($this->endpoint().'?view=registered')->assertOk()->assertJsonPath('meta.total', 1)->json();
        $this->assertSame($all['summary'], $registered['summary']);
        $this->getJson($this->endpoint().'?search=sin-coincidencia')->assertOk()->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)->assertJsonPath('summary.total_students', 12)->assertJsonPath('summary.registered', 1);
        // A roster change can leave the requested page outside the new range; the UI resets to page 1.
        DB::table('enrollments')->where('student_id', '!=', $this->student->id)->update(['status' => 'INACTIVE']);
        $this->getJson($this->endpoint().'?per_page=10&page=2')->assertOk()->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 1)->assertJsonPath('data', [])->assertJsonPath('summary.total_students', 1);
    }

    public function test_revoked_assignment_keeps_its_historical_activity_but_is_never_presented_as_active(): void
    {
        $collaborator = $this->collaborator();
        $id = $this->initialEntry();
        DB::table('exam_entries')->where('id', $id)->update(['verified_by' => $collaborator->id]);
        ExamCollaborator::where('exam_id', $this->exam->id)->where('user_id', $collaborator->id)
            ->update(['status' => 'ACTIVE', 'revoked_at' => now()]);
        $data = $this->actingAs($this->owner)->getJson($this->endpoint('collaborators'))->assertOk()
            ->assertJsonPath('summary.registered', 1)->json();
        $this->assertSnapshot($data);
        $rows = $data['collaborators']['data'];
        $this->assertCount(count(array_unique(array_column($rows, 'user_id'))), $rows);
        $this->assertSame(1, array_sum(array_column($rows, 'valid_registered')));
        $row = collect($rows)->firstWhere('user_id', $collaborator->id);
        $this->assertSame('REVOKED', $row['assignment_status']);
        $this->assertSame(1, $row['valid_registered']);
        foreach ($rows as $row) {
            $this->assertIsInt($row['user_id']);
            $this->assertNotEmpty($row['name']);
            $this->assertContains($row['role'], ['RESPONSIBLE_TEACHER', 'COLLABORATOR', 'HISTORICAL_VERIFIER']);
            $this->assertNull($row['assigned_total']);
            $this->assertNull($row['pending']);
            $this->assertNull($row['attendance_percentage']);
        }
    }

    public function test_current_annulment_endpoint_updates_official_rows_and_activity_without_including_offline_pending(): void
    {
        $entry = $this->initialEntry();
        $this->actingAs($this->owner)->postJson($this->entryBase().'/entries/'.$entry.'/annulment', ['reason' => 'Corregir ingreso.'])->assertOk();
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('summary.registered', 0)
            ->assertJsonPath('summary.pending', 1)->assertJsonPath('data.0.entry', null);
        $this->getJson($this->endpoint('collaborators'))->assertOk()->assertJsonPath('summary.registered', 0)
            ->assertJsonPath('collaborators.data.0.valid_registered', 0);
        $pending = DB::table('exam_entries')->insertGetId(['exam_id' => $this->exam->id, 'student_id' => $this->student->id,
            'room_id' => $this->exam->room_id, 'verified_by' => $this->owner->id, 'verification_method' => 'MANUAL',
            'entered_at' => now(), 'status' => 'PENDING_SYNC']);
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('summary.registered', 0)
            ->assertJsonPath('offline.pending_count', null)->assertJsonPath('data.0.attendance_status', 'PENDING');
        DB::table('exam_entries')->where('id', $pending)->update(['status' => 'VALID']);
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('summary.registered', 1)
            ->assertJsonPath('data.0.entry.verification_method', 'MANUAL');
        $this->assertDatabaseHas('exam_entries', ['id' => $entry, 'status' => 'ANNULLED']);
    }

    public function test_collaborator_failure_after_success_does_not_reuse_previous_current_snapshot(): void
    {
        $this->initialEntry();
        $this->actingAs($this->owner)->getJson($this->endpoint('collaborators'))->assertOk()->assertJsonPath('summary.registered', 1);
        $this->mock(AttendanceCollaboratorActivity::class, function ($mock) {
            $mock->shouldReceive('collect')->once()->andThrow(new \RuntimeException('Simulated activity failure'));
        });
        $response = $this->getJson($this->endpoint('collaborators'))->assertStatus(503)->assertJsonPath('is_current', false)
            ->assertJsonPath('last_updated_at', null)->assertJsonPath('data', null)->assertJsonMissingPath('summary');
        $this->assertStringNotContainsString('Simulated activity failure', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_student_failure_after_success_does_not_return_old_rows_as_confirmed(): void
    {
        $this->actingAs($this->owner)->getJson($this->endpoint())->assertOk();
        DB::listen(function (QueryExecuted $query) {
            if (str_contains($query->sql, '"s"."id" as "student_id"')) {
                throw new \RuntimeException('Simulated roster failure');
            }
        });
        $this->getJson($this->endpoint())->assertStatus(503)->assertJsonPath('is_current', false)
            ->assertJsonPath('last_updated_at', null)->assertJsonPath('data', null)->assertJsonMissingPath('summary');
    }

    public function test_generic_admin_cannot_supervise_and_owner_role_is_revalidated_on_each_request(): void
    {
        $admin = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        foreach (['students', 'collaborators'] as $view) {
            $this->actingAs($admin)->getJson($this->endpoint($view))->assertForbidden();
        }
        $this->actingAs($this->owner)->getJson($this->endpoint())->assertOk();
        DB::table('role_user')->where('user_id', $this->owner->id)->update(['status' => 'INACTIVE']);
        $this->getJson($this->endpoint())->assertForbidden();
        $this->getJson($this->endpoint('collaborators'))->assertForbidden();
    }
}
