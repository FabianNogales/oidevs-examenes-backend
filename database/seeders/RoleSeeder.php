<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the official application roles.
     */
    public function run(): void
    {
        $roles = [
            RoleName::ADMINISTRADOR->value => 'Administracion del sistema EIDA.',
            RoleName::DOCENTE->value => 'Gestion docente dentro del sistema EIDA.',
            RoleName::ESTUDIANTE->value => 'Acceso estudiantil al sistema EIDA.',
        ];

        foreach ($roles as $name => $description) {
            Role::query()->firstOrCreate(
                ['name' => $name],
                [
                    'description' => $description,
                    'status' => UserStatus::ACTIVE->value,
                ],
            );
        }
    }
}
