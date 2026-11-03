<?php

namespace Database\Seeders;

use App\Models\PortalEventCategory;
use Illuminate\Database\Seeder;

/** Explicit integration fixture; does not change existing categories or create events. */
class PortalCalendarDemoCategorySeeder extends Seeder
{
    public function run(): void
    {
        PortalEventCategory::firstOrCreate(['name' => 'Actividades académicas'], ['is_active' => true]);
    }
}
