<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\AcademicTerm;
use App\Models\Exam;
use App\Models\Role;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use App\Services\Audit\AuditLogService;
use App\Services\Collaborators\ExamCollaboratorService;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Exams\ExamRevision;
use App\Services\Rooms\RoomAvailabilityService;
use App\Services\Students\StudentRegistrationService;
use App\Services\Teachers\TeacherService;
use App\Support\Eligibility\EligibilityRules;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Explicitly invoked demo snapshots; see docs/EIDA_FULL_DEMO_SEEDER.md. */
class EidaFullDemoSeeder extends Seeder
{
    private const DATASET = 'EIDA-FULLQA-v1';

    private const STAGE_ACTION = 'DEMO_SEED_STAGE_CREATED';

    private const ADMIN_PASSWORD = 'DemoAdmin1';

    private const TEACHER_PASSWORD = 'DemoTeacher1';

    private const STUDENT_PASSWORD = 'DemoStudent1';

    private CarbonImmutable $now;

    private string $domain;

    private ?User $actor = null;

    /** @var array<string, array<string|int, int>> IDs owned by immutable stage manifests. */
    private array $ids = [];

    private array $pending = [];

    public function run(): void
    {
        if (config('eida.allow_demo_seeding') !== true) {
            throw new DomainException('Demo deshabilitada: configura EIDA_ALLOW_DEMO_SEEDING=true solo en la BD de demostracion.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new DomainException('EidaFullDemoSeeder requiere PostgreSQL y todas las migraciones actuales.');
        }
        $this->now = CarbonImmutable::now(ExamEntryAvailability::TIMEZONE)->startOfMinute();
        $domains = config('eida.institutional_email_domains', []);
        $this->domain = strtolower(trim((string) ($domains[0] ?? '')));
        if ($this->domain === '' || filter_var('fullqa.admin@'.$this->domain, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Configura al menos un EIDA_INSTITUTIONAL_EMAIL_DOMAINS valido; no se inventa un dominio alternativo.');
        }
        $this->ids = [];
        $this->actor = null;
        foreach (RoleName::cases() as $role) {
            if (Role::where('name', $role->value)->where('status', '!=', 'ACTIVE')->exists()) {
                throw new DomainException('Existe un rol oficial inactivo. La demo no modifica roles existentes.');
            }
        }
        $this->stage('foundation', fn () => $this->foundation());
        $this->actor = User::findOrFail($this->id('users', 'admin'));
        $this->stage('academic', fn () => $this->academic());
        $this->stage('scenarios', fn () => $this->scenarios());
        $this->refreshTimeline();

        $this->command?->info('EIDA FULLQA lista. Dominio: '.$this->domain.'; horarios: America/La_Paz.');
        $this->command?->line('Admin: fullqa.admin@'.$this->domain.' / '.self::ADMIN_PASSWORD.' (solo creacion inicial).');
        $this->command?->line('No se restablecen passwords, estados, habilitaciones, colaboraciones ni ingresos en reejecuciones.');
    }

    /** Each small phase and its ownership manifest commit or roll back together. */
    private function stage(string $stage, Closure $create): void
    {
        app(AcademicTermIntegrity::class)->transaction(function () use ($stage, $create): void {
            // Same order as timeline refresh: period lock, then demo lock.
            // Serializes concurrent invocations, even when no manifest exists yet.
            DB::select('SELECT pg_advisory_xact_lock(240028, 1)');
            $manifests = \App\Models\AuditLog::where('action', self::STAGE_ACTION)
                ->where('entity_type', self::DATASET)->where('new_values->stage', $stage)->limit(2)->get();
            if ($manifests->count() > 1) {
                throw new DomainException('Manifiestos demo duplicados; se requiere revision manual.');
            }
            $manifest = $manifests->first();
            if ($manifest !== null) {
                $values = $manifest->new_values;
                if (($values['domain'] ?? null) !== $this->domain) {
                    throw new DomainException('El dominio cambio desde la primera seed. Conserva el dominio original; no se duplicaran cuentas.');
                }
                foreach ($values['records'] as $table => $records) {
                    foreach ($records as $key => $id) {
                        if (! DB::table($table)->where('id', $id)->exists()) {
                            throw new DomainException("Falta un registro del manifiesto {$stage}: {$table}/{$id}. No se recrea silenciosamente.");
                        }
                        $this->ids[$table][$key] = (int) $id;
                    }
                }

                return;
            }
            $this->pending = [];
            $create();
            $this->audit(self::STAGE_ACTION, self::DATASET, null, null, [
                'stage' => $stage, 'domain' => $this->domain, 'records' => $this->pending,
            ]);
        });
    }

    private function foundation(): void
    {
        // Fail on existing period conflicts before creating accounts or catalogues.
        foreach ($this->termPlan() as $data) {
            app(AcademicTermIntegrity::class)->assertCanSave($data);
        }
        $this->ensureRoles();
        foreach ([['SIS', 'Sistemas', 'ACTIVE'], ['INF', 'Informatica', 'ACTIVE'], ['LEG', 'Carrera historica', 'INACTIVE']] as [$key, $name, $status]) {
            $this->insert('careers', $key, ['code' => 'FULLQA-'.$key, 'name' => 'FULLQA '.$name, 'status' => $status], ['code']);
        }
        foreach ([['TIS', 'Taller de Software', 'ACTIVE'], ['RED', 'Redes', 'ACTIVE'], ['BD', 'Bases de Datos', 'ACTIVE'], ['OLD', 'Materia historica', 'INACTIVE']] as [$key, $name, $status]) {
            $this->insert('subjects', $key, ['code' => 'FULLQA-'.$key, 'name' => 'FULLQA '.$name, 'status' => $status], ['code']);
        }
        foreach ([['SIS', 'TIS'], ['INF', 'TIS'], ['SIS', 'RED'], ['INF', 'RED'], ['INF', 'BD'], ['SIS', 'BD'], ['LEG', 'OLD']] as [$career, $subject]) {
            $this->insert('career_subject', $career.'-'.$subject, [
                'career_id' => $this->id('careers', $career), 'subject_id' => $this->id('subjects', $subject),
            ]);
        }
        foreach (['A', 'B', 'C', 'OFF'] as $index => $key) {
            $this->insert('rooms', $key, [
                'code' => 'FULLQA-'.$key, 'name' => 'FULLQA Aula '.$key, 'location' => 'Edificio ficticio FULLQA',
                'description' => 'Ambiente ficticio para demostracion.', 'capacity' => 25 + $index * 10,
                'floor' => (string) ($index + 1), 'status' => $key === 'OFF' ? 'INACTIVE' : 'ACTIVE',
            ], ['code', 'name']);
        }

        $email = 'fullqa.admin@'.$this->domain;
        $this->vacant('users', ['email' => $email]);
        $admin = User::forceCreate(['email' => $email, 'password' => Hash::make(self::ADMIN_PASSWORD),
            'status' => 'ACTIVE', 'must_change_password' => false, 'profile_photo' => null]);
        $this->remember('users', 'admin', $admin->id);
        $this->actor = $admin;
        $this->recordRole($admin, RoleName::ADMINISTRADOR);

        foreach ([1 => 'Irene', 2 => 'Tomas'] as $n => $name) {
            $data = ['email' => "fullqa.docente{$n}@{$this->domain}", 'institutional_code' => "fullqa-doc-0{$n}",
                'identity_number' => (string) (99000100 + $n), 'first_names' => $name, 'last_names' => 'Demo Docente'];
            $this->vacant('users', ['email' => $data['email']]);
            $this->vacant('teachers', array_intersect_key($data, array_flip(['institutional_code', 'identity_number'])));
            $teacher = app(TeacherService::class)->create($data, $admin, $this->request($admin));
            // The service initializes CI + must_change_password; these ready accounts simulate a completed first access.
            $teacher->user->forceFill(['password' => Hash::make(self::TEACHER_PASSWORD), 'must_change_password' => false])->save();
            $this->remember('teachers', $n, $teacher->id);
            $this->remember('users', 'teacher'.$n, $teacher->user_id);
            $this->recordRole($teacher->user, RoleName::DOCENTE);
        }

        $names = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elena', 'Felipe', 'Gabriela', 'Hugo', 'Ines', 'Julia', 'Kevin', 'Lucia'];
        foreach ($names as $index => $name) {
            $n = $index + 1;
            $data = ['email' => sprintf('fullqa.estudiante%02d@%s', $n, $this->domain),
                'sis_code' => (string) (990260000 + $n), 'identity_number' => (string) (99010000 + $n),
                'first_names' => $name, 'last_names' => 'Demo Estudiante', 'profile_photo' => null];
            $this->vacant('users', ['email' => $data['email']]);
            $this->vacant('students', array_intersect_key($data, array_flip(['sis_code', 'identity_number'])));
            $career = \App\Models\Career::findOrFail($this->id('careers', $n % 2 === 1 ? 'SIS' : 'INF'));
            $student = app(StudentRegistrationService::class)->create($data, $career, $admin, $this->request($admin));
            if ($n !== 8) {
                $student->user->forceFill(['password' => Hash::make(self::STUDENT_PASSWORD), 'must_change_password' => false])->save();
            }
            // Initial snapshots: 09 has inactive account/profile; 10 has active account but inactive academic profile.
            if (in_array($n, [9, 10], true)) {
                $student->forceFill(['status' => 'INACTIVE'])->save();
                if ($n === 9) {
                    $student->user->forceFill(['status' => 'INACTIVE'])->save();
                }
                $this->audit('DEMO_PROFILE_SNAPSHOT_INITIALIZED', 'Student', $student->id, null,
                    ['student_id' => $student->id, 'status' => 'INACTIVE', 'user_status' => $student->user->status]);
            }
            $this->remember('students', $n, $student->id);
            $this->remember('users', 'student'.$n, $student->user_id);
            $this->recordRole($student->user, RoleName::ESTUDIANTE);
        }
    }

    /** Bootstrap official roles without depending on another seeder or overwriting existing roles. */
    private function ensureRoles(): void
    {
        $roles = [
            RoleName::ADMINISTRADOR->value => 'Administracion del sistema EIDA.',
            RoleName::DOCENTE->value => 'Gestion docente dentro del sistema EIDA.',
            RoleName::ESTUDIANTE->value => 'Acceso estudiantil al sistema EIDA.',
        ];

        foreach ($roles as $name => $description) {
            Role::query()->firstOrCreate(
                ['name' => $name],
                ['description' => $description, 'status' => 'ACTIVE'],
            );
        }
    }

    /** Guard every independent natural UNIQUE, never adopt an existing account by email/SIS/CI. */
    private function vacant(string $table, array $keys): void
    {
        foreach ($keys as $field => $value) {
            $query = DB::table($table);
            $exists = $field === 'email'
                ? $query->whereRaw('LOWER(email) = ?', [strtolower($value)])->exists()
                : $query->where($field, $value)->exists();
            if ($exists) {
                throw new DomainException("Colision de namespace demo en {$table}.{$field}; no se adopta ni modifica el registro existente.");
            }
        }
    }

    private function recordRole(User $user, RoleName $roleName): void
    {
        $role = Role::where('name', $roleName->value)->where('status', 'ACTIVE')->firstOrFail();
        $pivot = DB::table('role_user')->where('user_id', $user->id)->where('role_id', $role->id)->first();
        if ($pivot === null) {
            $id = DB::table('role_user')->insertGetId(['user_id' => $user->id, 'role_id' => $role->id,
                'assigned_at' => now(), 'assigned_by' => $this->actor->id, 'status' => 'ACTIVE']);
        } else {
            $id = $pivot->id;
        }
        $this->remember('role_user', $user->id, $id);
        $this->audit('USER_ROLE_ASSIGNED', 'User', $user->id, null, ['role_id' => $role->id, 'role' => $roleName->value,
            'assigned' => true, 'assigned_at' => now()->toISOString(), 'assigned_by' => $this->actor->id]);
    }

    private function academic(): void
    {
        app(AcademicTermIntegrity::class)->transaction(function (): void {
            foreach ($this->termPlan() as $key => $data) {
                $data = app(AcademicTermIntegrity::class)->validatePayload($data, true);
                app(AcademicTermIntegrity::class)->assertCanSave($data);
                $id = $this->insert('academic_terms', $key, $data, ['name']);
                $this->audit('DEMO_PERIOD_SNAPSHOT_CREATED', 'ACADEMIC_TERM', $id, null, $data);
            }
            foreach ($this->offeringPlan() as $key => [$teacher, $subject, $term, $status]) {
                // Historical INACTIVE offering is a snapshot, not a new assignment into an inactive period via API.
                $values = ['teacher_id' => $this->id('teachers', $teacher), 'subject_id' => $this->id('subjects', $subject),
                    'academic_term_id' => $this->id('academic_terms', $term), 'status' => $status];
                $id = $this->insert('course_offerings', $key, $values);
                $this->audit('DEMO_OFFERING_SNAPSHOT_CREATED', 'CourseOffering', $id, null, $values);
            }
            foreach ($this->enrollmentPlan() as $offering => $students) {
                $teacher = $this->offeringPlan()[$offering][0];
                foreach ($students as $n) {
                    $this->insert('enrollments', $offering.'-'.$n, ['course_offering_id' => $this->id('course_offerings', $offering),
                        'student_id' => $this->id('students', $n), 'registered_by' => $this->id('users', 'teacher'.$teacher),
                        'status' => ($offering === 'MAIN' && $n === 5) || $offering === 'OFF' ? 'INACTIVE' : 'ACTIVE']);
                }
            }
        });
    }

    private function scenarios(): void
    {
        foreach ($this->examPlan() as $key => [$offering, $room, $start, $duration, $status, $type]) {
            $teacher = $this->offeringPlan()[$offering][0];
            if (in_array($status, ExamEntryAvailability::STATES, true)) {
                app(RoomAvailabilityService::class)->ensureReservable($this->id('rooms', $room), $start->toDateString(), $start->format('H:i:s'), $duration);
            }
            $values = ['course_offering_id' => $this->id('course_offerings', $offering), 'room_id' => $this->id('rooms', $room),
                'name' => 'FULLQA '.$key, 'exam_date' => $start->toDateString(), 'start_time' => $start->format('H:i:s'),
                'duration_minutes' => $duration, 'status' => $status, 'evaluation_type' => $type,
                'rules' => 'FULLQA: portar CI; datos y credenciales ficticios de QA.', 'created_by' => $this->id('users', 'teacher'.$teacher)];
            $id = $this->insert('exams', $key, $values);
            // The database independently adds CREATE/Exam; keep both events honest and distinct.
            $this->audit('DEMO_EXAM_SNAPSHOT_CREATED', 'Exam', $id, null, $values);
            $students = match ($key) {
                'A-INGRESO' => [1, 2, 3, 4, 5, 7, 8, 9, 10],
                'B-PROXIMO', 'C-FUTURO', 'F-CANCELADO' => [1, 2, 3, 4, 6, 7, 8],
                default => $this->enrollmentPlan()[$offering],
            };
            foreach ($students as $n) {
                $ineligible = $n === 2 && in_array($key, ['A-INGRESO', 'B-PROXIMO'], true);
                $eligibility = EligibilityRules::values(['status' => $ineligible ? 'INELIGIBLE' : 'ELIGIBLE',
                    'reason_code' => 'INSTITUTIONAL_REQUIREMENT_PENDING', 'observations' => 'FULLQA: documento ficticio pendiente.']);
                $this->insert('exam_eligibilities', $key.'-'.$n, $eligibility + ['exam_id' => $id,
                    'student_id' => $this->id('students', $n), 'evaluated_by' => $values['created_by'],
                    'evaluated_at' => $start->subDay()->min($this->now)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), 'revision' => 1]);
            }
        }

        $teacher = User::findOrFail($this->id('users', 'teacher1'));
        $service = app(ExamCollaboratorService::class);
        foreach ([['A-INGRESO', 'student12', false], ['B-PROXIMO', 'teacher2', false], ['A-INGRESO', 'student11', true]] as [$exam, $user, $revoke]) {
            $collaboration = $service->assignCollaborator($this->id('exams', $exam), $this->id('users', $user), $this->request($teacher));
            $this->remember('exam_collaborators', $exam.'-'.$user, $collaboration->id);
            if ($revoke) {
                $service->revokeCollaborator($this->id('exams', $exam), $this->id('users', $user), $this->request($teacher));
            }
        }
        foreach ($this->entryPlan() as $key => [$exam, $n, $method]) {
            $examRow = Exam::findOrFail($this->id('exams', $exam));
            $values = ['exam_id' => $examRow->id, 'student_id' => $this->id('students', $n), 'room_id' => $examRow->room_id,
                'verified_by' => $this->id('users', 'teacher1'), 'verification_method' => $method, 'status' => 'VALID',
                'entered_at' => $this->entryTime($examRow)];
            $id = $this->insert('exam_entries', $key, $values);
            // Historical rows cannot be created through today's admission window; explicitly labelled initial snapshots.
            \App\Models\AuditLog::create(['user_id' => $values['verified_by'], 'action' => 'DEMO_ENTRY_SNAPSHOT_CREATED',
                'entity_type' => 'ExamEntry', 'entity_id' => $id, 'new_values' => $values,
                'user_agent' => self::DATASET, 'created_at' => $values['entered_at']]);
        }
    }

    /** Only these temporal fields are managed. All other demo edits survive subsequent runs. */
    private function refreshTimeline(): void
    {
        app(AcademicTermIntegrity::class)->transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(240028, 1)');
            $changes = [];
            foreach ($this->offeringPlan() as $key => [$teacher, $subject, $term]) {
                $offering = DB::table('course_offerings')->where('id', $this->id('course_offerings', $key))->first();
                if ((int) $offering->teacher_id !== $this->id('teachers', $teacher)
                    || (int) $offering->subject_id !== $this->id('subjects', $subject)
                    || (int) $offering->academic_term_id !== $this->id('academic_terms', $term)) {
                    throw new DomainException('Una oferta demo fue reasignada. No se desplazan sus examenes automaticamente.');
                }
            }
            foreach ($this->termPlan() as $key => $plan) {
                $term = AcademicTerm::findOrFail($this->id('academic_terms', $key));
                $data = ['name' => $term->name, 'start_date' => $plan['start_date'], 'end_date' => $plan['end_date'], 'status' => $term->status];
                app(AcademicTermIntegrity::class)->assertCanSave($data, $term->id);
                $old = $term->only(['start_date', 'end_date']);
                $term->fill(array_intersect_key($data, $old));
                if ($term->isDirty()) {
                    $term->save();
                    $changes['academic_terms'][$term->id] = ['old' => $old, 'new' => $term->only(array_keys($old))];
                }
            }
            // Lock every managed exam first: admission and QR generation use the same lock.
            $exams = Exam::whereIn('id', array_values($this->ids['exams']))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $roomIds = $exams->pluck('room_id')->unique()->sort()->values();
            \App\Models\Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($this->examPlan() as $key => [$offering, $room, $start]) {
                $exam = $exams->get($this->id('exams', $key));
                if ((int) $exam->course_offering_id !== $this->id('course_offerings', $offering)
                    || ! in_array((int) $exam->room_id, array_values($this->ids['rooms']), true)) {
                    throw new DomainException('Un examen demo fue vinculado fuera de su escenario. No se desplaza automaticamente.');
                }
                $old = $exam->only(['exam_date', 'start_time']);
                $exam->fill(['exam_date' => $start->toDateString(), 'start_time' => $start->format('H:i:s')]);
                if ($exam->isDirty(['exam_date', 'start_time'])) {
                    if (DB::table('exam_entries')->where('exam_id', $exam->id)
                        ->whereNotIn('id', array_values($this->ids['exam_entries']))->exists()) {
                        throw new DomainException('Hay ingresos posteriores a la seed en un examen demo. Revisa su historial antes de desplazar la ventana; no se alteran esos ingresos.');
                    }
                    // Check target intervals against both real exams and other managed target intervals.
                    $this->assertTargetRoomFree($exam, $exams);
                    app(ExamRevision::class)->saveNext($exam);
                    StudentQrToken::where('exam_id', $exam->id)->where('status', 'ACTIVE')->whereNull('revoked_at')
                        ->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
                    $changes['exams'][$exam->id] = ['old' => $old, 'new' => $exam->only(array_keys($old))];
                }
            }
            foreach ($this->entryPlan() as $key => [$examKey, $n]) {
                $entry = DB::table('exam_entries')->where('id', $this->id('exam_entries', $key))->lockForUpdate()->first();
                $exam = $exams->get($this->id('exams', $examKey));
                if ((int) $entry->exam_id !== $exam->id || (int) $entry->student_id !== $this->id('students', $n)) {
                    throw new DomainException('Un ingreso demo fue reasociado; no se modifica.');
                }
                $at = $this->entryTime($exam);
                if ($entry->status === 'VALID' && $entry->entered_at !== $at) {
                    DB::table('exam_entries')->where('id', $entry->id)->update(['entered_at' => $at, 'updated_at' => now()]);
                    $changes['exam_entries'][$entry->id] = ['old' => $entry->entered_at, 'new' => $at];
                }
            }
            if ($changes !== []) {
                $this->audit('DEMO_TIMELINE_REFRESHED', self::DATASET, null, null, ['changes' => $changes]);
            }
        });
    }

    private function assertTargetRoomFree(Exam $exam, $exams): void
    {
        if (! in_array($exam->status, ExamEntryAvailability::STATES, true)) {
            return;
        }
        if (\App\Models\Room::findOrFail($exam->room_id)->status !== 'ACTIVE') {
            throw new DomainException('Un aula del escenario fue desactivada. No se reserva una nueva ventana sobre ella.');
        }
        $start = ExamEntryAvailability::startsAt($exam);
        $end = ExamEntryAvailability::endsAt($exam);
        if (app(RoomAvailabilityService::class)->overlaps($start, $end)->where('room_id', $exam->room_id)
            ->whereNotIn('id', $exams->keys())->exists()) {
            throw new DomainException('La nueva ventana demo colisiona con otra reserva. No se modifica la reserva existente.');
        }
        foreach ($this->examPlan() as $key => $plan) {
            $other = $exams->get($this->id('exams', $key));
            if ($other->id !== $exam->id && $other->room_id === $exam->room_id && in_array($other->status, ExamEntryAvailability::STATES, true)
                && $plan[2]->lessThan($end) && $plan[2]->addMinutes($other->duration_minutes)->greaterThan($start)) {
                throw new DomainException('Las ventanas destino demo se solapan tras una edicion manual de aula/duracion.');
            }
        }
    }

    private function termPlan(): array
    {
        return [
            'CURRENT' => ['name' => 'FULLQA Periodo actual', 'start_date' => $this->now->subDays(30)->toDateString(),
                'end_date' => $this->now->addDays(30)->toDateString(), 'status' => 'ACTIVE'],
            'HISTORY' => ['name' => 'FULLQA Periodo historico', 'start_date' => $this->now->subDays(100)->toDateString(),
                'end_date' => $this->now->subDays(50)->toDateString(), 'status' => 'INACTIVE'],
        ];
    }

    private function offeringPlan(): array
    {
        return ['MAIN' => [1, 'TIS', 'CURRENT', 'ACTIVE'], 'SECOND' => [1, 'RED', 'CURRENT', 'ACTIVE'],
            'OTHER' => [2, 'BD', 'CURRENT', 'ACTIVE'], 'HISTORY' => [1, 'TIS', 'HISTORY', 'INACTIVE'],
            'OFF' => [2, 'RED', 'CURRENT', 'INACTIVE']];
    }

    private function enrollmentPlan(): array
    {
        return ['MAIN' => range(1, 10), 'SECOND' => [1, 4, 7, 8], 'OTHER' => [11, 12], 'HISTORY' => [1, 2, 3, 4], 'OFF' => [12]];
    }

    private function examPlan(): array
    {
        return [
            'A-INGRESO' => ['MAIN', 'A', $this->now->subMinutes(15), 240, 'IN_PROGRESS', 'partial'],
            'B-PROXIMO' => ['MAIN', 'A', $this->now->addHours(6), 90, 'SCHEDULED', 'partial'],
            'C-FUTURO' => ['MAIN', 'B', $this->now->addDays(3), 90, 'SCHEDULED', 'final'],
            'D-VENCIDO' => ['SECOND', 'B', $this->now->subDays(3), 90, 'SCHEDULED', 'partial'],
            'E-HISTORICO' => ['HISTORY', 'A', $this->now->subDays(75), 90, 'COMPLETED', 'final'],
            'F-CANCELADO' => ['MAIN', 'C', $this->now->addDays(2), 90, 'CANCELLED', 'makeup'],
            'G-OTRO-DOCENTE' => ['OTHER', 'B', $this->now->addHours(8), 90, 'SCHEDULED', 'makeup'],
        ];
    }

    private function entryPlan(): array
    {
        return ['CURRENT' => ['A-INGRESO', 3, 'MANUAL'], 'ENDED' => ['D-VENCIDO', 4, 'MANUAL'], 'HISTORY' => ['E-HISTORICO', 1, 'QR']];
    }

    private function entryTime(Exam $exam): string
    {
        return ExamEntryAvailability::startsAt($exam)->addMinutes(5)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function insert(string $table, string $key, array $values, array $unique = []): int
    {
        $this->vacant($table, array_intersect_key($values, array_flip($unique)));
        $id = DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
        $this->remember($table, $key, $id);

        return $id;
    }

    private function remember(string $table, string|int $key, int $id): void
    {
        $this->ids[$table][$key] = $id;
        $this->pending[$table][$key] = $id;
    }

    private function id(string $table, string|int $key): int
    {
        return $this->ids[$table][$key];
    }

    private function request(?User $actor = null): Request
    {
        $request = Request::create('/demo/fullqa', 'POST', server: ['HTTP_USER_AGENT' => self::DATASET]);
        $request->setUserResolver(fn () => $actor ?? $this->actor);

        return $request;
    }

    private function audit(string $action, string $type, ?int $id, ?array $old = null, ?array $new = null): void
    {
        app(AuditLogService::class)->log($this->request(), $this->actor?->id, $action, $type, $id, $old, $new);
    }
}
