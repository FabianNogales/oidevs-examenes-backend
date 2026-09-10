<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('student_qr_tokens', function (Blueprint $table) {
            // Agrega la columna subject_id después de student_id y establece la relación con la tabla 'subjects'
            $table->foreignId('subject_id')
                ->nullable()
                ->after('student_id')
                ->constrained('subjects')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('student_qr_tokens', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropColumn('subject_id');
        });
    }
};