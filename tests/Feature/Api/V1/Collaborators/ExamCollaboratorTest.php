<?php

namespace Tests\Feature\Api\V1\Collaborators;

use App\Enums\RoleName;
use App\Http\Middleware\VerifyExamAccess;
use App\Models\Career;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExamCollaboratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    /**
     * Crea un profesor válido en la tabla `teachers`.
     */
    private function createTeacher(?User $user = null): Teacher
    {
        $user = $user ?? User::factory()->create();

        $role = Role::firstOrCreate(
            ['name' => RoleName::DOCENTE->value],
            ['status' => 'ACTIVE']
        );
        $user->roles()->syncWithoutDetaching([$role->id => ['status' => 'ACTIVE', 'assigned_at' => now()]]);

        return Teacher::create([
            'user_id' => $user->id,
            'institutional_code' => 'PROF-'.rand(1000, 9999),
            'identity_number' => (string) rand(1000000, 9999999),
            'first_names' => 'Carlos',
            'last_names' => 'Docente',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Crea una carrera de prueba.
     */
    private function createCareer(): Career
    {
        return Career::firstOrCreate(
            ['code' => 'SIS'],
            [
                'name' => 'Ingeniería de Sistemas',
                'status' => 'ACTIVE',
            ]
        );
    }

    /**
     * Crea un estudiante válido en la tabla `students`.
     */
    private function createStudent(?User $user = null): Student
    {
        $user = $user ?? User::factory()->create();
        $career = $this->createCareer();

        return Student::create([
            'user_id' => $user->id,
            'career_id' => $career->id,
            'sis_code' => '2020'.rand(10000, 99999),
            'identity_number' => (string) rand(1000000, 9999999),
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Crea un aula válida en la tabla `rooms`.
     */
    private function createRoom(): int
    {
        return DB::table('rooms')->insertGetId([
            'code' => 'A-617-'.DB::table('rooms')->count(),
            'name' => 'Aula 617',
            'location' => 'Edificio Nuevo - Bloque A',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Crea una oferta de curso relacionando materia, gestión académica y profesor.
     */
    private function createCourseOffering(?Teacher $teacher = null): int
    {
        if (! $teacher) {
            $teacher = $this->createTeacher();
        }

        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'MAT-'.rand(100, 999),
            'name' => 'Materia de Prueba',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $academicTermId = DB::table('academic_terms')->insertGetId([
            'name' => 'Gestión 2/2026-'.DB::table('academic_terms')->count(),
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('course_offerings')->insertGetId([
            'subject_id' => $subjectId,
            'academic_term_id' => $academicTermId,
            'teacher_id' => $teacher->id,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Crea un examen de prueba con todos sus campos obligatorios.
     */
    private function createExam(?Teacher $teacher = null): Exam
    {
        $teacher = $teacher ?? $this->createTeacher();
        $courseOfferingId = $this->createCourseOffering($teacher);
        $roomId = $this->createRoom();

        return Exam::create([
            'course_offering_id' => $courseOfferingId,
            'room_id' => $roomId,
            'name' => 'Examen Parcial',
            'exam_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '08:15:00',
            'duration_minutes' => 90,
            'status' => 'SCHEDULED',
            'evaluation_type' => 'partial',
            'created_by' => $teacher->user_id,
        ]);
    }

    /** @test */
    public function teacher_can_assign_a_student_as_collaborator(): void
    {
        /** @var User $teacherUser */ $teacherUser = User::factory()->create();
        $teacher = $this->createTeacher($teacherUser);
        $student = $this->createStudent();
        $exam = $this->createExam($teacher);

        $response = $this->actingAs($teacherUser)
            ->postJson("/api/v1/exams/{$exam->id}/collaborators", [
                'user_id' => $student->user_id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Colaborador asignado exitosamente.');

        $this->assertDatabaseHas('exam_collaborators', [
            'exam_id' => $exam->id,
            'user_id' => $student->user_id,
            'status' => 'ACTIVE',
        ]);
    }

    /** @test */
    public function teacher_can_revoke_collaborator_access(): void
    {
        /** @var User $teacherUser */ $teacherUser = User::factory()->create();
        $teacher = $this->createTeacher($teacherUser);
        $student = $this->createStudent();
        $exam = $this->createExam($teacher);

        $collaborator = ExamCollaborator::create([
            'exam_id' => $exam->id,
            'user_id' => $student->user_id,
            'assigned_by' => $teacherUser->id,
            'access_code_hash' => hash('sha256', 'test_code'),
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($teacherUser)
            ->deleteJson("/api/v1/exams/{$exam->id}/collaborators/{$student->user_id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Autorización de colaborador revocada exitosamente.');

        $this->assertDatabaseHas('exam_collaborators', [
            'id' => $collaborator->id,
            'status' => 'REVOKED',
        ]);
    }

    /** @test */
    public function student_can_view_their_assigned_collaborations(): void
    {
        /** @var User $teacherUser */
        $teacherUser = User::factory()->create();
        $teacher = $this->createTeacher($teacherUser);

        /** @var User $studentUser */ $studentUser = User::factory()->create();
        $student = $this->createStudent($studentUser);
        $exam = $this->createExam($teacher);

        ExamCollaborator::create([
            'exam_id' => $exam->id,
            'user_id' => $student->user_id,
            'assigned_by' => $teacherUser->id,
            'access_code_hash' => hash('sha256', 'test_code'),
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($studentUser)
            ->getJson('/api/v1/me/collaborations');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_eligible_student_can_collaborate_without_changing_roles(): void
    {
        $teacherUser = User::factory()->create();
        $teacher = $this->createTeacher($teacherUser);
        $exam = $this->createExam($teacher);
        $user = $this->createStudent()->user;
        $roles = $user->roles()->pluck('roles.id')->all();

        $assigned = $this->actingAs($teacherUser)->postJson("/api/v1/exams/{$exam->id}/collaborators", [
            'user_id' => $user->id,
        ])->assertCreated()->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.assigned_by', $teacherUser->id)
            ->assertJsonPath('data.assigned_by_name', 'Carlos Docente')
            ->assertJsonMissingPath('data.access_code_hash');

        $this->actingAs($teacherUser)->getJson("/api/v1/exams/{$exam->id}/collaborators")
            ->assertOk()->assertJsonPath('data.0.display_name', 'Juan Pérez')
            ->assertJsonPath('data.0.identity_number', $user->student->identity_number)
            ->assertJsonPath('data.0.assigned_by', $teacherUser->id);

        $this->actingAs($user)->getJson('/api/v1/me/collaborations')
            ->assertOk()->assertJsonPath('data.0.exam_id', $exam->id)
            ->assertJsonPath('data.0.duration_minutes', 90)
            ->assertJsonPath('data.0.subject_name', 'Materia de Prueba')
            ->assertJsonPath('data.0.room', 'Aula 617');
        $this->assertSame($roles, $user->roles()->pluck('roles.id')->all());
    }

    public function test_duplicates_are_rejected_and_revoked_users_can_be_reassigned(): void
    {
        $teacherUser = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($teacherUser));
        $user = $this->createStudent()->user;
        $url = "/api/v1/exams/{$exam->id}/collaborators";
        $this->actingAs($teacherUser)->postJson($url, ['user_id' => $user->id])->assertCreated();
        $this->postJson($url, ['user_id' => $user->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->deleteJson($url.'/'.$user->id)->assertOk();
        $this->actingAs($user)->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($teacherUser)->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url, ['user_id' => $user->id])->assertCreated();
        $this->assertDatabaseCount('exam_collaborators', 1);
        $this->assertDatabaseHas('exam_collaborators', ['user_id' => $user->id, 'revoked_at' => null]);
    }

    public function test_only_responsible_teacher_can_manage_assignments(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $other = User::factory()->create();
        $this->createTeacher($other);
        $user = $this->createStudent()->user;
        $url = "/api/v1/exams/{$exam->id}/collaborators";
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->postJson($url, ['user_id' => $user->id])->assertForbidden();
        $this->deleteJson($url.'/'.$user->id)->assertForbidden();
        $this->actingAs($owner)->postJson($url, ['user_id' => $user->id])->assertCreated();
        $this->actingAs($user)->postJson($url, ['user_id' => $other->id])->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->deleteJson($url.'/'.$user->id)->assertForbidden();
    }

    public function test_user_search_supports_profiles_email_ci_and_full_name(): void
    {
        $teacherUser = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($teacherUser));
        $student = $this->createStudent();
        $plainUser = User::factory()->create(['email' => 'registered@example.com']);
        $this->actingAs($teacherUser);
        foreach (['Juan Pérez', $student->identity_number, $student->user->email] as $search) {
            $this->getJson('/api/v1/users?exam_id='.$exam->id.'&search='.urlencode($search))->assertOk()
                ->assertJsonPath('data.0.id', $student->user_id)
                ->assertJsonPath('data.0.identity_number', $student->identity_number);
        }
        $this->getJson('/api/v1/users?exam_id='.$exam->id.'&search=registered%40example.com')->assertOk()
            ->assertJsonPath('data.0.id', $plainUser->id)->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/users?exam_id='.$exam->id.'&search=no-match-xyz')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($plainUser)->getJson('/api/v1/users?search=Juan')->assertForbidden();
    }

    public function test_expired_and_cancelled_exams_are_excluded(): void
    {
        $teacherUser = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($teacherUser));
        $user = $this->createStudent()->user;
        $url = "/api/v1/exams/{$exam->id}/collaborators";
        $this->actingAs($teacherUser)->postJson($url, ['user_id' => $user->id])->assertCreated();
        $exam->update(['status' => 'CANCELLED']);
        $this->actingAs($user)->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(0, 'data');
        $exam->update(['status' => 'SCHEDULED', 'exam_date' => now()->subDay()->toDateString()]);
        $this->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($teacherUser)->postJson($url, ['user_id' => User::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('exam_id');
    }

    public function test_assignment_validation_and_cross_exam_revocation(): void
    {
        $owner = User::factory()->create();
        $teacher = $this->createTeacher($owner);
        $exam = $this->createExam($teacher);
        $otherExam = $this->createExam($teacher);
        $url = "/api/v1/exams/{$exam->id}/collaborators";
        $user = $this->createStudent()->user;
        $this->actingAs($owner)->postJson($url, ['student_id' => 1])->assertUnprocessable();
        $this->postJson($url, ['user_id' => 999999])->assertUnprocessable();
        $inactive = User::factory()->create(['status' => 'INACTIVE']);
        $this->postJson($url, ['user_id' => $inactive->id])->assertUnprocessable();
        $this->postJson($url, ['user_id' => $user->id])->assertCreated();
        $this->deleteJson("/api/v1/exams/{$otherExam->id}/collaborators/{$user->id}")->assertNotFound();
        $this->assertDatabaseHas('exam_collaborators', ['exam_id' => $exam->id, 'user_id' => $user->id, 'status' => 'ACTIVE']);
    }

    public function test_exam_access_uses_user_identity_and_loses_access_after_revocation(): void
    {
        $unrelated = User::factory()->create();
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $user = $this->createStudent()->user;
        $this->actingAs($owner)->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $user->id])->assertCreated();

        $check = function (User $actor) use ($exam) {
            $request = Request::create('/control', 'GET', ['exam_id' => $exam->id]);
            $request->setUserResolver(fn () => $actor);

            return (new VerifyExamAccess)->handle($request, fn () => response()->json(['ok' => true]))->getStatusCode();
        };
        $this->assertSame(200, $check($owner));
        $this->assertSame(200, $check($user));
        $this->assertSame(403, $check($unrelated));
        $exam->update(['status' => 'CANCELLED']);
        $this->assertSame(403, $check($user));
        $exam->update(['status' => 'SCHEDULED']);
        $this->deleteJson("/api/v1/exams/{$exam->id}/collaborators/{$user->id}")->assertOk();
        $this->assertSame(403, $check($user));
    }

    public function test_collaborations_are_private_and_authentication_is_required(): void
    {
        $this->getJson('/api/v1/me/collaborations')->assertUnauthorized();
        $this->getJson('/api/v1/users?search=Juan')->assertUnauthorized();
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $user = $this->createStudent()->user;
        $this->actingAs($owner)->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $user->id])->assertCreated();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/me/collaborations')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_migration_preserves_legacy_assignments_and_can_reverse_student_assignments(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $student = $this->createStudent();
        $migration = require database_path('migrations/2026_10_08_000001_replace_student_with_user_on_exam_collaborators.php');
        $migration->down();
        $id = DB::table('exam_collaborators')->insertGetId([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'assigned_by' => $owner->id,
            'access_code_hash' => 'legacy-hash',
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);
        $migration->up();
        $this->assertDatabaseHas('exam_collaborators', [
            'id' => $id, 'user_id' => $student->user_id, 'assigned_by' => $owner->id,
            'status' => 'ACTIVE', 'access_code_hash' => 'legacy-hash',
        ]);
        $migration->down();
        $this->assertDatabaseHas('exam_collaborators', ['id' => $id, 'student_id' => $student->id]);
        $migration->up();
    }

    private function enroll(Student $student, Exam $exam, string $status = 'ACTIVE'): void
    {
        DB::table('enrollments')->insert([
            'course_offering_id' => $exam->course_offering_id,
            'student_id' => $student->id,
            'status' => $status,
            'registered_by' => $exam->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_candidates_and_assignment_exclude_active_enrollment_in_exam_offering(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $enrolled = $this->createStudent();
        $candidate = $this->createStudent();
        $this->enroll($enrolled, $exam);
        $this->actingAs($owner)->getJson("/api/v1/users?exam_id={$exam->id}")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $candidate->user_id]);
        $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $enrolled->user_id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('exam_collaborators', 0);
        $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $candidate->user_id])->assertCreated();
    }

    public function test_former_enrollment_and_other_offerings_do_not_exclude_candidates(): void
    {
        $owner = User::factory()->create();
        $teacher = $this->createTeacher($owner);
        $exam = $this->createExam($teacher);
        $otherExam = $this->createExam($teacher);
        $former = $this->createStudent();
        $other = $this->createStudent();
        $this->enroll($former, $exam, 'INACTIVE');
        $this->enroll($other, $otherExam);
        $this->actingAs($owner)->getJson("/api/v1/users?exam_id={$exam->id}")->assertOk()->assertJsonCount(3, 'data');
        foreach ([$former, $other] as $student) {
            $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $student->user_id])->assertCreated();
        }
    }

    public function test_active_users_of_any_role_are_candidates_and_inactive_accounts_are_rejected(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $plain = User::factory()->create();
        $teacherUser = User::factory()->create();
        $this->createTeacher($teacherUser);
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::ADMINISTRADOR->value], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $inactive = User::factory()->create(['status' => 'INACTIVE']);
        $this->actingAs($owner)->getJson("/api/v1/users?exam_id={$exam->id}")
            ->assertOk()->assertJsonCount(4, 'data')->assertJsonMissing(['id' => $inactive->id]);
        foreach ([$plain, $teacherUser, $admin] as $candidate) {
            $roles = $candidate->roles()->pluck('roles.id')->all();
            $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $candidate->id])->assertCreated();
            $this->assertSame($roles, $candidate->roles()->pluck('roles.id')->all());
        }
        $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $inactive->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_teacher_can_collaborate_on_another_exam_without_managing_its_collaborators(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $collaborator = User::factory()->create();
        $ownExam = $this->createExam($this->createTeacher($collaborator));
        $this->actingAs($owner)->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $collaborator->id])->assertCreated();
        $this->actingAs($collaborator)->getJson('/api/v1/me/collaborations')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.exam_id', $exam->id);
        $this->assertTrue($collaborator->hasRole(RoleName::DOCENTE));
        $this->getJson("/api/v1/exams/{$ownExam->id}/collaborators")->assertOk();
        $this->getJson("/api/v1/exams/{$exam->id}/collaborators")->assertForbidden();
        $request = Request::create('/control', 'GET', ['exam_id' => $exam->id]);
        $request->setUserResolver(fn () => $collaborator);
        $this->assertSame(200, (new VerifyExamAccess)->handle($request, fn () => response()->json(['ok' => true]))->getStatusCode());
        $this->actingAs($owner)->deleteJson("/api/v1/exams/{$exam->id}/collaborators/{$collaborator->id}")->assertOk();
        $this->assertSame(403, (new VerifyExamAccess)->handle($request, fn () => response()->json(['ok' => true]))->getStatusCode());
        $this->actingAs($collaborator)->getJson("/api/v1/exams/{$ownExam->id}/collaborators")->assertOk();
        $this->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_student_enrollment_restriction_also_applies_to_a_user_with_teacher_role(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $student = $this->createStudent();
        $this->createTeacher($student->user);
        $this->enroll($student, $exam);
        $this->actingAs($owner)->getJson("/api/v1/users?exam_id={$exam->id}")
            ->assertOk()->assertJsonMissing(['id' => $student->user_id]);
        $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $student->user_id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_candidate_search_requires_exam_and_responsible_teacher(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $other = User::factory()->create();
        $this->createTeacher($other);
        $this->actingAs($owner)->getJson('/api/v1/users?search=Juan')->assertUnprocessable()->assertJsonValidationErrors('exam_id');
        $this->getJson('/api/v1/users?exam_id=999999')->assertUnprocessable();
        $this->actingAs($other)->getJson("/api/v1/users?exam_id={$exam->id}")->assertForbidden();
    }

    public function test_enrollment_after_search_is_rechecked_on_assignment_and_access(): void
    {
        $owner = User::factory()->create();
        $exam = $this->createExam($this->createTeacher($owner));
        $student = $this->createStudent();
        $this->actingAs($owner)->getJson("/api/v1/users?exam_id={$exam->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $student->user_id])->assertCreated();
        $this->enroll($student, $exam);
        $this->postJson("/api/v1/exams/{$exam->id}/collaborators", ['user_id' => $student->user_id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->getJson("/api/v1/users?exam_id={$exam->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($student->user)->getJson('/api/v1/me/collaborations')->assertOk()->assertJsonCount(0, 'data');
        $request = Request::create('/control', 'GET', ['exam_id' => $exam->id]);
        $request->setUserResolver(fn () => $student->user);
        $response = (new VerifyExamAccess)->handle($request, fn () => response()->json(['ok' => true]));
        $this->assertSame(403, $response->getStatusCode());
    }
}
