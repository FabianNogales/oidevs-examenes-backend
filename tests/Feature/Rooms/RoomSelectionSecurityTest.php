<?php

namespace Tests\Feature\Rooms;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class RoomSelectionSecurityTest extends TestCase
{
    use CourseOfferingFixture, RefreshDatabase;

    private array $fixture;

    private CourseOffering $foreignOffering;

    private Exam $ownExam;

    private Exam $foreignExam;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.sqlite');
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite' || $connection['database'] !== ':memory:'
            || ! empty($connection['url']) || $app['config']->get('session.driver') !== 'array'
            || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Room selection tests require isolated in-memory SQLite and array session/cache.');
        }
        foreach ($app['db']->connection()->getPdo()->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if ($database['file'] !== '') {
                throw new \RuntimeException('Persistent test connection rejected.');
            }
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-05-09 10:00:00', 'America/La_Paz'));
        $this->fixture = $this->offeringFixture();
        $teacher = Teacher::factory()->create();
        $this->foreignOffering = CourseOffering::create(['teacher_id' => $teacher->id,
            'subject_id' => $this->fixture['subject']->id, 'academic_term_id' => $this->fixture['term']->id, 'status' => 'ACTIVE']);
        $this->ownExam = $this->exam($this->fixture['offering'], 'Parcial propio', '08:15:00');
        $this->foreignExam = $this->exam($this->foreignOffering, 'Examen privado de otro docente', '11:15:00');
    }

    public function test_guest_first_password_and_inactive_account_guards_apply(): void
    {
        $this->getJson('/api/v1/rooms')->assertUnauthorized();
        $actor = $this->teacher();
        $actor->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/rooms')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $actor->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->getJson('/api/v1/rooms')->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_students_and_room_catalog_operators_keep_only_basic_availability(): void
    {
        foreach (['ESTUDIANTE', 'Operador de aulas SEC01'] as $roleName) {
            $actor = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
            $role = Role::firstOrCreate(['name' => $roleName], ['status' => 'ACTIVE']);
            if ($roleName !== 'ESTUDIANTE') {
                $role->forceFill(['is_custom' => true, 'custom_name_key' => mb_strtoupper($roleName)])->save();
                $role->permissions()->sync(Permission::where('key', 'rooms.view')->pluck('id'));
            }
            $actor->roles()->attach($role->id, ['status' => 'ACTIVE']);
            Sanctum::actingAs($actor);

            $this->getJson('/api/v1/rooms')->assertOk()->assertJsonPath('meta', null)
                ->assertJsonPath('data.0.id', $this->fixture['room']->id)
                ->assertJsonPath('data.0.busy_slots', [])->assertJsonPath('data.0.schedules_list', [])
                ->assertJsonMissing(['exam_name' => $this->foreignExam->name]);
            $this->getJson('/api/v1/rooms?course_offering_id='.$this->foreignOffering->id)
                ->assertForbidden()->assertJsonMissingPath('meta')->assertJsonMissingPath('data');
            $this->getJson('/api/v1/rooms?exam_date=2027-05-10&start_time=08:15&duration_minutes=90')
                ->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_teacher_owns_offering_context_without_requiring_administrative_room_permissions(): void
    {
        $this->teacher();
        $this->getJson('/api/v1/rooms?course_offering_id='.$this->fixture['offering']->id)->assertOk()
            ->assertJsonPath('meta.academic_term.id', $this->fixture['term']->id)
            ->assertJsonPath('data.0.schedules_list.0.exam_id', $this->ownExam->id)
            ->assertJsonPath('data.0.schedules_list.0.exam_name', $this->ownExam->name)
            ->assertJsonPath('data.0.schedules_list.1.exam_id', null)
            ->assertJsonPath('data.0.schedules_list.1.exam_name', 'Reservado')
            ->assertJsonPath('data.0.schedules_list.1.start', '11:15')
            ->assertJsonPath('data.0.schedules_list.1.end', '12:45')
            ->assertJsonMissing(['exam_name' => $this->foreignExam->name]);
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        foreach ([$this->foreignOffering->id, 999999] as $id) {
            $this->getJson('/api/v1/rooms?course_offering_id='.$id)->assertForbidden()
                ->assertJsonMissingPath('meta')->assertJsonMissingPath('data');
        }
    }

    public function test_offering_context_is_validated_and_role_revocation_removes_it(): void
    {
        $actor = $this->teacher();
        foreach ([0, -1, 'invalid', '1.5', '1e2', [$this->fixture['offering']->id]] as $id) {
            $this->getJson('/api/v1/rooms?'.http_build_query(['course_offering_id' => $id]))
                ->assertUnprocessable()->assertJsonValidationErrors('course_offering_id');
        }
        $actor->roles()->detach(Role::where('name', 'DOCENTE')->value('id'));
        $this->getJson('/api/v1/rooms?course_offering_id='.$this->fixture['offering']->id)->assertForbidden();
        $this->getJson('/api/v1/rooms')->assertOk()->assertJsonPath('data.0.schedules_list', []);
    }

    public function test_administrator_can_read_offering_context_and_reservations(): void
    {
        $actor = $this->fixture['user'];
        $actor->roles()->detach(Role::where('name', 'DOCENTE')->value('id'));
        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/rooms?course_offering_id='.$this->foreignOffering->id)->assertOk()
            ->assertJsonPath('meta.academic_term.id', $this->fixture['term']->id)
            ->assertJsonPath('data.0.schedules_list.1.exam_id', $this->foreignExam->id)
            ->assertJsonPath('data.0.schedules_list.1.exam_name', $this->foreignExam->name);
    }

    public function test_exam_exclusion_still_requires_ownership_and_preserves_occupancy(): void
    {
        $this->teacher();
        $query = ['exam_date' => '2027-05-10', 'start_time' => '08:15', 'duration_minutes' => 90];
        $this->getJson('/api/v1/rooms?'.http_build_query($query))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/rooms?'.http_build_query($query + ['exclude_exam_id' => $this->ownExam->id]))
            ->assertOk()->assertJsonPath('data.0.id', $this->fixture['room']->id)
            ->assertJsonPath('data.0.schedules_list.0.exam_id', null);
        $this->getJson('/api/v1/rooms?'.http_build_query($query + ['exclude_exam_id' => $this->foreignExam->id]))
            ->assertNotFound()->assertJsonMissingPath('data');
    }

    private function teacher(): User
    {
        $actor = $this->fixture['user'];
        $actor->roles()->detach(Role::where('name', 'ADMINISTRADOR')->value('id'));
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function exam(CourseOffering $offering, string $name, string $start): Exam
    {
        return Exam::create(['course_offering_id' => $offering->id, 'created_by' => $this->fixture['user']->id,
            'room_id' => $this->fixture['room']->id, 'name' => $name, 'exam_date' => '2027-05-10',
            'start_time' => $start, 'duration_minutes' => 90, 'evaluation_type' => 'partial', 'status' => 'SCHEDULED']);
    }
}
