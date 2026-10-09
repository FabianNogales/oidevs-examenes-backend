<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
        $table->unique(
            ['teacher_id', 'subject_id', 'academic_term_id'],
            'course_offerings_teacher_subject_term_unique'
        );
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
        $table->dropUnique('course_offerings_teacher_subject_term_unique');
    });
    }
};
