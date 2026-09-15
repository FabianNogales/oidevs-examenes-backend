<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CourseOfferingTeacherMigrationTest extends TestCase
{
    public function test_clean_database_finishes_with_teacher_id_fk_and_without_teacher_user_id(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertTrue(Schema::hasColumn('course_offerings', 'teacher_id'));
        $this->assertFalse(Schema::hasColumn('course_offerings', 'teacher_user_id'));
        $this->assertColumnNotNullable('course_offerings', 'teacher_id');
        $this->assertForeignKey('course_offerings', 'teacher_id', 'teachers', 'id');
    }

    public function test_existing_course_offerings_are_backfilled_for_same_and_multiple_teachers(): void
    {
        $this->prepareDatabaseBeforeTeacherReplacementMigration();

        $this->insertTeacherProfile(3, 10);
        $this->insertTeacherProfile(8, 20);
        $this->insertCourseOffering(1, 10);
        $this->insertCourseOffering(2, 10);
        $this->insertCourseOffering(3, 20);

        $this->migration()->up();

        $this->assertSame(3, DB::table('course_offerings')->where('id', 1)->value('teacher_id'));
        $this->assertSame(3, DB::table('course_offerings')->where('id', 2)->value('teacher_id'));
        $this->assertSame(8, DB::table('course_offerings')->where('id', 3)->value('teacher_id'));
        $this->assertFalse(Schema::hasColumn('course_offerings', 'teacher_user_id'));
        $this->assertColumnNotNullable('course_offerings', 'teacher_id');
        $this->assertForeignKey('course_offerings', 'teacher_id', 'teachers', 'id');
    }

    public function test_migration_fails_without_losing_teacher_user_id_when_teacher_profile_is_missing(): void
    {
        $this->prepareDatabaseBeforeTeacherReplacementMigration();

        $this->insertUser(99);
        $this->insertCourseOffering(1, 99);

        try {
            $this->migration()->up();
            $this->fail('The migration should fail when a course offering has no associated Teacher profile.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Existen course_offerings cuyo teacher_user_id no tiene perfil Teacher asociado.',
                $exception->getMessage()
            );
        }

        $this->assertTrue(Schema::hasColumn('course_offerings', 'teacher_user_id'));
        $this->assertFalse(Schema::hasColumn('course_offerings', 'teacher_id'));
        $this->assertSame(99, DB::table('course_offerings')->where('id', 1)->value('teacher_user_id'));
    }

    public function test_down_restores_teacher_user_id_from_teacher_id(): void
    {
        $this->prepareDatabaseBeforeTeacherReplacementMigration();

        $this->insertTeacherProfile(3, 10);
        $this->insertCourseOffering(1, 10);

        $migration = $this->migration();

        $migration->up();
        $this->assertSame(3, DB::table('course_offerings')->where('id', 1)->value('teacher_id'));

        $migration->down();

        $this->assertTrue(Schema::hasColumn('course_offerings', 'teacher_user_id'));
        $this->assertFalse(Schema::hasColumn('course_offerings', 'teacher_id'));
        $this->assertSame(10, DB::table('course_offerings')->where('id', 1)->value('teacher_user_id'));
        $this->assertColumnNotNullable('course_offerings', 'teacher_user_id');
        $this->assertForeignKey('course_offerings', 'teacher_user_id', 'users', 'id');
    }

    private function prepareDatabaseBeforeTeacherReplacementMigration(): void
    {
        Artisan::call('migrate:fresh', [
            '--path' => 'database/migrations/2026_09_05_000000_create_initial_database_schema.php',
            '--force' => true,
        ]);

        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_09_06_200000_create_teachers_table.php',
            '--force' => true,
        ]);

        DB::table('subjects')->insert([
            'id' => 1,
            'code' => 'INF-101',
            'name' => 'Ingenieria de Software',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('academic_terms')->insert([
            'id' => 1,
            'name' => '2026-I',
            'start_date' => '2026-02-01',
            'end_date' => '2026-07-01',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_06_200001_replace_teacher_user_id_on_course_offerings_table.php');
    }

    private function insertTeacherProfile(int $teacherId, int $userId): void
    {
        $this->insertUser($userId);

        DB::table('teachers')->insert([
            'id' => $teacherId,
            'user_id' => $userId,
            'institutional_code' => 'DOC-'.$teacherId,
            'identity_number' => 'CI-'.$teacherId,
            'first_names' => 'Teacher',
            'last_names' => (string) $teacherId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(int $id): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'email' => 'user'.$id.'@example.test',
            'password' => 'secret',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCourseOffering(int $id, int $teacherUserId): void
    {
        DB::table('course_offerings')->insert([
            'id' => $id,
            'subject_id' => 1,
            'academic_term_id' => 1,
            'teacher_user_id' => $teacherUserId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertColumnNotNullable(string $table, string $column): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $columnInfo = collect(DB::select("PRAGMA table_info({$table})"))
                ->firstWhere('name', $column);

            $this->assertNotNull($columnInfo);
            $this->assertSame(1, (int) $columnInfo->notnull);

            return;
        }

        $isNullable = DB::table('information_schema.columns')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('is_nullable');

        $this->assertSame('NO', $isNullable);
    }

    private function assertForeignKey(string $table, string $column, string $foreignTable, string $foreignColumn): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $foreignKey = collect(DB::select("PRAGMA foreign_key_list({$table})"))
                ->first(fn (object $key): bool => $key->from === $column);

            $this->assertNotNull($foreignKey);
            $this->assertSame($foreignTable, $foreignKey->table);
            $this->assertSame($foreignColumn, $foreignKey->to);

            return;
        }

        $foreignKey = DB::table('information_schema.table_constraints as tc')
            ->join('information_schema.key_column_usage as kcu', function ($join): void {
                $join->on('tc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('tc.table_schema', '=', 'kcu.table_schema');
            })
            ->join('information_schema.constraint_column_usage as ccu', function ($join): void {
                $join->on('ccu.constraint_name', '=', 'tc.constraint_name')
                    ->on('ccu.table_schema', '=', 'tc.table_schema');
            })
            ->where('tc.table_name', $table)
            ->where('tc.constraint_type', 'FOREIGN KEY')
            ->where('kcu.column_name', $column)
            ->where('ccu.table_name', $foreignTable)
            ->where('ccu.column_name', $foreignColumn)
            ->exists();

        $this->assertTrue($foreignKey);
    }
}
