<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedSmallInteger('partial_number')->nullable()->after('evaluation_type');
            $table->boolean('is_shared_room')->default(false)->after('duration_minutes');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE exams
                ADD CONSTRAINT exams_partial_number_range_check
                CHECK (partial_number IS NULL OR (partial_number >= 1 AND partial_number <= 6))
            ');
        }

        Schema::create('exam_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_shared')->default(false);
            $table->boolean('shared_with_consent')->default(false);
            $table->timestamps();

            $table->unique(['exam_id', 'room_id'], 'exam_rooms_exam_room_unique');
            $table->index(['room_id', 'is_shared']);
        });

        // Sincroniza los exámenes existentes en la nueva tabla exam_rooms
        $existingExams = DB::table('exams')->select(['id', 'room_id', 'is_shared_room'])->get();
        $now = now();
        foreach ($existingExams as $exam) {
            DB::table('exam_rooms')->insertOrIgnore([
                'exam_id' => $exam->id,
                'room_id' => $exam->room_id,
                'is_primary' => true,
                'is_shared' => (bool) $exam->is_shared_room,
                'shared_with_consent' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_rooms');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exams DROP CONSTRAINT IF EXISTS exams_partial_number_range_check');
        }

        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['partial_number', 'is_shared_room']);
        });
    }
};