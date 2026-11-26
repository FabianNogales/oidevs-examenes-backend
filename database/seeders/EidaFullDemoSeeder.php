<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\AcademicRecord;
use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Exam;
use App\Models\ExamEligibility;
use App\Models\ExamOfflineDistribution;
use App\Models\Role;
use App\Models\User;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use App\Services\Admin\CustomRoleService;
use App\Services\Admin\StaffAccountService;
use App\Services\Auth\LoginUserResolver;
use App\Services\Collaborators\ExamCollaboratorService;
use App\Services\Discipline\DisciplineService;
use App\Services\Eligibility\Automation\AcademicDataService;
use App\Services\Eligibility\Automation\ConfigurationService;
use App\Services\Eligibility\Automation\EvaluationService;
use App\Services\Eligibility\Automation\GlobalCriteriaCatalog;
use App\Services\Eligibility\ExamEligibilityInitializer;
use App\Services\Eligibility\ExamEligibilityService;
use App\Services\Entries\EntryAnnulmentService;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Entries\QrEntryService;
use App\Services\Exams\ExamRoomAssignmentService;
use App\Services\Exams\ExamSchedulePolicy;
use App\Services\Offline\OfflineAssignmentService;
use App\Services\Offline\OfflinePackageService;
use App\Services\Offline\OfflineSyncService;
use App\Services\Portal\PortalCalendarService;
use App\Services\Portal\PortalInformationService;
use App\Services\Portal\PortalNewsService;
use App\Services\Portal\PortalServiceCatalog;
use App\Services\QrV3\ExamQrV3;
use App\Services\QrV3\QrV3Factory;
use App\Services\Rooms\RoomAvailabilityService;
use App\Services\Students\StudentQrService;
use App\Services\Students\StudentRegistrationService;
use App\Services\Teachers\TeacherService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EidaFullDemoSeeder extends Seeder
{
    public const DATASET = 'EIDA-SPRINT123-v2';

    public const DEMO_PASSWORD = 'EidaDemo2026!';

    private array $ids = [];

    private User $admin;

    private string $passwordHash;

    private CarbonImmutable $now;

    public function run(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new DomainException('EidaFullDemoSeeder requiere PostgreSQL.');
        }
        $this->now = CarbonImmutable::now(ExamEntryAvailability::TIMEZONE)->startOfMinute();
        // Use the backend's existing issuer and keys, including their signature checks.
        app(QrV3Factory::class)->make()->publicSigningMaterial($this->now->subDay()->timestamp, $this->now->addHours(5)->timestamp, true);
        $this->passwordHash = Hash::make(self::DEMO_PASSWORD);
        $this->ids = [];
        app(AcademicTermIntegrity::class)->transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(240028, 1)');
            $existing = AuditLog::where('action', 'DEMO_DATASET_CREATED')->where('entity_type', self::DATASET)->get();
            if ($existing->count() > 1) {
                throw new DomainException('Manifiesto duplicado; requiere revisión manual.');
            }
            if ($manifest = $existing->first()) {
                foreach ($manifest->new_values['records'] as $table => $ids) {
                    if (DB::table($table)->whereIn('id', array_values($ids))->count() !== count($ids)) {
                        throw new DomainException("Faltan registros en {$table}; no se recrean silenciosamente.");
                    }
                }
                $this->ids = $manifest->new_values['records'];
                $this->restoreInstitutionalAccounts();
                $this->command?->info('Demo existente: cuentas institucionales verificadas; se conservan las relaciones, fechas y listas congeladas.');

                return;
            }
            $legacyEmails = [];
            foreach ($this->accountKeys() as $key) {
                $legacyEmails[] = $key.'@eida.test';
                $legacyEmails[] = $key.'@example.com';
            }
            if (User::whereIn(DB::raw('LOWER(email)'), $legacyEmails)->exists()) {
                throw new DomainException('Existen cuentas demo anteriores sin manifiesto de propiedad. Requieren revisión; no se adoptan por correo ni se duplican.');
            }
            $this->foundation();
            $this->academic();
            $this->exams();
            $this->portal();
            $this->assertIntegrity();
            AuditLog::create(['user_id' => $this->admin->id, 'action' => 'DEMO_DATASET_CREATED', 'entity_type' => self::DATASET,
                'new_values' => ['records' => $this->ids, 'executed_at' => $this->now->toISOString(), 'timezone' => ExamEntryAvailability::TIMEZONE],
                'user_agent' => self::DATASET]);
        });
        $this->command?->info('Demo Sprint 1–3 lista. Contraseña de demostración: '.self::DEMO_PASSWORD);
        foreach ($this->ids as $table => $records) {
            $this->command?->line($table.': '.count($records));
        }
        $this->command?->line('Roles base disponibles: '.Role::whereIn('name', array_column(RoleName::cases(), 'value'))->count());
        $this->command?->line('Permisos del catálogo migrado: '.DB::table('permissions')->count());
        foreach (User::whereIn('id', array_values($this->ids['users']))->orderBy('id')->pluck('email') as $email) {
            $this->command?->line($email);
        }
        $exam = Exam::findOrFail($this->id('exams', 'A-INGRESO'));
        $this->command?->line('Ingreso: '.$exam->name.' / '.$exam->exam_date.' '.$exam->start_time.' America/La_Paz; '.(ExamEntryAvailability::reason($exam) ?? 'DISPONIBLE'));
    }

    private function foundation(): void
    {
        foreach (RoleName::cases() as $role) {
            $row = Role::firstOrCreate(['name' => $role->value], ['status' => 'ACTIVE']);
            if ($row->status !== 'ACTIVE') {
                throw new DomainException('Existe un rol base inactivo; la demo no lo modifica.');
            }
        }
        foreach (['SIS' => 'Ingeniería de Sistemas', 'INF' => 'Informática'] as $key => $name) {
            $this->insert('careers', $key, ['code' => 'DEMO-'.$key, 'name' => 'DEMO '.$name, 'status' => 'ACTIVE'], ['code']);
        }
        foreach (['TIS' => 'Taller de Software', 'BD' => 'Bases de Datos', 'RED' => 'Redes'] as $key => $name) {
            $this->insert('subjects', $key, ['code' => 'DEMO-'.$key, 'name' => 'DEMO '.$name, 'status' => 'ACTIVE'], ['code']);
            foreach (['SIS', 'INF'] as $career) {
                $this->insert('career_subject', $career.$key, ['career_id' => $this->id('careers', $career), 'subject_id' => $this->id('subjects', $key)]);
            }
        }
        foreach (['Norte', 'Sur'] as $name) {
            $this->insert('buildings', $name, ['name' => 'DEMO Edificio '.$name, 'is_active' => true], ['name']);
        }
        foreach (['A', 'B', 'C', 'D'] as $i => $key) {
            $this->insert('rooms', $key, ['code' => 'DEMO-'.$key, 'name' => 'DEMO Aula '.$key,
                'building_id' => $this->id('buildings', $i < 2 ? 'Norte' : 'Sur'), 'floor' => (string) ($i % 2 + 1),
                'location' => 'Campus ficticio de demostración', 'capacity' => 30, 'status' => $key === 'D' ? 'INACTIVE' : 'ACTIVE',
                'inactive_until' => $key === 'D' ? $this->now->addDays(2) : null], ['code', 'name']);
        }
        $this->vacant('users', ['email' => 'admin@umss.edu.bo', 'staff_identity_number' => '88000001']);
        $this->admin = User::forceCreate(['email' => 'admin@umss.edu.bo', 'password' => $this->passwordHash,
            'staff_first_names' => 'Adriana', 'staff_last_names' => 'Ficticia Administradora', 'staff_identity_number' => '88000001',
            'status' => 'ACTIVE', 'email_verified_at' => now(), 'must_change_password' => false]);
        $this->remember('users', 'admin', $this->admin->id);
        $this->admin->roles()->attach(Role::where('name', RoleName::ADMINISTRADOR->value)->value('id'),
            ['status' => 'ACTIVE', 'assigned_by' => $this->admin->id, 'assigned_at' => now()]);
        foreach (['docente1' => 'Irene', 'docente2' => 'Tomás', 'docente3' => 'Nora', 'colaborador1' => 'Mario', 'colaborador2' => 'Sara'] as $key => $name) {
            $n = count($this->ids['teachers'] ?? []) + 1;
            $data = ['email' => $key.'@umss.edu.bo', 'institutional_code' => 'DEMO-DOC-'.$n,
                'identity_number' => (string) (88000100 + $n), 'first_names' => $name, 'last_names' => 'Ficticio Docente '.$n];
            $this->vacant('users', ['email' => $data['email']]);
            $this->vacant('teachers', ['institutional_code' => $data['institutional_code'], 'identity_number' => $data['identity_number']]);
            $teacher = app(TeacherService::class)->create($data, $this->admin, $this->request($this->admin));
            $this->ready($teacher->user, $key);
            $this->remember('teachers', $key, $teacher->id);
        }
        $first = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elena', 'Felipe', 'Gabriela', 'Hugo', 'Inés', 'Julia', 'Kevin', 'Lucía', 'Mateo', 'Nadia', 'Óscar', 'Paula', 'René', 'Sofía'];
        $last = ['Alba', 'Borda', 'Caro', 'Doria', 'Escal', 'Faro', 'Galo', 'Heria', 'Iria', 'Jara', 'Koria', 'Lara', 'Mora', 'Nero', 'Oria', 'Pera', 'Quera', 'Roa'];
        foreach ($first as $i => $name) {
            $n = $i + 1;
            $data = ['email' => "estudiante{$n}@umss.edu.bo", 'sis_code' => (string) (880260000 + $n),
                'identity_number' => (string) (88010000 + $n), 'first_names' => $name, 'last_names' => $last[$i].' Ficticio', 'profile_photo' => null];
            $this->vacant('users', ['email' => $data['email']]);
            $this->vacant('students', ['sis_code' => $data['sis_code'], 'identity_number' => $data['identity_number']]);
            $student = app(StudentRegistrationService::class)->create($data, Career::findOrFail($this->id('careers', $n % 2 ? 'SIS' : 'INF')),
                $this->admin, $this->request($this->admin));
            $this->ready($student->user, 'estudiante'.$n);
            if ($n === 17) {
                $student->update(['status' => 'INACTIVE']);
            }
            $this->remember('students', $n, $student->id);
        }
        foreach (['secretaria1' => ['DEMO Secretaría académica', ['rooms.view', 'subjects.view', 'careers.view', 'academic_terms.view', 'course_offerings.view']],
            'secretaria2' => ['DEMO Edición institucional', ['portal.news.update', 'portal.events.update', 'portal.information.sections.update', 'portal.information.faqs.update', 'portal.services.update']]] as $key => [$name, $permissions]) {
            $this->vacant('roles', ['name' => $name]);
            $role = app(CustomRoleService::class)->save(['name' => $name, 'permission_keys' => $permissions], $this->request($this->admin));
            $this->remember('roles', $key, $role->id);
            $ci = $key === 'secretaria1' ? '88000201' : '88000202';
            $this->vacant('users', ['email' => $key.'@umss.edu.bo', 'staff_identity_number' => $ci]);
            $user = app(StaffAccountService::class)->create(['email' => $key.'@umss.edu.bo', 'role_id' => $role->id,
                'first_names' => $key === 'secretaria1' ? 'Vera' : 'Yara', 'last_names' => 'Ficticia Secretaría', 'identity_number' => $ci], $this->request($this->admin));
            $this->ready($user, $key);
        }
        $this->capture('role_user', 'user_id', array_values($this->ids['users']));
    }

    private function academic(): void
    {
        // Reuse a covering institutional period without claiming or modifying it.
        $current = DB::table('academic_terms')->where('status', 'ACTIVE')->whereDate('start_date', '<=', $this->now->subDays(7)->toDateString())
            ->whereDate('end_date', '>=', $this->now->addDays(7)->toDateString())->first();
        if ($current) {
            $termId = $current->id;
        } else {
            $data = ['name' => 'DEMO Periodo Sprint 1–3', 'start_date' => $this->now->subDays(30)->toDateString(),
                'end_date' => $this->now->addDays(60)->toDateString(), 'status' => 'ACTIVE'];
            app(AcademicTermIntegrity::class)->assertCanSave($data);
            $termId = $this->insert('academic_terms', 'CURRENT', $data, ['name']);
        }
        $earliest = CarbonImmutable::parse(DB::table('academic_terms')->min('start_date'), ExamEntryAvailability::TIMEZONE);
        $history = ['name' => 'DEMO Periodo histórico', 'start_date' => $earliest->subDays(91)->toDateString(),
            'end_date' => $earliest->subDay()->toDateString(), 'status' => 'INACTIVE'];
        app(AcademicTermIntegrity::class)->assertCanSave($history);
        $historyId = $this->insert('academic_terms', 'HISTORY', $history, ['name']);
        foreach (['MAIN' => ['docente1', 'TIS', range(1, 18)], 'SECOND' => ['docente2', 'BD', range(1, 8)],
            'OTHER' => ['docente3', 'RED', range(9, 16)], 'HISTORY' => ['docente2', 'BD', range(1, 8)]] as $key => [$teacher, $subject, $students]) {
            $offering = $this->insert('course_offerings', $key, ['teacher_id' => $this->id('teachers', $teacher),
                'subject_id' => $this->id('subjects', $subject), 'academic_term_id' => $key === 'HISTORY' ? $historyId : $termId,
                'status' => $key === 'HISTORY' ? 'INACTIVE' : 'ACTIVE']);
            foreach ($students as $n) {
                $this->insert('enrollments', $key.'-'.$n, ['course_offering_id' => $offering, 'student_id' => $this->id('students', $n),
                    'registered_by' => $this->id('users', $teacher), 'status' => $n === 18 ? 'INACTIVE' : 'ACTIVE']);
                if ($n === 4) {
                    continue;
                }
                $score = in_array($n, [2, 6], true) ? 20 : 70 + $n % 20;
                $record = app(AcademicDataService::class)->validateRecord(['assessments' => [
                    'FIRST_PARTIAL' => ['was_taken' => true, 'score' => $score], 'SECOND_PARTIAL' => ['was_taken' => true, 'score' => $score]],
                    'attended_classes' => in_array($n, [2, 3], true) ? 10 : 18, 'total_classes' => 20]);
                $row = AcademicRecord::create($record + ['course_offering_id' => $offering, 'student_id' => $this->id('students', $n),
                    'updated_by' => $this->id('users', $teacher), 'revision' => 1]);
                $this->remember('academic_records', $key.'-'.$n, $row->id);
            }
        }
    }

    private function exams(): void
    {
        $slots = collect(ExamSchedulePolicy::ALLOWED_START_TIMES)->map(fn ($time) => $this->now->setTimeFromTimeString($time));
        $live = $slots->filter(fn ($at) => $at->lessThanOrEqualTo($this->now))->last();
        if (! $live || $this->now->format('H:i:s') >= ExamSchedulePolicy::DAY_END_TIME) {
            $live = $this->now->format('H:i:s') < '06:45:00' ? $this->now->setTime(6, 45) : $this->now->addDay()->setTime(6, 45);
        }
        $duration = match ($live->format('H:i:s')) {
            '20:15:00' => 90, '18:45:00' => 180, default => 270
        };
        $plans = [
            'A-INGRESO' => ['MAIN', 'A', $live, $duration, $live->isFuture() ? 'SCHEDULED' : 'IN_PROGRESS', 'partial'],
            'B-AUTOMATICO' => ['MAIN', 'B', $this->now->addDay()->setTime(8, 15), 90, 'SCHEDULED', 'partial'],
            'C-FINAL' => ['MAIN', 'B', $this->now->addDays(3)->setTime(9, 45), 180, 'SCHEDULED', 'final'],
            'D-FINALIZADO' => ['MAIN', 'A', $this->now->subDays(3)->setTime(8, 15), 90, 'COMPLETED', 'partial'],
            'E-HISTORICO' => ['HISTORY', 'B', CarbonImmutable::parse(DB::table('academic_terms')->where('id', $this->id('academic_terms', 'HISTORY'))->value('end_date'), ExamEntryAvailability::TIMEZONE)->subDays(5)->setTime(9, 45), 90, 'COMPLETED', 'final'],
            'F-CANCELADO' => ['MAIN', 'C', $this->now->addDays(2)->setTime(12, 45), 90, 'CANCELLED', 'partial'],
            'G-OTRO-DOCENTE' => ['OTHER', 'C', $this->now->addDay()->setTime(14, 15), 90, 'SCHEDULED', 'partial'],
            'H-LISTAS-EXCLUSIVAS' => ['MAIN', 'A', $this->now->addDays(2)->setTime(8, 15), 90, 'SCHEDULED', 'makeup'],
            'I-PENDIENTES' => ['MAIN', 'C', $this->now->addDays(4)->setTime(8, 15), 90, 'SCHEDULED', 'final'],
        ];
        foreach ($plans as $key => [$offering, $room, $at, $minutes, $status, $type]) {
            if (! app(ExamSchedulePolicy::class)->fitsWithinInstitutionalDay($at->format('H:i:s'), $minutes)) {
                throw new DomainException('Horario demo fuera de los bloques institucionales.');
            }
            $teacher = $this->user(match ($offering) {
                'SECOND', 'HISTORY' => 'docente2', 'OTHER' => 'docente3', default => 'docente1'
            });
            app(RoomAvailabilityService::class)->ensureReservable($this->id('rooms', $room), $at->toDateString(), $at->format('H:i:s'), $minutes);
            $exam = Exam::create(['course_offering_id' => $this->id('course_offerings', $offering), 'room_id' => $this->id('rooms', $room),
                'name' => 'DEMO '.$key, 'exam_date' => $at->toDateString(), 'start_time' => $at->format('H:i:s'),
                'duration_minutes' => $minutes, 'evaluation_type' => $type, 'status' => $status,
                'rules' => 'Datos ficticios. Presentar CI y QR vigente; respetar la lista exclusiva.', 'created_by' => $teacher->id]);
            $this->remember('exams', $key, $exam->id);
            app(ExamRoomAssignmentService::class)->syncExamRooms($exam->id, [$exam->room_id]);
            if ($key !== 'I-PENDIENTES') {
                app(ExamEligibilityInitializer::class)->forExam($exam, $teacher->id);
            }
            if (in_array($key, ['A-INGRESO', 'B-AUTOMATICO', 'H-LISTAS-EXCLUSIVAS'], true)) {
                ExamEligibility::where('exam_id', $exam->id)->where('student_id', $this->id('students', 4))->delete();
                foreach ([3 => 'ELIGIBLE', 5 => 'INELIGIBLE'] as $n => $decision) {
                    app(ExamEligibilityService::class)->save(ExamEligibility::where('exam_id', $exam->id)->where('student_id', $this->id('students', $n))->firstOrFail(),
                        ['status' => $decision, 'reason_code' => 'INSTITUTIONAL_REQUIREMENT_PENDING', 'observations' => 'Documento ficticio pendiente.'], $this->request($teacher));
                }
                $configuration = app(ConfigurationService::class)->save($this->request($teacher, ['revision' => 0,
                    'catalog_revision' => app(GlobalCriteriaCatalog::class)->revision(), 'criteria' => [
                        ['code' => 'MIN_ATTENDANCE_PERCENTAGE', 'parameters' => ['minimum_percentage' => 75]],
                        ['code' => 'MIN_ASSESSMENT_SCORE', 'parameters' => ['assessment_key' => 'FIRST_PARTIAL', 'minimum_score' => 51]],
                    ]]), $exam->id);
                $evaluation = app(EvaluationService::class)->preview($this->request($teacher, ['configuration_revision' => $configuration['revision'],
                    'catalog_revision' => $configuration['catalog_revision']]), $exam->id);
                app(EvaluationService::class)->confirm($this->request($teacher), $exam->id, $evaluation->preview_id);
            }
            if (in_array($key, ['A-INGRESO', 'H-LISTAS-EXCLUSIVAS'], true)) {
                foreach (['colaborador1', 'colaborador2'] as $operator) {
                    app(ExamCollaboratorService::class)->assignCollaborator($exam->id, $this->id('users', $operator), $this->request($teacher));
                }
            }
            if ($key === 'B-AUTOMATICO') {
                app(ExamCollaboratorService::class)->assignCollaborator($exam->id, $this->id('users', 'colaborador2'), $this->request($teacher));
                app(ExamCollaboratorService::class)->revokeCollaborator($exam->id, $this->id('users', 'colaborador2'), $this->request($teacher));
            }
            if ($status === 'COMPLETED') {
                // Real services create historical evidence at its original time, then close the exam.
                $exam->update(['status' => 'IN_PROGRESS']);
                $this->at($at->addMinutes(10), fn () => $this->activity($exam, $teacher));
                $exam->update(['status' => 'COMPLETED']);
            } elseif ($key === 'A-INGRESO' && ExamEntryAvailability::reason($exam) === null) {
                $this->activity($exam, $teacher);
            }
            if (in_array($key, ['A-INGRESO', 'H-LISTAS-EXCLUSIVAS'], true)) {
                app(OfflineAssignmentService::class)->prepare($exam->id, $this->request($teacher));
            }
        }
        foreach (['exam_rooms', 'exam_eligibilities', 'exam_eligibility_criteria', 'exam_eligibility_configurations', 'eligibility_evaluations',
            'student_criterion_results', 'exam_collaborators', 'exam_entries', 'student_qr_tokens', 'special_cases', 'infractions', 'discipline_cases',
            'expulsions', 'exam_offline_distributions', 'exam_offline_members', 'exam_offline_sync_events'] as $table) {
            $this->capture($table, 'exam_id', array_values($this->ids['exams']));
        }
        $this->capture('exam_offline_lists', 'distribution_id', array_values($this->ids['exam_offline_distributions']));
        $this->capture('exam_offline_packages', 'list_id', array_values($this->ids['exam_offline_lists']));
    }

    private function activity(Exam $exam, User $teacher): void
    {
        $entries = app(QrEntryService::class);
        $params = ['room_id' => $exam->room_id];
        $qr = app(StudentQrService::class)->getOrGenerateForExam($this->id('students', 1), $exam->id, 3);
        app(QrV3Factory::class)->makeVerifier()->verify($qr['token'], $this->id('students', 1), $exam->id, now()->timestamp);
        $this->expectResult($entries->process($exam->id, $teacher, $params + ['qr_token' => $qr['token']]), 'AUTHORIZED');
        foreach ([7, 8] as $n) {
            $response = $entries->processManual($exam->id, $this->id('students', $n), $teacher, $params);
            $this->expectResult($response, 'AUTHORIZED');
            if ($n === 8) {
                app(EntryAnnulmentService::class)->annul($exam->id, $response->getData(true)['data']['entry']['id'],
                    'DEMO: corrección del registro; conserva historial.', $this->request($teacher));
            }
        }
        $this->expectResult($entries->process($exam->id, $teacher, $params + ['qr_token' => $qr['token']]), 'ALREADY_REGISTERED');
        app(DisciplineService::class)->create($exam->id, ['kind' => 'INCIDENT', 'type' => 'IDENTIFICATION',
            'description' => 'DEMO: documento deteriorado, identidad pendiente de contraste.', 'student_id' => $this->id('students', 4),
            'decision' => 'RECORD', 'request_key' => (string) Str::uuid()], $this->request($teacher));
        app(DisciplineService::class)->create($exam->id, ['kind' => 'INCIDENT', 'type' => 'ENVIRONMENT',
            'description' => 'DEMO: interrupción breve de iluminación.', 'student_id' => null,
            'decision' => 'RECORD', 'request_key' => (string) Str::uuid()], $this->request($teacher));
        app(DisciplineService::class)->create($exam->id, ['kind' => 'INFRACTION', 'type' => 'COPYING',
            'description' => 'DEMO: uso de material no permitido constatado por el responsable.', 'student_id' => $this->id('students', 7),
            'observations' => 'Evidencia enteramente ficticia.', 'decision' => 'EXPEL', 'reason' => 'DEMO: decisión justificada del responsable.',
            'request_key' => (string) Str::uuid()], $this->request($teacher));
        app(OfflineAssignmentService::class)->prepare($exam->id, $this->request($teacher));
        $hasCollaborator = DB::table('exam_collaborators')->where('exam_id', $exam->id)->where('user_id', $this->id('users', 'colaborador2'))->where('status', 'ACTIVE')->exists();
        $operator = $hasCollaborator ? $this->user('colaborador2') : $teacher;
        $package = app(OfflinePackageService::class)->download($exam->id, $this->request($operator));
        $student = collect($package['students'])->first(fn ($row) => $row['eligibility_status'] === 'ELIGIBLE' && $row['entry_status'] === 'PENDING' && $row['student_status'] === 'ACTIVE');
        if ($student) {
            $result = app(OfflineSyncService::class)->sync($exam->id, $package + ['events' => [[
                'event_id' => (string) Str::uuid(), 'student_id' => $student['student_id'], 'method' => 'MANUAL', 'captured_at' => now()->toISOString(),
            ]]], $this->request($operator));
            if (($result['results'][0]['status'] ?? null) !== 'ACCEPTED') {
                throw new DomainException('La sincronización demo no generó un resultado.');
            }
            $rejected = app(OfflineSyncService::class)->sync($exam->id, $package + ['events' => [[
                'event_id' => (string) Str::uuid(), 'student_id' => $student['student_id'], 'method' => 'MANUAL', 'captured_at' => now()->toISOString(),
            ]], 'protocol_version' => 1], $this->request($operator));
            if (($rejected['results'][0]['status'] ?? null) !== 'DUPLICATE') {
                throw new DomainException('El ingreso sincronizado no conservó su unicidad.');
            }
            $negative = app(OfflineSyncService::class)->sync($exam->id, array_replace($package, ['room_id' => $this->id('rooms', 'D'), 'events' => [[
                'event_id' => (string) Str::uuid(), 'student_id' => $student['student_id'], 'method' => 'MANUAL', 'captured_at' => now()->toISOString(),
            ]]]), $this->request($operator));
            if (($negative['results'][0]['status'] ?? null) !== 'REJECTED') {
                throw new DomainException('No se rechazó el ambiente incorrecto.');
            }
        }
        if (str_contains($exam->name, 'D-FINALIZADO')) {
            $target = Exam::findOrFail($this->id('exams', 'A-INGRESO'));
            $this->expectResult($entries->process($target->id, $teacher, ['room_id' => $target->room_id, 'qr_token' => $qr['token']]), 'WRONG_EXAM');
        }
        if ($exam->course_offering_id === $this->id('course_offerings', 'MAIN') && str_contains($exam->name, 'A-INGRESO')) {
            foreach ([2, 4] as $n) {
                $this->expectResult($entries->processManual($exam->id, $this->id('students', $n), $teacher, $params), 'DENIED');
            }
        }
    }

    private function portal(): void
    {
        $request = $this->request($this->admin);
        $category = DB::table('portal_event_categories')->where('is_active', true)->orderBy('id')->value('id');
        if (! $category) {
            throw new DomainException('Se requiere una categoría activa de calendario.');
        }
        foreach (['PUBLIC', 'PRIVATE', 'PUBLIC'] as $i => $visibility) {
            $event = app(PortalCalendarService::class)->save(['category_id' => $category, 'title' => 'DEMO '.['Jornada de exámenes', 'Coordinación docente', 'Taller de preparación'][$i],
                'description' => 'Actividad ficticia para presentar el calendario institucional.', 'visibility' => $visibility,
                'starts_at' => $this->now->addDays($i + 1)->setTime(10, 0)->toISOString(), 'ends_at' => $this->now->addDays($i + 1)->setTime(11, 0)->toISOString(),
                'location' => 'DEMO Campus', 'all_day' => false], $request);
            if ($i < 2) {
                app(PortalCalendarService::class)->changeStatus($event->id, ['revision' => $event->revision, 'status' => 'PUBLISHED'], $request);
            }
            $this->remember('portal_events', $i, $event->id);
        }
        foreach (['Apertura de inscripciones', 'Guía de ingreso con QR', 'Borrador de comunicado'] as $i => $title) {
            $news = app(PortalNewsService::class)->save(['title' => 'DEMO '.$title, 'summary' => 'Información ficticia para la demostración de EIDA.',
                'content' => 'Consulta tus exámenes, revisa la habilitación y presenta tu identificación. Este contenido no constituye una comunicación institucional real.'], $request);
            if ($i < 2) {
                app(PortalNewsService::class)->changeStatus($news->id, ['revision' => $news->revision, 'status' => 'PUBLISHED'], $request);
            }
            $this->remember('portal_news', $i, $news->id);
        }
        foreach (['SECTION' => ['title' => 'DEMO Orientación de acceso', 'description' => 'Revisa tu habilitación y descarga el QR vigente. Las fotografías de estas cuentas están ausentes.', 'links' => [], 'sort_order' => 90],
            'FAQ' => ['question' => 'DEMO: ¿cómo preparar una lista offline?', 'answer' => 'El responsable distribuye las listas; cada colaborador descarga su lista en la APK antes de desconectarse.', 'sort_order' => 90]] as $kind => $data) {
            $content = app(PortalInformationService::class)->save($kind, $data, $request);
            app(PortalInformationService::class)->publish($kind, $content->id, $content->revision, true, $request);
            $this->remember('portal_information_contents', $kind, $content->id);
        }
        $domain = config('portal_services.allowed_domains')[0] ?? null;
        if (! $domain) {
            throw new DomainException('Configura EIDA_SERVICE_ALLOWED_DOMAINS para los enlaces institucionales.');
        }
        foreach (['ACTIVE', 'INACTIVE'] as $i => $status) {
            $service = app(PortalServiceCatalog::class)->save(['name' => 'DEMO '.($i ? 'Servicio en preparación' : 'Portal institucional'),
                'description' => 'Enlace de demostración a un dominio institucional permitido.', 'icon' => 'academic',
                'url' => 'https://'.$domain.'/', 'display_order' => 90 + $i], $request);
            if ($status === 'ACTIVE') {
                app(PortalServiceCatalog::class)->changeStatus($service->id, ['revision' => $service->revision, 'status' => $status], $request);
            }
            $this->remember('portal_services', $i, $service->id);
        }
    }

    private function assertIntegrity(): void
    {
        $exam = Exam::findOrFail($this->id('exams', 'H-LISTAS-EXCLUSIVAS'));
        $distribution = ExamOfflineDistribution::where('exam_id', $exam->id)->firstOrFail();
        if (app(OfflineAssignmentService::class)->isStale($exam, $distribution)
            || DB::table('exam_offline_members')->where('exam_id', $exam->id)->count() !== 17
            || ! (app(ExamQrV3::class)->manifest(Exam::findOrFail($this->id('exams', 'A-INGRESO')))['available'] ?? false)) {
            throw new DomainException('Falló integridad de listas o disponibilidad del QR v3.');
        }
        foreach ($this->ids['users'] as $id) {
            $user = User::findOrFail($id);
            if (! app(LoginUserResolver::class)->isValidEmail($user->email) || $user->must_change_password || $user->status !== 'ACTIVE') {
                throw new DomainException('Cuenta demo incompatible con el inicio de sesión.');
            }
        }
    }

    private function ready(User $user, string $key): void
    {
        $user->forceFill(['password' => $this->passwordHash, 'email_verified_at' => now(), 'must_change_password' => false,
            'active_session_id' => null, 'profile_photo' => null])->save();
        $this->remember('users', $key, $user->id);
    }

    private function restoreInstitutionalAccounts(): void
    {
        $keys = $this->accountKeys();
        if (count($this->ids['users'] ?? []) !== count($keys) || array_diff($keys, array_keys($this->ids['users']))) {
            throw new DomainException('El manifiesto no identifica las 26 cuentas previstas; no se adoptan usuarios por correo.');
        }
        $users = User::whereIn('id', array_values($this->ids['users']))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        // Preflight every target before changing any identity. A matching institutional address is never ownership.
        foreach ($keys as $key) {
            $user = $users->get($this->id('users', $key));
            $email = $key.'@umss.edu.bo';
            if (! $user || ! in_array(strtolower($user->email), [$email, $key.'@eida.test', $key.'@example.com'], true)) {
                throw new DomainException('La identidad demo '.$key.' fue modificada; requiere revisión manual.');
            }
            if (User::whereRaw('LOWER(email) = ?', [$email])->whereKeyNot($user->id)->exists()) {
                throw new DomainException('El correo '.$email.' pertenece a otra cuenta. No se sobrescribe ni se fusiona.');
            }
            if (str_starts_with($key, 'estudiante')) {
                $n = (int) substr($key, strlen('estudiante'));
                $validProfile = DB::table('students')->where('id', $this->id('students', $n))->where('user_id', $user->id)->exists();
                $role = RoleName::ESTUDIANTE->value;
            } elseif (str_starts_with($key, 'docente') || str_starts_with($key, 'colaborador')) {
                $validProfile = DB::table('teachers')->where('id', $this->id('teachers', $key))->where('user_id', $user->id)->exists();
                $role = RoleName::DOCENTE->value;
            } else {
                $validProfile = true;
                $role = $key === 'admin' ? RoleName::ADMINISTRADOR->value : Role::findOrFail($this->id('roles', $key))->name;
            }
            if (! $validProfile || ! $user->hasRole($role)) {
                throw new DomainException('El perfil o rol de '.$key.' ya no coincide con la demo; no se reasocian registros ajenos.');
            }
        }
        foreach ($keys as $key) {
            $user = $users[$this->id('users', $key)];
            $email = $key.'@umss.edu.bo';
            $resetPassword = ! Hash::check(self::DEMO_PASSWORD, $user->password);
            if ($user->email === $email && $user->status === 'ACTIVE' && ! $user->must_change_password
                && $user->email_verified_at && ! $resetPassword) {
                continue;
            }
            $old = $user->only(['email', 'status', 'must_change_password']);
            $values = ['email' => $email, 'status' => 'ACTIVE', 'email_verified_at' => $user->email_verified_at ?? now(),
                'must_change_password' => false, 'remember_token' => null, 'active_session_id' => Str::random(40)];
            if ($resetPassword) {
                $values['password'] = $this->passwordHash;
            }
            $user->forceFill($values)->save();
            $user->tokens()->delete();
            AuditLog::create(['user_id' => $this->id('users', 'admin'), 'action' => 'DEMO_ACCOUNT_ACCESS_RESTORED',
                'entity_type' => 'User', 'entity_id' => $user->id, 'old_values' => $old,
                'new_values' => $user->only(['email', 'status', 'must_change_password']) + ['dataset' => self::DATASET, 'password_reset' => $resetPassword],
                'user_agent' => self::DATASET]);
        }
    }

    private function accountKeys(): array
    {
        return ['admin', 'docente1', 'docente2', 'docente3', 'colaborador1', 'colaborador2',
            ...array_map(fn (int $n) => 'estudiante'.$n, range(1, 18)), 'secretaria1', 'secretaria2'];
    }

    private function at(CarbonImmutable $time, callable $callback): void
    {
        $old = Carbon::getTestNow();
        $oldImmutable = CarbonImmutable::getTestNow();
        try {
            Carbon::setTestNow($time);
            CarbonImmutable::setTestNow($time);
            $callback();
        } finally {
            Carbon::setTestNow($old);
            CarbonImmutable::setTestNow($oldImmutable);
        }
    }

    private function expectResult(JsonResponse $response, string $expected): void
    {
        if (($response->getData(true)['result'] ?? null) !== $expected) {
            throw new DomainException('Resultado inesperado al crear el escenario de ingreso: '.$expected);
        }
    }

    private function capture(string $table, string $column, array $ids): void
    {
        foreach (DB::table($table)->whereIn($column, $ids)->pluck('id') as $id) {
            $this->remember($table, (string) $id, $id);
        }
    }

    private function insert(string $table, string $key, array $values, array $unique = []): int
    {
        $this->vacant($table, array_intersect_key($values, array_flip($unique)));
        $id = DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
        $this->remember($table, $key, $id);

        return $id;
    }

    private function vacant(string $table, array $values): void
    {
        foreach ($values as $field => $value) {
            $query = DB::table($table);
            $exists = $field === 'email' ? $query->whereRaw('LOWER(email) = ?', [strtolower($value)])->exists() : $query->where($field, $value)->exists();
            if ($exists) {
                throw new DomainException("Colisión en {$table}.{$field}; el registro existente no se adopta ni modifica.");
            }
        }
    }

    private function remember(string $table, string|int $key, int|string $id): void
    {
        $this->ids[$table][$key] = $id;
    }

    private function id(string $table, string|int $key): int
    {
        return (int) $this->ids[$table][$key];
    }

    private function user(string $key): User
    {
        return User::findOrFail($this->id('users', $key));
    }

    private function request(User $actor, array $data = []): Request
    {
        $request = Request::create('/demo/sprint123', 'POST', $data, server: ['HTTP_USER_AGENT' => self::DATASET]);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }
}
