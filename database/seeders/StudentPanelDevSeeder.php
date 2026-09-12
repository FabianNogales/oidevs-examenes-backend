<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentPanelDevSeeder extends Seeder
{
    // Cuántos estudiantes de prueba quieres para tu panel
    private int $studentCount = 5;

    public function run(): void
    {
        $this->cleanPreviousRun();

        // ---------- 1. Docente "dueño" del curso de prueba ----------
        $teacherId = DB::table('users')->insertGetId([
            'email' => 'docente.dev@test.local',
            'password' => Hash::make('password'),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ---------- 2. Catálogos mínimos que exige el esquema ----------
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

        $subjectId = DB::table('subjects')->insertGetId([
            'code' => 'DEV-101',
            'name' => '[DEV-TEST] Base de Datos',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'code' => 'DEV-AULA-1', // <- este campo era el que te faltaba
            'name' => 'Aula 617',
            'location' => 'Módulo DEV',
            'status' => 'AVAILABLE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ---------- 3. Oferta académica + examen ----------
        $offeringId = DB::table('course_offerings')->insertGetId([
            'subject_id' => $subjectId,
            'academic_term_id' => $termId,
            'teacher_user_id' => $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $examId = DB::table('exams')->insertGetId([
            'course_offering_id' => $offeringId,
            'room_id'            => $roomId,
            'name'               => '[DEV-TEST] Examen Parcial de Prueba',
            'exam_date'          => now()->toDateString(),
            'start_time'         => now()->addHours(2)->format('H:i:s'),
            'duration_minutes'   => 90,
            'rules'              => 'Portar carnet de identidad.',
            'status'             => 'ACTIVE', 
            'created_by'         => $teacherId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        // ---------- 4. Estudiantes de prueba + inscripción + QR ----------
        for ($i = 1; $i <= $this->studentCount; $i++) {
            $studentUserId = DB::table('users')->insertGetId([
                'email' => "estudiante.dev{$i}@test.local",
                'password' => Hash::make('password'),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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

            DB::table('enrollments')->insert([
                'course_offering_id' => $offeringId,
                'student_id'         => $studentId,
                'status'             => 'ACTIVE',
                'registered_by'      => $teacherId,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            DB::table('student_qr_tokens')->insert([
                'student_id' => $studentId,
                'exam_id' => $examId,
                'token' => Str::uuid(),
                'status' => 'ACTIVE',
                'generated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command?->info("Listo: {$this->studentCount} estudiantes de prueba con QR generado para el examen #{$examId}.");
    }

    /**
     * Borra SOLO lo que este seeder crea (marcado con 'DEV-TEST' / 'DEV-'),
     * en orden inverso de dependencia, para poder re-correr limpio.
     */
    private function cleanPreviousRun(): void
{
    $devStudentIds = DB::table('students')->where('sis_code', 'like', 'DEV%')->pluck('id');
    $devUserIds = DB::table('students')->where('sis_code', 'like', 'DEV%')->pluck('user_id')
        ->merge(DB::table('users')->where('email', 'like', '%.dev%@test.local')->pluck('id'))
        ->unique();
    $devExamIds = DB::table('exams')->where('name', 'like', '[DEV-TEST]%')->pluck('id');
    $devOfferingIds = DB::table('course_offerings')
        ->whereIn('id', DB::table('exams')->where('name', 'like', '[DEV-TEST]%')->pluck('course_offering_id'))
        ->pluck('id');

    // 1. Borrar dependencias hijas primero
    if (DB::getSchemaBuilder()->hasTable('exam_eligibilities')) {
        DB::table('exam_eligibilities')->whereIn('exam_id', $devExamIds)->delete();
    }
    DB::table('student_qr_tokens')->whereIn('student_id', $devStudentIds)->delete();
    DB::table('enrollments')->whereIn('student_id', $devStudentIds)->delete();

    // 2. Borrar padres
    DB::table('exams')->whereIn('id', $devExamIds)->delete();
    DB::table('course_offerings')->whereIn('id', $devOfferingIds)->delete();
    DB::table('students')->whereIn('id', $devStudentIds)->delete();
    DB::table('users')->whereIn('id', $devUserIds)->delete();
    DB::table('rooms')->where('code', 'like', 'DEV-%')->delete();
    DB::table('subjects')->where('code', 'like', 'DEV-%')->delete();
    DB::table('academic_terms')->where('name', 'like', '[DEV-TEST]%')->delete();
    DB::table('careers')->where('code', 'like', 'DEV-%')->delete();
}
}