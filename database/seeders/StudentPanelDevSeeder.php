<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentPanelDevSeeder extends Seeder
{
    private int $studentCount = 5;

    public function run(): void
    {
        $this->cleanPreviousRun();

        // ---------- 1. Docente "dueño" del curso de prueba ----------
        $teacherUserId = DB::table('users')->insertGetId([
            'email' => 'docente.dev@test.local',
            'password' => Hash::make('password'),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacherId = DB::table('teachers')->insertGetId([
            'user_id' => $teacherUserId,
            'institutional_code' => 'DEV-DOC-01',
            'identity_number' => 'DEV-CI-DOC-01',
            'first_names' => 'Docente',
            'last_names' => 'Dev Prueba',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assignRoleIfExists($teacherUserId, 'DOCENTE');

        // ---------- 2. Catálogos mínimos ----------
        $careerId = DB::table('careers')->insertGetId([
            'code' => 'DEV-SIS',
            'name' => '[DEV-TEST] Ingeniería de Sistemas',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $termId = DB::table('academic_terms')->insertGetId([
            'name' => '[DEV-TEST] Gestión 2026-II',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Materia 1: Base de Datos
        $subjectId1 = DB::table('subjects')->insertGetId([
            'code' => 'DEV-101',
            'name' => '[DEV-TEST] Base de Datos',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Materia 2: Sistemas Operativos
        $subjectId2 = DB::table('subjects')->insertGetId([
            'code' => 'DEV-102',
            'name' => '[DEV-TEST] Sistemas Operativos',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'DEV-AULA-1',
            'name' => 'Aula 617',
            'location' => 'Módulo DEV',
            'status' => 'AVAILABLE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ---------- 3. Ofertas académicas (usando teacher_id) ----------
        $offeringId1 = DB::table('course_offerings')->insertGetId([
            'subject_id' => $subjectId1,
            'academic_term_id' => $termId,
            'teacher_id' => $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $offeringId2 = DB::table('course_offerings')->insertGetId([
            'subject_id' => $subjectId2,
            'academic_term_id' => $termId,
            'teacher_id' => $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Examen 1: Disponible AHORA
        $examId1 = DB::table('exams')->insertGetId([
            'course_offering_id' => $offeringId1,
            'room_id'            => $roomId,
            'name'               => '[DEV-TEST] Examen Parcial de Prueba',
            'exam_date'          => now()->toDateString(),
            'start_time'         => now()->addHour()->format('H:i:s'),
            'duration_minutes'   => 90,
            'rules'              => 'Portar carnet de identidad.',
            'status'             => 'ACTIVE',
            'created_by'         => $teacherUserId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Examen 2: Fuera del rango de 24h
        $examId2 = DB::table('exams')->insertGetId([
            'course_offering_id' => $offeringId2,
            'room_id'            => $roomId,
            'name'               => '[DEV-TEST] Examen Final',
            'exam_date'          => now()->addDays(3)->toDateString(),
            'start_time'         => '10:00:00',
            'duration_minutes'   => 90,
            'rules'              => 'Portar carnet de identidad.',
            'status'             => 'ACTIVE',
            'created_by'         => $teacherUserId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // ---------- 4. Estudiantes de prueba + inscripciones ----------
        for ($i = 1; $i <= $this->studentCount; $i++) {
            $studentUserId = DB::table('users')->insertGetId([
                'email' => "estudiante.dev{$i}@test.local",
                'password' => Hash::make('password'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assignRoleIfExists($studentUserId, 'ESTUDIANTE');

            $studentId = DB::table('students')->insertGetId([
                'user_id' => $studentUserId,
                'sis_code' => "DEV{$i}0000",
                'identity_number' => "DEVCI{$i}00",
                'first_names' => "Estudiante{$i}",
                'last_names' => 'Dev Prueba',
                'career_id' => $careerId,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Inscripción a ambas materias
            DB::table('enrollments')->insert([
                [
                    'course_offering_id' => $offeringId1,
                    'student_id'         => $studentId,
                    'status'             => 'ACTIVE',
                    'registered_by'      => $teacherUserId,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
                [
                    'course_offering_id' => $offeringId2,
                    'student_id'         => $studentId,
                    'status'             => 'ACTIVE',
                    'registered_by'      => $teacherUserId,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]
            ]);

            // Token para el Examen 1
            DB::table('student_qr_tokens')->insert([
                'student_id'   => $studentId,
                'exam_id'      => $examId1,
                'token'        => Str::uuid(),
                'status'       => 'ACTIVE',
                'generated_at' => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $this->command?->info("Seeder ejecutado con éxito: {$this->studentCount} estudiantes inscritos en 2 exámenes de prueba.");
    }

    private function assignRoleIfExists(int $userId, string $roleName): void
    {
        // Buscar el rol o crearlo si no existe para entorno de desarrollo
        $roleId = DB::table('roles')->where('name', $roleName)->value('id');

        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name'        => $roleName,
                'description' => "Rol de {$roleName} para entorno de pruebas",
                'status'      => 'ACTIVE',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        DB::table('role_user')->insertOrIgnore([
            'role_id'     => $roleId,
            'user_id'     => $userId,
            'assigned_at' => now(),
            'status'      => 'ACTIVE',
        ]);
    }

    private function cleanPreviousRun(): void
    {
        DB::transaction(function () {
            // 1. Obtener IDs de Docentes DEV
            $devTeacherIds = DB::table('teachers')
                ->where('institutional_code', 'like', 'DEV-%')
                ->orWhere('identity_number', 'like', 'DEV-%')
                ->pluck('id')
                ->toArray();

            // 2. Obtener IDs de Exámenes DEV
            $devExamIds = DB::table('exams')
                ->where('name', 'like', '[DEV-TEST]%')
                ->pluck('id')
                ->toArray();

            // 3. Obtener IDs de Ofertas DEV (asociadas a exámenes DEV o al docente DEV)
            $devOfferingIds = DB::table('course_offerings')
                ->whereIn('id', function ($query) {
                    $query->select('course_offering_id')
                        ->from('exams')
                        ->where('name', 'like', '[DEV-TEST]%');
                })
                ->orWhereIn('teacher_id', $devTeacherIds)
                ->pluck('id')
                ->toArray();

            // 4. Obtener IDs de Estudiantes DEV (por sis_code, CI, o inscritos en las ofertas DEV)
            $devStudentIds = DB::table('students')
                ->where('sis_code', 'like', 'DEV%')
                ->orWhere('identity_number', 'like', 'DEV%')
                ->orWhereIn('id', function ($query) use ($devOfferingIds) {
                    $query->select('student_id')
                        ->from('enrollments')
                        ->whereIn('course_offering_id', $devOfferingIds);
                })
                ->pluck('id')
                ->toArray();

            // 5. Obtener IDs de Usuarios DEV asociados a estudiantes, docentes o email DEV
            $studentUserIds = DB::table('students')->whereIn('id', $devStudentIds)->pluck('user_id')->toArray();
            $teacherUserIds = DB::table('teachers')->whereIn('id', $devTeacherIds)->pluck('user_id')->toArray();

            $devUserIds = DB::table('users')
                ->where('email', 'like', '%.dev%@test.local')
                ->orWhere('email', 'docente.dev@test.local')
                ->orWhereIn('id', array_merge($studentUserIds, $teacherUserIds))
                ->pluck('id')
                ->unique()
                ->toArray();

            // --- ELIMINACIÓN EN ORDEN DE RESTRICCIONES FK ---

            if (DB::getSchemaBuilder()->hasTable('exam_eligibilities') && !empty($devExamIds)) {
                DB::table('exam_eligibilities')->whereIn('exam_id', $devExamIds)->delete();
            }

            if (!empty($devUserIds)) {
                DB::table('role_user')->whereIn('user_id', $devUserIds)->delete();
            }

            if (!empty($devStudentIds)) {
                DB::table('student_qr_tokens')->whereIn('student_id', $devStudentIds)->delete();
            }

            // Eliminar inscripciones vinculadas por estudiante O por oferta académica DEV
            DB::table('enrollments')
                ->where(function ($query) use ($devStudentIds, $devOfferingIds) {
                    if (!empty($devStudentIds)) {
                        $query->whereIn('student_id', $devStudentIds);
                    }
                    if (!empty($devOfferingIds)) {
                        $query->orWhereIn('course_offering_id', $devOfferingIds);
                    }
                })
                ->delete();

            if (!empty($devExamIds)) {
                DB::table('exams')->whereIn('id', $devExamIds)->delete();
            }

            if (!empty($devOfferingIds)) {
                DB::table('course_offerings')->whereIn('id', $devOfferingIds)->delete();
            }

            if (!empty($devTeacherIds)) {
                DB::table('teachers')->whereIn('id', $devTeacherIds)->delete();
            }

            if (!empty($devStudentIds)) {
                DB::table('students')->whereIn('id', $devStudentIds)->delete();
            }

            if (!empty($devUserIds)) {
                DB::table('users')->whereIn('id', $devUserIds)->delete();
            }

            DB::table('rooms')->where('code', 'like', 'DEV-%')->delete();
            DB::table('subjects')->where('code', 'like', 'DEV-%')->delete();
            DB::table('academic_terms')->where('name', 'like', '[DEV-TEST]%')->delete();
            DB::table('careers')->where('code', 'like', 'DEV-%')->delete();
        });
    }
}