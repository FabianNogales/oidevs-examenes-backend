<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropForeign(['teacher_user_id']);
            $table->dropColumn('teacher_user_id');
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->index('subject_id');
            $table->index('academic_term_id');
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropIndex(['subject_id']);
            $table->dropIndex(['academic_term_id']);
            $table->dropIndex(['teacher_id']);
            $table->dropColumn('teacher_id');
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->foreignId('teacher_user_id')->constrained('users')->restrictOnDelete();
        });
    }
};
