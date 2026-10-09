<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('exam_collaborators')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('students')
                ->whereColumn('students.id', 'exam_collaborators.student_id')->whereNotNull('students.user_id');
        })->exists()) {
            throw new RuntimeException('Existen colaboradores sin usuario asociado; corregirlos antes de migrar.');
        }

        Schema::table('exam_collaborators', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::statement('UPDATE exam_collaborators SET user_id = (SELECT user_id FROM students WHERE students.id = exam_collaborators.student_id)');
        Schema::table('exam_collaborators', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unique(['exam_id', 'user_id']);
            $table->dropForeign(['student_id']);
            $table->dropUnique(['exam_id', 'student_id']);
            $table->dropColumn('student_id');
        });
    }

    public function down(): void
    {
        if (DB::table('exam_collaborators')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('students')
                ->whereColumn('students.user_id', 'exam_collaborators.user_id');
        })->exists()) {
            throw new RuntimeException('No se puede revertir: existen colaboradores sin perfil de estudiante.');
        }

        Schema::table('exam_collaborators', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::statement('UPDATE exam_collaborators SET student_id = (SELECT id FROM students WHERE students.user_id = exam_collaborators.user_id)');
        Schema::table('exam_collaborators', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
            $table->unique(['exam_id', 'student_id']);
            $table->dropForeign(['user_id']);
            $table->dropUnique(['exam_id', 'user_id']);
            $table->dropColumn('user_id');
        });
    }
};
