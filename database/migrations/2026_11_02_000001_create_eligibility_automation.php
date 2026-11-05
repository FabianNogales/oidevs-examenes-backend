<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eligibility_criteria', fn (Blueprint $t) => $t->string('code')->nullable()->unique());
        Schema::create('eligibility_policies', function (Blueprint $t) {
            $t->id();
            $t->uuid('family');
            $t->unsignedInteger('version')->default(1);
            $t->string('name');
            $t->string('status')->default('DRAFT');
            $t->json('definition');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('activated_at')->nullable();
            $t->timestampsTz();
            $t->unique(['family', 'version']);
        });
        Schema::create('exam_eligibility_configurations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('eligibility_policy_id')->constrained()->restrictOnDelete();
            $t->string('evaluation_type');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('academic_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->json('assessments');
            $t->unsignedInteger('attended_classes')->nullable();
            $t->unsignedInteger('total_classes')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['course_offering_id', 'student_id']);
        });
        Schema::create('academic_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->json('preview');
            $t->json('result')->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('eligibility_evaluations', function (Blueprint $t) {
            $t->id();
            $t->uuid('preview_id')->unique();
            $t->foreignId('exam_id')->constrained()->restrictOnDelete();
            $t->foreignId('eligibility_policy_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->json('snapshot');
            $t->json('results');
            $t->json('confirmation')->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampsTz();
        });
        Schema::table('exam_eligibilities', function (Blueprint $t) {
            $t->string('decision_source')->default('LEGACY');
        });
        if (DB::getDriverName() === 'sqlite') {
            // SQLite's generic FK rebuild loses HU12's existing CHECK(revision > 0).
            DB::statement('ALTER TABLE exam_eligibilities ADD COLUMN automation_evaluation_id INTEGER NULL REFERENCES eligibility_evaluations(id) ON DELETE RESTRICT');
        } else {
            Schema::table('exam_eligibilities', fn (Blueprint $t) => $t->foreignId('automation_evaluation_id')->nullable()->constrained('eligibility_evaluations')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE exam_eligibilities DROP COLUMN automation_evaluation_id');
            DB::statement('ALTER TABLE exam_eligibilities DROP COLUMN decision_source');
        } else {
            Schema::table('exam_eligibilities', function (Blueprint $t) {
                $t->dropConstrainedForeignId('automation_evaluation_id');
                $t->dropColumn('decision_source');
            });
        }
        foreach (['eligibility_evaluations', 'academic_imports', 'academic_records', 'exam_eligibility_configurations', 'eligibility_policies'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('eligibility_criteria', fn (Blueprint $t) => $t->dropColumn('code'));
    }
};
