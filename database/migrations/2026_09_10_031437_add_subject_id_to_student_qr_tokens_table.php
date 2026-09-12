<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('student_qr_tokens', function (Blueprint $table) {
            $table->foreignId('exam_id')
                ->after('student_id')
                ->constrained('exams')
                ->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::table('student_qr_tokens', function (Blueprint $table) {
            $table->dropForeign(['exam_id']);
            $table->dropColumn('exam_id');
        });
    }
};