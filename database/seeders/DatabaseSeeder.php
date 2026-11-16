<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Llama a tu seeder principal aquí
        $this->call([
            EidaFullDemoSeeder::class,
            // RoleSeeder::class, (agrega otros si los tienes)
        ]);
    }
}