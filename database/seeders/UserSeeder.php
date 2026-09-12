<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', RoleName::ADMINISTRADOR->value)->first();
        $docenteRole = Role::where('name', RoleName::DOCENTE->value)->first();
        $estudianteRole = Role::where('name', RoleName::ESTUDIANTE->value)->first();

        $admin = new User();
        $admin->email = 'admin@test.com';
        $admin->password = 'Password1';
        $admin->status = UserStatus::ACTIVE->value;
        $admin->must_change_password = false;
        $admin->save();

        $admin->roles()->attach($adminRole->id, [
            'status' => UserStatus::ACTIVE->value,
            'assigned_at' => now(),
        ]);

        $docente = new User();
        $docente->email = 'docente@test.com';
        $docente->password = 'Password1';
        $docente->status = UserStatus::ACTIVE->value;
        $docente->must_change_password = false;
        $docente->save();

        $docente->roles()->attach($docenteRole->id, [
            'status' => UserStatus::ACTIVE->value,
            'assigned_at' => now(),
        ]);

        $estudiante = new User();
        $estudiante->email = 'estudiante@test.com';
        $estudiante->password = 'Password1';
        $estudiante->status = UserStatus::ACTIVE->value;
        $estudiante->must_change_password = false;
        $estudiante->save();

        $estudiante->roles()->attach($estudianteRole->id, [
            'status' => UserStatus::ACTIVE->value,
            'assigned_at' => now(),
        ]);
    }
}