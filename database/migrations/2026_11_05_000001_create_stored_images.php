<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('purpose', 20);
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('news_id')->nullable()->constrained('portal_news')->cascadeOnDelete();
            $table->binary('content'); // Laravel PostgreSQL grammar: BYTEA.
            $table->string('mime_type', 32);
            $table->unsignedInteger('byte_size');
            $table->char('sha256', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestampTz('uploaded_at');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['student_id', 'purpose']);
            $table->index('news_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE stored_images ADD CONSTRAINT stored_images_owner_check CHECK (
                (purpose IN ('profile', 'historical') AND student_id IS NOT NULL AND news_id IS NULL)
                OR (purpose = 'news' AND news_id IS NOT NULL AND student_id IS NULL))");
            DB::statement("ALTER TABLE stored_images ADD CONSTRAINT stored_images_content_check CHECK (
                byte_size = octet_length(content) AND byte_size > 0 AND byte_size <= 5242880
                AND mime_type IN ('image/jpeg', 'image/png', 'image/webp')
                AND width > 0 AND height > 0 AND width::bigint * height <= 16000000)");
        }
        Schema::table('users', fn (Blueprint $table) => $table->foreignUuid('profile_image_id')->nullable()->constrained('stored_images')->nullOnDelete());
        Schema::table('students', fn (Blueprint $table) => $table->foreignUuid('historical_image_id')->nullable()->constrained('stored_images')->nullOnDelete());
        Schema::table('portal_news', fn (Blueprint $table) => $table->foreignUuid('image_id')->nullable()->constrained('stored_images')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('portal_news', fn (Blueprint $table) => $table->dropConstrainedForeignId('image_id'));
        Schema::table('students', fn (Blueprint $table) => $table->dropConstrainedForeignId('historical_image_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('profile_image_id'));
        Schema::dropIfExists('stored_images');
    }
};
