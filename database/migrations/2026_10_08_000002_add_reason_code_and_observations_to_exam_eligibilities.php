<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_eligibilities', function (Blueprint $table) {
            $table->string('reason_code', 64)->nullable();
            $table->text('observations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_eligibilities', function (Blueprint $table) {
            $table->dropColumn(['reason_code', 'observations']);
        });
    }
};
