<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('eligibility_policies')->insert([
            'family' => '86a203b1-1222-4333-8444-000000000034', 'version' => 1,
            'name' => 'Propuesta inicial del equipo', 'status' => 'ACTIVE', 'revision' => 1,
            'definition' => json_encode(['origin' => 'TEAM_PROPOSAL', 'combination' => 'ALL',
                'allowed_criteria' => ['PREVIOUS_ASSESSMENTS_TAKEN', 'MIN_ATTENDANCE_PERCENTAGE', 'MIN_ASSESSMENT_SCORE', 'MIN_ACCUMULATED_SCORE', 'MIN_ASSESSMENT_AVERAGE', 'SECOND_INSTANCE_PARTIAL_AVERAGE'],
                'second_instance' => ['evaluation_type' => 'makeup', 'operator' => 'GT', 'threshold' => 25]], JSON_THROW_ON_ERROR),
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The schema rollback removes policies after their dependent configurations/history.
    }
};
