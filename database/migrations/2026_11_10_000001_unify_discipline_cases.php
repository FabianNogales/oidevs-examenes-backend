<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discipline_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('occurred_at');
            $table->text('observations')->nullable();
            $table->foreignId('request_actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unique(['source_type', 'source_id']);
            $table->unique(['request_actor_id', 'request_key']);
            $table->index(['exam_id', 'occurred_at', 'id']);
        });
        Schema::table('expulsions', function (Blueprint $table) {
            $table->foreignId('discipline_case_id')->nullable()->unique()->constrained('discipline_cases')->restrictOnDelete();
        });
        foreach (['special_cases' => 'INCIDENT', 'infractions' => 'INFRACTION'] as $table => $kind) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($kind) {
                foreach ($rows as $row) {
                    DB::table('discipline_cases')->insert(['exam_id' => $row->exam_id, 'source_type' => $kind,
                        'source_id' => $row->id, 'occurred_at' => $row->occurred_at ?? $row->created_at ?? now()]);
                }
            });
        }
        DB::table('audit_logs')->where('action', 'ENTRY_QR_WRONG_EXAM')->where('entity_type', 'Exam')->whereIn('entity_id', DB::table('exams')->select('id'))->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('discipline_cases')->insert(['exam_id' => $row->entity_id, 'source_type' => 'QR', 'source_id' => $row->id, 'occurred_at' => $row->created_at]);
            }
        });
        DB::table('expulsions')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $case = $row->infraction_id ? DB::table('discipline_cases')->where('source_type', 'INFRACTION')->where('source_id', $row->infraction_id)->where('exam_id', $row->exam_id)->whereIn('source_id', DB::table('infractions')->where('student_id', $row->student_id)->select('id'))->value('id') : null;
                $case ??= DB::table('discipline_cases')->insertGetId(['exam_id' => $row->exam_id, 'source_type' => 'LEGACY_EXPULSION', 'source_id' => $row->id, 'occurred_at' => $row->expelled_at]);
                DB::table('expulsions')->where('id', $row->id)->update(['discipline_case_id' => $case]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('discipline_cases')->whereNotNull('request_key')->exists()) {
            throw new RuntimeException('Existen casos unificados registrados. Requieren un plan de conservación antes del rollback.');
        }
        Schema::table('expulsions', fn (Blueprint $table) => $table->dropUnique(['discipline_case_id']));
        Schema::table('expulsions', fn (Blueprint $table) => $table->dropConstrainedForeignId('discipline_case_id'));
        Schema::dropIfExists('discipline_cases');
    }
};
