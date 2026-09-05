<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('profile_photo')->nullable();
            $table->string('status')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->index();

            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('careers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('sis_code')->unique();
            $table->string('identity_number')->unique();
            $table->string('first_names');
            $table->string('last_names');
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('status')->index();
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['course_offering_id', 'student_id']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('exam_date')->index();
            $table->time('start_time');
            $table->integer('duration_minutes');
            $table->text('rules')->nullable();
            $table->string('status')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('eligibility_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('source')->index();
            $table->string('criterion_type')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('exam_eligibility_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('eligibility_criterion_id')->constrained()->restrictOnDelete();
            $table->boolean('is_required');
            $table->jsonb('parameters')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'eligibility_criterion_id'], 'exam_criteria_unique');
        });

        Schema::create('student_criterion_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('eligibility_criterion_id')->constrained()->restrictOnDelete();
            $table->string('result')->index();
            $table->text('observation')->nullable();
            $table->foreignId('evaluated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('evaluated_at')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['exam_id', 'student_id', 'eligibility_criterion_id'],
                'student_criterion_results_unique'
            );
        });

        Schema::create('exam_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('status')->index();
            $table->text('reason')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
        });

        Schema::create('exam_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->string('access_code_hash');
            $table->string('status')->index();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
        });

        Schema::create('student_qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->uuid('token')->unique();
            $table->string('status')->index();
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->string('verification_method');
            $table->timestamp('entered_at')->useCurrent()->index();
            $table->string('status')->index();
            $table->timestamp('annulled_at')->nullable();
            $table->foreignId('annulled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('annulment_reason')->nullable();
            $table->timestamps();
        });

        DB::statement(
            "CREATE UNIQUE INDEX uq_exam_entries_valid_student
            ON exam_entries (exam_id, student_id)
            WHERE status = 'VALID'"
        );

        Schema::create('special_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type')->index();
            $table->text('description');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('infractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('type')->index();
            $table->text('description');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('expulsions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('infraction_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('expelled_at')->useCurrent();
            $table->foreignId('expelled_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('expulsions');
        Schema::dropIfExists('infractions');
        Schema::dropIfExists('special_cases');
        DB::statement('DROP INDEX IF EXISTS uq_exam_entries_valid_student');
        Schema::dropIfExists('exam_entries');
        Schema::dropIfExists('student_qr_tokens');
        Schema::dropIfExists('exam_collaborators');
        Schema::dropIfExists('exam_eligibilities');
        Schema::dropIfExists('student_criterion_results');
        Schema::dropIfExists('exam_eligibility_criteria');
        Schema::dropIfExists('eligibility_criteria');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('students');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('academic_terms');
        Schema::dropIfExists('careers');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
