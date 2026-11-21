<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_first_names')->nullable();
            $table->string('staff_last_names')->nullable();
            $table->string('staff_identity_number')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['staff_identity_number']);
            $table->dropColumn(['staff_first_names', 'staff_last_names', 'staff_identity_number']);
        });
    }
};
