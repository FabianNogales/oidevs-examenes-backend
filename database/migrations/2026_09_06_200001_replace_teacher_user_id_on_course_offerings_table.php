<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('course_offerings', function (Blueprint $table) {
                $table->foreignId('teacher_id')->nullable();
            });

            DB::statement(
                'UPDATE course_offerings
                 SET teacher_id = (
                     SELECT teachers.id
                     FROM teachers
                     WHERE teachers.user_id = course_offerings.teacher_user_id
                 )
                 WHERE teacher_id IS NULL'
            );

            if (DB::table('course_offerings')->whereNull('teacher_id')->exists()) {
                throw new RuntimeException(
                    'Existen course_offerings cuyo teacher_user_id no tiene perfil Teacher asociado.'
                );
            }

            $this->setColumnNotNull('course_offerings', 'teacher_id');

            Schema::table('course_offerings', function (Blueprint $table) {
                $table->foreign('teacher_id')->references('id')->on('teachers')->restrictOnDelete();
                $table->index('subject_id');
                $table->index('academic_term_id');
                $table->index('teacher_id');
            });

            Schema::table('course_offerings', function (Blueprint $table) {
                $table->dropForeign(['teacher_user_id']);
                $table->dropColumn('teacher_user_id');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('course_offerings', function (Blueprint $table) {
                $table->foreignId('teacher_user_id')->nullable();
            });

            DB::statement(
                'UPDATE course_offerings
                 SET teacher_user_id = (
                     SELECT teachers.user_id
                     FROM teachers
                     WHERE teachers.id = course_offerings.teacher_id
                 )
                 WHERE teacher_user_id IS NULL'
            );

            if (DB::table('course_offerings')->whereNull('teacher_user_id')->exists()) {
                throw new RuntimeException(
                    'Existen course_offerings cuyo teacher_id no tiene perfil Teacher asociado.'
                );
            }

            $this->setColumnNotNull('course_offerings', 'teacher_user_id');

            Schema::table('course_offerings', function (Blueprint $table) {
                $table->foreign('teacher_user_id')->references('id')->on('users')->restrictOnDelete();
            });

            Schema::table('course_offerings', function (Blueprint $table) {
                $table->dropForeign(['teacher_id']);
                $table->dropIndex(['subject_id']);
                $table->dropIndex(['academic_term_id']);
                $table->dropIndex(['teacher_id']);
                $table->dropColumn('teacher_id');
            });
        });
    }

    private function setColumnNotNull(string $table, string $column): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");

            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column) {
            $table->unsignedBigInteger($column)->nullable(false)->change();
        });
    }
};
