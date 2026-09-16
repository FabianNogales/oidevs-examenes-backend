<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Career;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Role;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EidaDemoSeeder extends Seeder
{
    private const DIRECT_ADMIN_PASSWORD = 'DemoAdmin1';

    public function __construct(private readonly InitialPasswordService $initialPasswordService) {}

    public function run(): void
    {
        $domain = $this->institutionalDomain();

        $roles = $this->ensureRoles();

        $admin = $this->ensureUser(
            email: "demo.admin@{$domain}",
            password: self::DIRECT_ADMIN_PASSWORD,
            status: UserStatus::ACTIVE->value,
            mustChangePassword: false,
        );
        $this->assignRole($admin, $roles[RoleName::ADMINISTRADOR->value]);

        $teacher = $this->ensureTeacher([
            'email' => "demo.docente@{$domain}",
            'institutional_code' => 'DEMO-EIDA-DOC-001',
            'identity_number' => '71000001',
            'first_names' => 'Docente',
            'last_names' => 'Principal Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ], $roles);

        $this->ensureTeacher([
            'email' => "demo.docente.segundo@{$domain}",
            'institutional_code' => 'DEMO-EIDA-DOC-002',
            'identity_number' => '71000002',
            'first_names' => 'Marcela',
            'last_names' => 'Quiroga Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ], $roles);

        $this->ensureTeacher([
            'email' => "demo.docente.sinmaterias@{$domain}",
            'institutional_code' => 'DEMO-EIDA-DOC-003',
            'identity_number' => '71000003',
            'first_names' => 'Ruben',
            'last_names' => 'Vacio Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
        ], $roles);

        $this->ensureTeacher([
            'email' => "demo.docente.inactivo@{$domain}",
            'institutional_code' => 'DEMO-EIDA-DOC-004',
            'identity_number' => '71000004',
            'first_names' => 'Elena',
            'last_names' => 'Inactiva Demo',
            'status' => UserStatus::INACTIVE->value,
            'must_change_password' => false,
        ], $roles);

        $career = Career::query()->updateOrCreate(
            ['code' => 'DEMO-EIDA-SIS'],
            [
                'name' => 'DEMO EIDA Ingenieria de Sistemas',
                'status' => UserStatus::ACTIVE->value,
            ],
        );

        $term = $this->ensureAcademicTerm();

        $softwareWorkshop = Subject::query()->updateOrCreate(
            ['code' => 'DEMO-EIDA-TIS'],
            [
                'name' => 'DEMO EIDA Taller de Ingenieria de Software',
                'status' => UserStatus::ACTIVE->value,
            ],
        );

        $networks = Subject::query()->updateOrCreate(
            ['code' => 'DEMO-EIDA-RED'],
            [
                'name' => 'DEMO EIDA Redes de Computadoras',
                'status' => UserStatus::ACTIVE->value,
            ],
        );

        $roomA = $this->ensureRoom('DEMO-EIDA-AULA-A', 'DEMO EIDA Aula A', 'Edificio MEMI - Planta 2');
        $roomB = $this->ensureRoom('DEMO-EIDA-AULA-B', 'DEMO EIDA Aula B', 'Edificio MEMI - Planta 3');

        $offeringA = CourseOffering::query()->updateOrCreate(
            [
                'subject_id' => $softwareWorkshop->id,
                'academic_term_id' => $term->id,
                'teacher_id' => $teacher->id,
            ],
            ['status' => UserStatus::ACTIVE->value],
        );

        $offeringB = CourseOffering::query()->updateOrCreate(
            [
                'subject_id' => $networks->id,
                'academic_term_id' => $term->id,
                'teacher_id' => $teacher->id,
            ],
            ['status' => UserStatus::ACTIVE->value],
        );

        $studentA = $this->ensureStudent([
            'email' => "demo.estudiante@{$domain}",
            'sis_code' => '202600001',
            'identity_number' => '81000001',
            'first_names' => 'Ana',
            'last_names' => 'Estudiante Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
            'career_id' => $career->id,
        ], $roles);

        $studentB = $this->ensureStudent([
            'email' => "demo.estudiante.b@{$domain}",
            'sis_code' => '202600002',
            'identity_number' => '81000002',
            'first_names' => 'Bruno',
            'last_names' => 'Inscrito Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
            'career_id' => $career->id,
        ], $roles);

        $studentC = $this->ensureStudent([
            'email' => "demo.estudiante.noinscrito@{$domain}",
            'sis_code' => '202600003',
            'identity_number' => '81000003',
            'first_names' => 'Carla',
            'last_names' => 'Padron Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
            'career_id' => $career->id,
        ], $roles);

        $firstAccessStudent = $this->ensureStudent([
            'email' => "demo.firstaccess@{$domain}",
            'sis_code' => '202600004',
            'identity_number' => '81000004',
            'first_names' => 'Diego',
            'last_names' => 'Primer Acceso Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => true,
            'career_id' => $career->id,
        ], $roles);

        $inactiveStudent = $this->ensureStudent([
            'email' => "demo.inactive@{$domain}",
            'sis_code' => '202600005',
            'identity_number' => '81000005',
            'first_names' => 'Eva',
            'last_names' => 'Inactiva Demo',
            'status' => UserStatus::INACTIVE->value,
            'must_change_password' => false,
            'career_id' => $career->id,
        ], $roles);

        $notEligibleStudent = $this->ensureStudent([
            'email' => "demo.estudiante.nohabilitado@{$domain}",
            'sis_code' => '202600006',
            'identity_number' => '81000006',
            'first_names' => 'Fabian',
            'last_names' => 'No Habilitado Demo',
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => false,
            'career_id' => $career->id,
        ], $roles);

        foreach ([$studentA, $studentB, $notEligibleStudent] as $student) {
            $this->ensureEnrollment($offeringA, $student, $teacher->user);
        }

        foreach ([$studentA, $studentB] as $student) {
            $this->ensureEnrollment($offeringB, $student, $teacher->user);
        }

        $availableAt = Carbon::now('America/La_Paz')->addHours(2);
        $futureAt = Carbon::now('America/La_Paz')->addDays(3)->setTime(10, 0);
        $pastAt = Carbon::now('America/La_Paz')->subDay()->setTime(8, 0);

        $examA = $this->ensureExam(
            $offeringA,
            $roomA,
            $teacher->user,
            'DEMO EIDA QR disponible - Parcial TIS',
            $availableAt,
            'QR disponible para estudiantes inscritos dentro de la ventana de 24 horas.',
        );

        $examB = $this->ensureExam(
            $offeringA,
            $roomB,
            $teacher->user,
            'DEMO EIDA QR no disponible - Final TIS',
            $futureAt,
            'QR no disponible todavia por estar fuera de la ventana de 24 horas.',
        );

        $examC = $this->ensureExam(
            $offeringB,
            $roomA,
            $teacher->user,
            'DEMO EIDA examen pasado - Redes',
            $pastAt,
            'Examen pasado para validar mensajes de expiracion del QR.',
        );

        $this->ensureEligibility($examA, $studentA, 'ELIGIBLE', $teacher->user, 'DEMO: estudiante inscrito y habilitado.');
        $this->ensureEligibility($examA, $studentB, 'ELIGIBLE', $teacher->user, 'DEMO: segundo estudiante inscrito y habilitado.');
        $this->ensureEligibility($examA, $notEligibleStudent, 'NOT_ELIGIBLE', $teacher->user, 'DEMO: inscrito pero marcado como no habilitado. El servicio QR actual no consulta esta tabla.');
        $this->ensureEligibility($examB, $studentA, 'ELIGIBLE', $teacher->user, 'DEMO: fuera de ventana QR por fecha.');
        $this->ensureEligibility($examC, $studentA, 'ELIGIBLE', $teacher->user, 'DEMO: examen pasado.');

        $this->command?->info('EidaDemoSeeder listo. Dominio usado: '.$domain);
        $this->command?->warn('Nota: las rutas docentes actuales requieren tambien el rol legado Docente.');
        $this->command?->line('Usuarios clave: '.$admin->email.', '.$teacher->user->email.', '.$studentA->user->email.'.');
        $this->command?->line('Estudiante sin inscripcion: '.$studentC->sis_code.'. First access: '.$firstAccessStudent->sis_code.'. Inactivo: '.$inactiveStudent->sis_code.'.');
    }

    /**
     * @return array<string, Role>
     */
    private function ensureRoles(): array
    {
        $officialRoles = [
            RoleName::ADMINISTRADOR->value => 'Administracion del sistema EIDA.',
            RoleName::DOCENTE->value => 'Gestion docente dentro del sistema EIDA.',
            RoleName::ESTUDIANTE->value => 'Acceso estudiantil al sistema EIDA.',
            'Docente' => 'Rol legado requerido por el middleware teacher.role actual.',
        ];

        $roles = [];

        foreach ($officialRoles as $name => $description) {
            $roles[$name] = Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => $description,
                    'status' => UserStatus::ACTIVE->value,
                ],
            );
        }

        return $roles;
    }

    private function ensureUser(string $email, string $password, string $status, bool $mustChangePassword): User
    {
        $user = User::query()->firstOrNew(['email' => strtolower($email)]);

        $user->forceFill([
            'password' => Hash::make($password),
            'status' => $status,
            'must_change_password' => $mustChangePassword,
        ])->save();

        return $user->refresh();
    }

    private function ensureAcademicTerm(): object
    {
        DB::table('academic_terms')->updateOrInsert(
            ['name' => 'DEMO EIDA 2/2026'],
            [
                'start_date' => '2026-08-01',
                'end_date' => '2026-12-31',
                'status' => UserStatus::ACTIVE->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return DB::table('academic_terms')->where('name', 'DEMO EIDA 2/2026')->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Role>  $roles
     */
    private function ensureTeacher(array $data, array $roles): Teacher
    {
        $user = User::query()->updateOrCreate(
            ['email' => strtolower((string) $data['email'])],
            [
                'password' => Hash::make((string) $data['identity_number']),
                'status' => $data['status'],
            ],
        );

        $this->initialPasswordService->initialize($user, (string) $data['identity_number']);
        $user->forceFill([
            'status' => $data['status'],
            'must_change_password' => (bool) $data['must_change_password'],
        ])->save();

        $teacher = Teacher::query()->updateOrCreate(
            ['institutional_code' => $data['institutional_code']],
            [
                'user_id' => $user->id,
                'identity_number' => $data['identity_number'],
                'first_names' => $data['first_names'],
                'last_names' => $data['last_names'],
                'status' => $data['status'],
            ],
        );

        $this->assignRole($user, $roles[RoleName::DOCENTE->value]);
        $this->assignRole($user, $roles['Docente']);

        return $teacher->refresh()->load('user');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, Role>  $roles
     */
    private function ensureStudent(array $data, array $roles): Student
    {
        $user = User::query()->updateOrCreate(
            ['email' => strtolower((string) $data['email'])],
            [
                'password' => Hash::make((string) $data['identity_number']),
                'status' => $data['status'],
            ],
        );

        $this->initialPasswordService->initialize($user, (string) $data['identity_number']);
        $user->forceFill([
            'status' => $data['status'],
            'must_change_password' => (bool) $data['must_change_password'],
        ])->save();

        $student = Student::query()->updateOrCreate(
            ['sis_code' => $data['sis_code']],
            [
                'user_id' => $user->id,
                'identity_number' => $data['identity_number'],
                'first_names' => $data['first_names'],
                'last_names' => $data['last_names'],
                'career_id' => $data['career_id'],
                'status' => $data['status'],
            ],
        );

        $this->assignRole($user, $roles[RoleName::ESTUDIANTE->value]);

        return $student->refresh()->load('user');
    }

    private function assignRole(User $user, Role $role): void
    {
        DB::table('role_user')->updateOrInsert(
            [
                'user_id' => $user->id,
                'role_id' => $role->id,
            ],
            [
                'assigned_at' => now(),
                'assigned_by' => null,
                'status' => UserStatus::ACTIVE->value,
            ],
        );
    }

    private function ensureRoom(string $code, string $name, string $location): object
    {
        DB::table('rooms')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $name,
                'location' => $location,
                'status' => UserStatus::ACTIVE->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return DB::table('rooms')->where('code', $code)->first();
    }

    private function ensureEnrollment(CourseOffering $offering, Student $student, User $registeredBy): void
    {
        DB::table('enrollments')->updateOrInsert(
            [
                'course_offering_id' => $offering->id,
                'student_id' => $student->id,
            ],
            [
                'status' => UserStatus::ACTIVE->value,
                'registered_by' => $registeredBy->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function ensureExam(
        CourseOffering $offering,
        object $room,
        User $createdBy,
        string $name,
        Carbon $scheduledAt,
        string $rules,
    ): Exam {
        return Exam::query()->updateOrCreate(
            [
                'course_offering_id' => $offering->id,
                'name' => $name,
            ],
            [
                'room_id' => $room->id,
                'exam_date' => $scheduledAt->toDateString(),
                'start_time' => $scheduledAt->format('H:i:s'),
                'duration_minutes' => 90,
                'rules' => $rules,
                'status' => UserStatus::ACTIVE->value,
                'created_by' => $createdBy->id,
            ],
        );
    }

    private function ensureEligibility(Exam $exam, Student $student, string $status, User $evaluatedBy, string $reason): void
    {
        DB::table('exam_eligibilities')->updateOrInsert(
            [
                'exam_id' => $exam->id,
                'student_id' => $student->id,
            ],
            [
                'status' => $status,
                'reason' => $reason,
                'evaluated_by' => $evaluatedBy->id,
                'evaluated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function institutionalDomain(): string
    {
        $domains = config('eida.institutional_email_domains', []);

        if (is_array($domains) && count($domains) > 0) {
            return (string) $domains[0];
        }

        return 'umss.edu.bo';
    }
}
