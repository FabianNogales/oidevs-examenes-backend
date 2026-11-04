<?php

namespace Tests\Feature\Api\V1\Entries;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\Role;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Students\StudentProfileService;
use App\Services\Students\StudentQrService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EntryControlTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    private Exam $exam;

    private Student $student;

    private ExamEligibility $eligibility;

    /**
     * Auxiliar para crear el rol DOCENTE activo y asignarlo activo al usuario
     */
    private function assignTeacherRole(User $user): void
    {
        $roleName = RoleName::DOCENTE->value;
        $activeStatus = UserStatus::ACTIVE->value;

        // 1. Obtener o crear el rol DOCENTE activo
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            ['status' => $activeStatus]
        );

        // 2. Adjuntar el rol en la pivote role_user con status ACTIVE
        $user->roles()->attach($role->id, [
            'status' => $activeStatus,
            'assigned_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear Usuario Docente activo y sin cambio de contraseña pendiente
        /** @var User $teacherUser */
        $this->teacherUser = User::factory()->create([
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ]);

        // 2. Asignar el rol DOCENTE
        $this->assignTeacherRole($this->teacherUser);

        // 3. Crear modelo Teacher vinculado al usuario
        $this->teacher = Teacher::create([
            'user_id' => $this->teacherUser->id,
            'institutional_code' => 'DOC-001',
            'identity_number' => '44556677',
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'status' => 'ACTIVE',
        ]);

        // 4. Tablas maestras requeridas para la oferta académica
        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'SIS-101',
            'name' => 'Programación I',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $termId = DB::table('academic_terms')->insertGetId([
            'name' => '2-2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-20',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $careerId = DB::table('careers')->insertGetId([
            'code' => 'SYS',
            'name' => 'Ingeniería de Sistemas',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'A-101',
            'name' => 'Aula 101',
            'location' => 'Edificio Central - Piso 1',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Oferta académica explícitamente ligada al Teacher ID
        $courseOffering = CourseOffering::create([
            'subject_id' => $subjectId,
            'academic_term_id' => $termId,
            'teacher_id' => $this->teacher->id,
            'status' => 'ACTIVE',
        ]);

        // 6. Examen vinculado a la oferta académica
        $this->exam = Exam::create([
            'course_offering_id' => $courseOffering->id,
            'room_id' => $roomId,
            'name' => 'Primer Examen Parcial',
            'exam_date' => now()->addDays(2)->toDateString(),
            'start_time' => '08:00:00',
            'duration_minutes' => 90,
            'status' => 'SCHEDULED',
            'created_by' => $this->teacherUser->id,
            'evaluation_type' => 'PARTIAL',
        ]);

        // 7. Estudiante
        $studentUser = User::factory()->create(['email' => 'estudiante@test.com']);
        $this->student = Student::create([
            'user_id' => $studentUser->id,
            'career_id' => $careerId,
            'sis_code' => '20230001',
            'identity_number' => '12345678',
            'first_names' => 'Carlos',
            'last_names' => 'Pérez',
            'status' => 'ACTIVE',
        ]);

        // 8. Estado de elegibilidad del estudiante
        $this->eligibility = ExamEligibility::create([
            'exam_id' => $this->exam->id,
            'student_id' => $this->student->id,
            'status' => 'ELIGIBLE',
        ]);
        $this->enroll($this->student);
        $this->travelTo(CarbonImmutable::parse($this->exam->exam_date.' '.$this->exam->start_time, 'America/La_Paz'));
    }

    private function base(): string
    {
        return "/api/v1/exams/{$this->exam->id}/entry-control/students";
    }

    public function test_entry_filters_are_applied_before_pagination_and_summary_stays_global(): void
    {
        $registered = $this->student();
        $this->enroll($registered);
        $this->eligible($registered);
        $this->entry($registered);
        $annulled = $this->student();
        $this->enroll($annulled);
        $this->eligible($annulled);
        $this->entry($annulled, 'ANNULLED');
        $this->actingAs($this->teacherUser)->getJson($this->base().'?entry_status=REGISTERED&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $registered->id)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('summary.total_students', 3)->assertJsonPath('summary.pending', 2);
        $this->getJson($this->base().'?entry_status=PENDING&per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $annulled->id)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('summary.registered', 1);
        $this->entry($this->student);
        $this->entry($annulled);
        $this->getJson($this->base().'?entry_status=PENDING')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)->assertJsonPath('summary.pending', 0);
    }

    public function test_verification_incidents_are_independent_of_entries_and_global_exam_blockers(): void
    {
        $inactive = $this->student('INACTIVE');
        $this->enroll($inactive);
        $this->eligible($inactive);
        $pendingEligibility = $this->student();
        $this->enroll($pendingEligibility);
        $ineligible = $this->student();
        $this->enroll($ineligible);
        $this->eligible($ineligible, 'NOT_ELIGIBLE');
        $this->entry($ineligible);
        $this->actingAs($this->teacherUser)->getJson($this->base().'?has_incident=true')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('summary.total_students', 4);
        $this->getJson($this->base().'?has_incident=true&entry_status=REGISTERED')->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $ineligible->id);
        $this->getJson($this->base().'?has_incident=true&entry_status=PENDING')->assertJsonCount(2, 'data');
        $this->getJson($this->base().'?has_incident=false')->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $this->student->id);
        $this->getJson($this->base().'?entry_status=OTHER')->assertUnprocessable();
        $this->getJson($this->base().'?has_incident=invalid')->assertUnprocessable();
    }

    private function enroll(Student $student, string $status = 'ACTIVE'): void
    {
        DB::table('enrollments')->insert([
            'course_offering_id' => $this->exam->course_offering_id,
            'student_id' => $student->id, 'status' => $status,
            'registered_by' => $this->teacherUser->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function student(string $status = 'ACTIVE'): Student
    {
        $count = DB::table('students')->count() + 1;

        return Student::create([
            'user_id' => User::factory()->create()->id, 'career_id' => $this->student->career_id,
            'sis_code' => str_pad((string) $count, 9, '0', STR_PAD_LEFT),
            'identity_number' => 'CI-'.$count, 'first_names' => 'Ana María', 'last_names' => 'Gómez López', 'status' => $status,
        ]);
    }

    private function eligible(Student $student, string $status = 'ELIGIBLE'): void
    {
        ExamEligibility::create([
            'exam_id' => $this->exam->id, 'student_id' => $student->id, 'status' => $status,
            'reason' => $status === 'INELIGIBLE' ? 'Documento pendiente' : null,
            'reason_code' => $status === 'INELIGIBLE' ? 'INSTITUTIONAL_REQUIREMENT_PENDING' : null,
            'observations' => $status === 'INELIGIBLE' ? 'Presentar certificado' : null,
        ]);
    }

    private function entry(Student $student, string $status = 'VALID', ?int $examId = null): int
    {
        return DB::table('exam_entries')->insertGetId([
            'exam_id' => $examId ?? $this->exam->id, 'student_id' => $student->id, 'room_id' => $this->exam->room_id,
            'verified_by' => $this->teacherUser->id, 'verification_method' => 'QR',
            'entered_at' => now(), 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function collaborator(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'ESTUDIANTE'], ['status' => 'ACTIVE']);
        User::findOrFail($user->id)->roles()->syncWithoutDetaching([$role->id => ['status' => 'ACTIVE', 'assigned_at' => now()]]);
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/collaborators", ['user_id' => $user->id])->assertCreated();

        return $user;
    }

    public function test_owner_receives_approved_list_and_detail_contract(): void
    {
        $this->actingAs($this->teacherUser)->getJson($this->base())->assertOk()
            ->assertJsonPath('exam.subject_name', 'Programación I')
            ->assertJsonPath('summary.total_students', 1)->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.student_id', $this->student->id)
            ->assertJsonPath('data.0.entry_status', 'PENDING')->assertJsonPath('data.0.entry', null)
            ->assertJsonPath('data.0.room_status', 'MATCH')->assertJsonPath('data.0.meets_entry_conditions', true)
            ->assertJsonPath('data.0.blockers', []);
        $this->getJson($this->base().'/'.$this->student->id)->assertOk()
            ->assertJsonPath('data.enrollment_status', 'ACTIVE')
            ->assertJsonPath('data.eligibility_status', 'ELIGIBLE')
            ->assertJsonPath('data.assigned_room_id', $this->exam->room_id)
            ->assertJsonPath('data.controlled_room_id', $this->exam->room_id);
    }

    public function test_search_pagination_and_summary_are_independent(): void
    {
        $student = $this->student();
        $this->enroll($student);
        $this->eligible($student, 'INELIGIBLE');
        $this->actingAs($this->teacherUser);
        foreach (['Ana', 'López', 'Ana María Gómez López', $student->sis_code, $student->identity_number] as $search) {
            $this->getJson($this->base().'?search='.urlencode($search).'&per_page=1')
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('summary.total_students', 2)
                ->assertJsonPath('summary.pending', 2)->assertJsonPath('summary.not_applicable', 0)
                ->assertJsonPath('data.0.student_id', $student->id);
        }
        $this->getJson($this->base().'?per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');
        $this->getJson($this->base().'?search=not-found')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('summary.total_students', 2);
        $this->getJson($this->base().'?page=99&per_page=1')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_missing_eligibility_inactivity_and_ineligibility_are_explicit(): void
    {
        $pending = $this->student();
        $this->enroll($pending);
        $inactive = $this->student('INACTIVE');
        $this->enroll($inactive);
        $this->eligible($inactive);
        $denied = $this->student();
        $this->enroll($denied);
        $this->eligible($denied, 'INELIGIBLE');
        $this->actingAs($this->teacherUser)->getJson($this->base().'/'.$pending->id)->assertOk()
            ->assertJsonPath('data.eligibility_status', null)->assertJsonPath('data.entry_status', 'PENDING')
            ->assertJsonPath('data.meets_entry_conditions', false)->assertJsonFragment(['code' => 'ELIGIBILITY_PENDING']);
        $this->getJson($this->base().'/'.$inactive->id)->assertOk()->assertJsonFragment(['code' => 'STUDENT_INACTIVE']);
        $this->getJson($this->base().'/'.$denied->id)->assertOk()->assertJsonPath('data.reason', 'Documento pendiente')
            ->assertJsonPath('data.observations', 'Presentar certificado')->assertJsonFragment(['code' => 'INELIGIBLE']);
    }

    public function test_detail_handles_unassociated_student_and_inactive_enrollment(): void
    {
        config(['app.debug' => true]);
        $outside = $this->student();
        $outsideResponse = $this->actingAs($this->teacherUser)->getJson($this->base().'/'.$outside->id)
            ->assertNotFound()->assertExactJson(['message' => 'Estudiante no encontrado.']);
        $missingResponse = $this->getJson($this->base().'/999999')->assertNotFound()
            ->assertExactJson(['message' => 'Estudiante no encontrado.']);
        $this->assertSame($outsideResponse->getContent(), $missingResponse->getContent());
        $this->getJson($this->base())->assertOk()->assertJsonCount(1, 'data');
        DB::table('enrollments')->where('student_id', $this->student->id)->update(['status' => 'INACTIVE']);
        $this->getJson($this->base().'/'.$this->student->id)->assertOk()
            ->assertJsonPath('data.enrollment_status', 'INACTIVE')->assertJsonPath('data.entry_status', 'PENDING');
        $this->getJson($this->base().'/999999')->assertNotFound();
    }

    public function test_foreign_students_cannot_be_read_or_found_by_owner_or_collaborator(): void
    {
        $outside = $this->student();
        $outside->update(['first_names' => 'Persona ajena', 'last_names' => 'Fuera del examen']);
        $otherExam = $this->exam->replicate();
        $otherExam->save();
        ExamEligibility::create(['exam_id' => $otherExam->id, 'student_id' => $outside->id, 'status' => 'ELIGIBLE']);
        $collaborator = $this->collaborator();

        foreach ([$this->teacherUser, $collaborator] as $operator) {
            $this->actingAs($operator);
            foreach ([$outside->first_names, $outside->sis_code, $outside->identity_number] as $search) {
                $this->getJson($this->base().'?'.http_build_query([
                    'search' => $search, 'student_id' => $outside->id, 'include_unassociated' => true,
                ]))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
            }
            $this->getJson($this->base().'/'.$outside->id.'?student_id='.$this->student->id.'&include_unassociated=1')
                ->assertNotFound()->assertExactJson(['message' => 'Estudiante no encontrado.']);
            $this->getJson($this->base().'/'.$this->student->id.'?student_id='.$outside->id)
                ->assertOk()->assertJsonPath('data.student_id', $this->student->id);
        }
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_each_association_alone_keeps_blocked_student_consultable(): void
    {
        $enrollmentOnly = $this->student();
        $this->enroll($enrollmentOnly, 'INACTIVE');
        $eligibilityOnly = $this->student();
        $this->eligible($eligibilityOnly, 'INELIGIBLE');
        $this->actingAs($this->teacherUser);

        $this->getJson($this->base().'/'.$enrollmentOnly->id)->assertOk()
            ->assertJsonPath('data.enrollment_status', 'INACTIVE')->assertJsonPath('data.eligibility_status', null)
            ->assertJsonPath('data.meets_entry_conditions', false)->assertJsonFragment(['code' => 'NOT_ENROLLED']);
        $this->getJson($this->base().'/'.$eligibilityOnly->id)->assertOk()
            ->assertJsonPath('data.enrollment_status', null)->assertJsonPath('data.eligibility_status', 'INELIGIBLE')
            ->assertJsonPath('data.reason', 'Documento pendiente')->assertJsonPath('data.meets_entry_conditions', false)
            ->assertJsonFragment(['code' => 'NOT_ENROLLED'])->assertJsonFragment(['code' => 'INELIGIBLE']);
        $this->getJson($this->base())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('exam_entries', 0);
    }

    public function test_room_comparison_and_invalid_parameters(): void
    {
        $room = DB::table('rooms')->insertGetId(['code' => 'OTHER', 'name' => 'Otra aula', 'status' => 'ACTIVE']);
        $this->actingAs($this->teacherUser)->getJson($this->base().'/'.$this->student->id.'?room_id='.$room)->assertOk()
            ->assertJsonPath('data.room_status', 'MISMATCH')->assertJsonPath('data.meets_entry_conditions', false)
            ->assertJsonFragment(['code' => 'ROOM_MISMATCH']);
        $this->getJson($this->base().'?room_id='.$room)->assertOk()
            ->assertJsonPath('summary.pending', 1)->assertJsonPath('data.0.room_status', 'MISMATCH');
        foreach (['per_page=101', 'per_page=0', 'page=0', 'page=abc', 'room_id=999999', 'room_id=abc', 'search[]=x'] as $query) {
            $this->getJson($this->base().'?'.$query)->assertUnprocessable();
        }
    }

    public function test_valid_entry_precedes_later_ineligibility_and_annulled_entries_do_not_count(): void
    {
        $id = $this->entry($this->student);
        $this->eligibility->update(['status' => 'INELIGIBLE', 'reason' => 'Nueva condición']);
        $this->actingAs($this->teacherUser)->getJson($this->base().'/'.$this->student->id)->assertOk()
            ->assertJsonPath('data.entry_status', 'REGISTERED')->assertJsonPath('data.entry.id', $id)
            ->assertJsonPath('data.entry.verified_by', $this->teacherUser->id)
            ->assertJsonPath('data.entry.verified_by_name', 'Juan Pérez')
            ->assertJsonPath('data.entry.verification_method', 'QR')
            ->assertJsonFragment(['code' => 'ALREADY_REGISTERED'])->assertJsonFragment(['code' => 'INELIGIBLE']);
        $this->getJson($this->base())->assertOk()->assertJsonPath('summary.registered', 1)->assertJsonPath('summary.not_applicable', 0);
        DB::table('exam_entries')->where('id', $id)->update(['status' => 'ANNULLED']);
        $this->eligibility->update(['status' => 'ELIGIBLE']);
        $this->getJson($this->base().'/'.$this->student->id)->assertOk()
            ->assertJsonPath('data.entry', null)->assertJsonPath('data.entry_status', 'PENDING')->assertJsonPath('data.meets_entry_conditions', true);
    }

    public function test_photo_urls_and_read_only_operations(): void
    {
        $this->student->user->update(['profile_photo' => 'profile-photos/student.png']);
        $tables = ['exam_entries', 'exam_eligibilities', 'enrollments', 'exam_collaborators', 'audit_logs'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->actingAs($this->teacherUser)->getJson($this->base())->assertOk()
            ->assertJsonPath('data.0.profile_photo_url', asset('storage/profile-photos/student.png'));
        $this->getJson($this->base().'/'.$this->student->id)->assertOk();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->student->user->update(['profile_photo' => 'https://example.com/photo.png']);
        $this->getJson($this->base().'/'.$this->student->id)->assertJsonPath('data.profile_photo_url', 'https://example.com/photo.png');
        $this->student->user->update(['profile_photo' => null]);
        $this->getJson($this->base().'/'.$this->student->id)->assertJsonPath('data.profile_photo_url', null);
    }

    public function test_only_owner_or_current_collaborator_can_access_both_routes(): void
    {
        $this->getJson($this->base())->assertUnauthorized();
        $this->getJson($this->base().'/'.$this->student->id)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($this->base())->assertForbidden();
        $collaborator = $this->collaborator();
        $this->actingAs($collaborator)->getJson($this->base())->assertOk();
        $this->getJson($this->base().'/'.$this->student->id)->assertOk();
        $otherExam = $this->exam->replicate();
        $otherExam->save();
        $this->getJson("/api/v1/exams/{$otherExam->id}/entry-control/students")->assertForbidden();
        $this->actingAs($this->teacherUser)->deleteJson("/api/v1/exams/{$this->exam->id}/collaborators/{$collaborator->id}")->assertOk();
        $this->actingAs($collaborator)->getJson($this->base())->assertForbidden();
        $this->getJson($this->base().'/'.$this->student->id)->assertForbidden();
    }

    public function test_collaborator_loses_access_on_cancellation_expiry_or_enrollment(): void
    {
        $collaborator = $this->collaborator();
        $this->exam->update(['status' => 'CANCELLED']);
        $this->actingAs($collaborator)->getJson($this->base())->assertForbidden();
        $this->exam->update(['status' => 'SCHEDULED', 'exam_date' => now()->subDay()->toDateString()]);
        $this->getJson($this->base())->assertForbidden();
        $this->exam->update(['exam_date' => now()->addDays(2)->toDateString()]);
        $student = $this->student();
        $role = Role::firstOrCreate(['name' => 'ESTUDIANTE'], ['status' => 'ACTIVE']);
        User::findOrFail($student->user_id)->roles()->syncWithoutDetaching([$role->id => ['status' => 'ACTIVE', 'assigned_at' => now()]]);
        $this->actingAs($this->teacherUser)->postJson("/api/v1/exams/{$this->exam->id}/collaborators", ['user_id' => $student->user_id])->assertCreated();
        $this->enroll($student);
        $this->actingAs($student->user)->getJson($this->base())->assertForbidden();
    }

    public function test_owner_without_active_teacher_role_cannot_use_owner_access(): void
    {
        $this->teacherUser->roles()->detach();
        $this->actingAs($this->teacherUser)->getJson($this->base())->assertForbidden();
    }

    public function test_other_exam_data_is_not_mixed_and_missing_exam_returns_404(): void
    {
        $otherExam = $this->exam->replicate();
        $otherExam->save();
        $this->entry($this->student, 'VALID', $otherExam->id);
        $this->actingAs($this->teacherUser)->getJson($this->base())->assertOk()
            ->assertJsonPath('data.0.entry_status', 'PENDING')->assertJsonPath('summary.registered', 0);
        $this->getJson('/api/v1/exams/999999/entry-control/students')->assertNotFound();
    }

    private function photoAdmin(): User
    {
        $admin = User::factory()->create(['must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $admin->roles()->attach($role->id, ['status' => 'ACTIVE', 'assigned_at' => now()]);

        return $admin;
    }

    public function test_historical_photo_is_null_until_admin_upload_and_propagates_to_qr_manual_and_history(): void
    {
        Storage::fake('local');
        $this->student->user->update(['profile_photo' => 'avatars/current.png']);
        $this->actingAs($this->teacherUser)->getJson($this->base().'/'.$this->student->id)
            ->assertJsonPath('data.historical_photo_url', null)->assertJsonPath('data.profile_photo_url', asset('storage/avatars/current.png'));
        $endpoint = "/api/v1/admin/students/{$this->student->id}/historical-photo";
        $this->actingAs($this->photoAdmin())->postJson($endpoint, ['photo' => UploadedFile::fake()->image('institution.png'), 'revision' => 0])
            ->assertCreated()->assertJsonPath('data.historical_photo_revision', 1);
        $this->student->refresh();
        $path = $this->student->historical_photo;
        Storage::disk('local')->assertExists($path);
        $this->getJson($endpoint)->assertOk()->assertHeader('Content-Type', 'image/png');
        $photoUrl = route('entry-control.historical-photo', ['exam_id' => $this->exam->id, 'student_id' => $this->student->id]);
        $this->actingAs($this->teacherUser)->getJson($this->base().'/'.$this->student->id)->assertJsonPath('data.historical_photo_url', $photoUrl);
        $this->getJson($photoUrl)->assertOk();
        $this->getJson($this->base())->assertJsonPath('data.0.historical_photo_url', $photoUrl);
        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->student->id, $this->exam->id);
        $entryBase = "/api/v1/exams/{$this->exam->id}/entry-control";
        $this->postJson($entryBase.'/qr', ['qr_token' => $qr['token'], 'room_id' => $this->exam->room_id])
            ->assertCreated()->assertJsonPath('data.student.historical_photo_url', $photoUrl)->assertJsonPath('data.student.profile_photo_url', asset('storage/avatars/current.png'));
        $this->postJson($this->base().'/'.$this->student->id.'/entries', ['room_id' => $this->exam->room_id])
            ->assertConflict()->assertJsonPath('data.student.historical_photo_url', $photoUrl);
        DB::table('exam_entries')->where('exam_id', $this->exam->id)->update(['status' => 'ANNULLED']);
        $this->postJson($this->base().'/'.$this->student->id.'/entries', ['room_id' => $this->exam->room_id])
            ->assertCreated()->assertJsonPath('data.entry.verification_method', 'MANUAL')->assertJsonPath('data.student.historical_photo_url', $photoUrl);
        $this->getJson($entryBase.'/entries')->assertJsonPath('data.0.student.historical_photo_url', $photoUrl);
        $otherExam = $this->exam->replicate();
        $otherExam->course_offering_id = $this->exam->course_offering_id;
        $otherExam->save();
        // No eligibility alone is manufactured by this read; enrollment remains
        // in the same offering, so use a student with no association at all.
        $unassociated = $this->student();
        $unassociated->forceFill(['historical_photo' => $path])->save();
        $this->getJson(route('entry-control.historical-photo', ['exam_id' => $otherExam->id, 'student_id' => $unassociated->id]))->assertNotFound();
    }

    public function test_profile_photo_updates_do_not_replace_or_remove_the_institutional_reference(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($this->photoAdmin())->postJson("/api/v1/admin/students/{$this->student->id}/historical-photo", ['photo' => UploadedFile::fake()->image('institution.jpg'), 'revision' => 0])->assertCreated();
        $path = $this->student->refresh()->historical_photo;
        app(StudentProfileService::class)->updateProfilePhoto($this->student->user, UploadedFile::fake()->image('current.jpg'), null, null);
        $this->assertSame($path, $this->student->refresh()->historical_photo);
        $this->assertNotSame($path, $this->student->user->fresh()->profile_photo);
        Storage::disk('local')->assertExists($path);
    }

    public function test_only_admin_can_create_or_correct_institutional_photo_with_revision_and_reason(): void
    {
        Storage::fake('local');
        $endpoint = "/api/v1/admin/students/{$this->student->id}/historical-photo";
        $this->postJson($endpoint)->assertUnauthorized();
        $this->actingAs($this->teacherUser)->postJson($endpoint)->assertForbidden();
        $this->actingAs($this->student->user)->postJson($endpoint)->assertForbidden();
        $admin = $this->photoAdmin();
        $this->actingAs($admin)->postJson($endpoint, ['photo' => UploadedFile::fake()->image('institution.jpg'), 'revision' => 0])->assertCreated();
        $old = $this->student->refresh()->historical_photo;
        $this->postJson($endpoint, ['photo' => UploadedFile::fake()->image('overwrite.jpg'), 'revision' => 1])->assertConflict()->assertJsonPath('code', 'HISTORICAL_PHOTO_EXISTS');
        $this->postJson($endpoint.'/corrections', ['photo' => UploadedFile::fake()->image('correct.jpg'), 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($endpoint.'/corrections', ['photo' => UploadedFile::fake()->image('correct.jpg'), 'revision' => 0, 'reason' => 'Corrección institucional'])->assertConflict();
        $this->postJson($endpoint.'/corrections', ['photo' => UploadedFile::fake()->image('correct.jpg'), 'revision' => 1, 'reason' => 'Corrección institucional'])->assertOk()->assertJsonPath('data.historical_photo_revision', 2);
        Storage::disk('local')->assertExists($old);
        $this->assertNotSame($old, $this->student->refresh()->historical_photo);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_INSTITUTIONAL_PHOTO_CORRECTED', 'entity_id' => $this->student->id, 'user_id' => $admin->id]);
        $this->actingAs(User::factory()->create())->getJson(route('entry-control.historical-photo', ['exam_id' => $this->exam->id, 'student_id' => $this->student->id]))->assertForbidden();
        $admin->update(['status' => 'INACTIVE']);
        $this->actingAs($admin)->postJson($endpoint)->assertUnauthorized();
    }

    public function test_invalid_institutional_files_and_audit_failures_do_not_persist_files_or_references(): void
    {
        Storage::fake('local');
        $endpoint = "/api/v1/admin/students/{$this->student->id}/historical-photo";
        $this->actingAs($this->photoAdmin());
        foreach ([UploadedFile::fake()->image('large.png')->size(5121), UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')] as $file) {
            $this->postJson($endpoint, ['photo' => $file, 'revision' => 0])->assertUnprocessable();
        }
        $this->mock(AuditLogService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($endpoint, ['photo' => UploadedFile::fake()->image('institution.jpg'), 'revision' => 0])->assertStatus(500);
        $this->assertNull($this->student->refresh()->historical_photo);
        $this->assertSame([], Storage::disk('local')->allFiles('institutional-photos'));
    }
}
