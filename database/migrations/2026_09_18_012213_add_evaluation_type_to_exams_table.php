<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('exams', function (Blueprint $table) {
            // Conjunto cerrado de valores: partial, final, makeup
            $table->string('evaluation_type', 50)->after('room_id')->default('partial');
        });
    }

    public function down(): void {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('evaluation_type');
        });
    }
};