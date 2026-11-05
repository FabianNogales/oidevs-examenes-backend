<?php

use App\Services\Eligibility\Automation\CriteriaCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_criteria', function (Blueprint $table) {
            // Fixed system criteria exist before any administrator account is created.
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::table('exam_eligibility_configurations', fn (Blueprint $table) => $table->text('manual_review_conditions')->nullable());
        foreach (CriteriaCatalog::LABELS as $code => $name) {
            DB::table('eligibility_criteria')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'description' => null,
                'source' => 'TEAM_PROPOSAL', 'criterion_type' => 'AUTOMATIC',
                'created_by' => null, 'status' => 'ACTIVE', 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve catalog rows, references and history; reverting cannot restore NOT NULL safely.
        Schema::table('exam_eligibility_configurations', fn (Blueprint $table) => $table->dropColumn('manual_review_conditions'));
        Schema::table('eligibility_criteria', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
