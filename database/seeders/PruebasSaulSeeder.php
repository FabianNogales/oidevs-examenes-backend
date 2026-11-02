<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seed local de pruebas: docente pruebasaul@umss.edu.bo con 3 materias,
 * 80 estudiantes y 5 aulas.
 *
 * Uso: php artisan db:seed --class=PruebasSaulSeeder
 */
class PruebasSaulSeeder extends Seeder
{
    private const DOMAIN = 'umss.edu.bo';

    private const TEACHER_EMAIL = 'pruebasaul@umss.edu.bo';

    private const TEACHER_PASSWORD = 'saul1234';

    private const STUDENT_PASSWORD = 'estudiante1234';

    private const TERM_NAME = 'FULLQA Periodo actual';

    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();

            // ---------- Roles ----------
            $roleIds = [];
            foreach ([
                RoleName::ADMINISTRADOR->value => 'Administracion del sistema EIDA.',
                RoleName::DOCENTE->value => 'Gestion docente dentro del sistema EIDA.',
                RoleName::ESTUDIANTE->value => 'Acceso estudiantil al sistema EIDA.',
            ] as $name => $description) {
                $roleIds[$name] = $this->firstOrInsert('roles', ['name' => $name], [
                    'description' => $description, 'status' => 'ACTIVE',
                ]);
            }

            // ---------- Carrera ----------
            $careerId = $this->firstOrInsert('careers', ['code' => 'ING-SIS'], [
                'name' => 'Ingenieria de Sistemas', 'status' => 'ACTIVE',
            ]);

            // ---------- Periodo (misma gestion del seeder FULLQA) ----------
            $termId = $this->firstOrInsert('academic_terms', ['name' => self::TERM_NAME], [
                'start_date' => $now->copy()->subDays(30)->toDateString(),
                'end_date' => $now->copy()->addDays(30)->toDateString(),
                'status' => 'ACTIVE',
            ]);

            // ---------- Aulas ----------
            $rooms = [
                ['PS-101', 'Aula 101', 'Edificio Central', 'Aula pequena para grupos reducidos.', 30, '1'],
                ['PS-202', 'Aula 202', 'Edificio Central', 'Aula mediana.', 45, '2'],
                ['PS-LAB1', 'Laboratorio de Computacion 1', 'Edificio de Tecnologia', 'Laboratorio con equipos de computo.', 25, '1'],
                ['PS-AM', 'Aula Magna', 'Edificio Central', 'Aula grande para examenes masivos.', 80, '3'],
                ['PS-AUD', 'Auditorio Principal', 'Edificio Administrativo', 'Auditorio de gran capacidad.', 120, '0'],
            ];
            foreach ($rooms as [$code, $name, $location, $description, $capacity, $floor]) {
                $this->firstOrInsert('rooms', ['code' => $code], [
                    'name' => $name, 'location' => $location, 'description' => $description,
                    'capacity' => $capacity, 'floor' => $floor, 'status' => 'ACTIVE',
                ]);
            }

            // ---------- Docente ----------
            $teacherUserId = $this->firstOrInsert('users', ['email' => self::TEACHER_EMAIL], [
                'password' => Hash::make(self::TEACHER_PASSWORD),
                'status' => 'ACTIVE',
                'must_change_password' => false,
                'email_verified_at' => $now,
            ]);
            $this->firstOrInsert('role_user', [
                'user_id' => $teacherUserId, 'role_id' => $roleIds[RoleName::DOCENTE->value],
            ], ['assigned_at' => $now, 'status' => 'ACTIVE'], false);

            $teacherId = $this->firstOrInsert('teachers', ['user_id' => $teacherUserId], [
                'institutional_code' => 'pruebasaul-doc',
                'identity_number' => '99000001',
                'first_names' => 'Saul',
                'last_names' => 'Pruebas Docente',
                'status' => 'ACTIVE',
            ]);

            // ---------- Materias y ofertas ----------
            $plan = [
                // code, nombre, total estudiantes, inhabilitados
                ['PS-INTRO', 'Introduccion a la programacion', 50, 0],
                ['PS-EED', 'Elementos y estructura de datos', 80, 12],
                ['PS-BD2', 'Base de datos II', 40, 0],
            ];

            $offerings = [];
            foreach ($plan as [$code, $name, $total, $disabled]) {
                $subjectId = $this->firstOrInsert('subjects', ['code' => $code], [
                    'name' => $name, 'status' => 'ACTIVE',
                ]);
                $this->firstOrInsert('career_subject', [
                    'career_id' => $careerId, 'subject_id' => $subjectId,
                ], [], false);
                $offeringId = $this->firstOrInsert('course_offerings', [
                    'teacher_id' => $teacherId, 'subject_id' => $subjectId, 'academic_term_id' => $termId,
                ], ['status' => 'ACTIVE'], false);
                $offerings[] = [$offeringId, $total, $disabled];
            }

            // ---------- 80 estudiantes ----------
            $firstNames = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elena', 'Felipe', 'Gabriela', 'Hugo', 'Ines', 'Julia',
                'Kevin', 'Lucia', 'Marco', 'Natalia', 'Oscar', 'Paola', 'Rodrigo', 'Sofia', 'Tomas', 'Valeria'];
            $lastNames = ['Mamani', 'Quispe', 'Flores', 'Rojas', 'Vargas', 'Gutierrez', 'Choque', 'Condori',
                'Mendoza', 'Salazar', 'Fernandez', 'Torrez', 'Arce', 'Villca', 'Camacho', 'Rios'];

            $studentIds = [];
            $studentPassword = Hash::make(self::STUDENT_PASSWORD); // un solo hash para acelerar el seed
            for ($n = 1; $n <= 80; $n++) {
                $userId = $this->firstOrInsert('users', ['email' => sprintf('pruebasaul.est%02d@%s', $n, self::DOMAIN)], [
                    'password' => $studentPassword,
                    'status' => 'ACTIVE',
                    'must_change_password' => false,
                    'email_verified_at' => $now,
                ]);
                $this->firstOrInsert('role_user', [
                    'user_id' => $userId, 'role_id' => $roleIds[RoleName::ESTUDIANTE->value],
                ], ['assigned_at' => $now, 'status' => 'ACTIVE'], false);

                $studentIds[$n] = $this->firstOrInsert('students', ['user_id' => $userId], [
                    'sis_code' => (string) (202600000 + $n),
                    'identity_number' => (string) (8800000 + $n),
                    'first_names' => $firstNames[($n - 1) % count($firstNames)],
                    'last_names' => $lastNames[($n - 1) % count($lastNames)].' '.$lastNames[(intdiv($n - 1, 5) + 3) % count($lastNames)],
                    'career_id' => $careerId,
                    'status' => 'ACTIVE',
                ]);
            }

            // ---------- Inscripciones ----------
            foreach ($offerings as [$offeringId, $total, $disabled]) {
                for ($n = 1; $n <= $total; $n++) {
                    // Los ultimos $disabled estudiantes quedan inhabilitados en la lista de la materia.
                    $status = $n > $total - $disabled ? 'INACTIVE' : 'ACTIVE';
                    $this->firstOrInsert('enrollments', [
                        'course_offering_id' => $offeringId, 'student_id' => $studentIds[$n],
                    ], ['status' => $status, 'registered_by' => $teacherUserId], false);
                }
            }
        });

        $this->command?->info('Seed de pruebas listo.');
        $this->command?->line('Docente: '.self::TEACHER_EMAIL.' / '.self::TEACHER_PASSWORD);
        $this->command?->line('Estudiantes: pruebasaul.est01@'.self::DOMAIN.' ... est80 / '.self::STUDENT_PASSWORD);
    }

    /**
     * Devuelve el id del registro que cumple $keys; si no existe lo crea con $values.
     * Es idempotente para poder re-ejecutar el seed sin duplicar.
     */
    private function firstOrInsert(string $table, array $keys, array $values = [], bool $timestamps = true): int
    {
        $existing = DB::table($table)->where($keys)->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $row = $keys + $values;
        if ($timestamps) {
            $row += ['created_at' => now(), 'updated_at' => now()];
        }

        return (int) DB::table($table)->insertGetId($row);
    }
}