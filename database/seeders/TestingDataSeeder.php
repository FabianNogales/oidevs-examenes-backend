<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestingDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Insertar Usuario Base (ID: 1)
        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id'         => 1,
                'email'      => 'estudiante@test.com',
                'password'   => Hash::make('password123'),
                'status'     => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Insertar Carrera de prueba (ID: 1) para satisfacer career_id
        if (!DB::table('careers')->where('id', 1)->exists()) {
            DB::table('careers')->insert([
                'id'         => 1,
                'name'       => 'Ingeniería de Sistemas',
                'code'       => 'ING-SIS',
                'status'     => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Insertar Estudiante (ID: 1) alineado a tu esquema exacto
        if (!DB::table('students')->where('id', 1)->exists()) {
            DB::table('students')->insert([
                'id'              => 1,
                'user_id'         => 1,
                'sis_code'        => '202600001',
                'identity_number' => '12345678',
                'first_names'     => 'Estudiante',
                'last_names'      => 'Prueba',
                'career_id'       => 1,
                'status'          => 'ACTIVE',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        // 4. Insertar Materia (ID: 1)
        Subject::firstOrCreate(
            ['id' => 1],
            [
                'code'   => 'SIS-301',
                'name'   => 'Sistemas de Información',
                'status' => 'ACTIVE',
            ]
        );
    }
}