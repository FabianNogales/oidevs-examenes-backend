<?php

namespace Database\Seeders;

use App\Models\PortalServiceCategory;
use Illuminate\Database\Seeder;

/** Explicit fixture for HU44/HU45 integration; never publishes services automatically. */
class PortalServiceDemoCategorySeeder extends Seeder
{
    public function run(): void
    {
        PortalServiceCategory::firstOrCreate(['name' => 'Servicios académicos'], ['is_active' => true]);
    }
}
