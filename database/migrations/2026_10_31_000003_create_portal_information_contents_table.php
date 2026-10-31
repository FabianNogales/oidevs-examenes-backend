<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_information_contents', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('key', 50)->nullable()->unique();
            $table->json('draft');
            $table->json('published')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['kind', 'is_published']);
        });

        // Approved structure only: no institutional claims, contact data or FAQs.
        $sections = [
            'about' => 'Acerca de EIDA',
            'objectives' => 'Objetivos y beneficios',
            'student_guide' => 'Orientaciones para estudiantes',
            'teacher_guide' => 'Orientaciones para docentes',
            'collaborator_guide' => 'Orientaciones para colaboradores',
            'contact' => 'Contacto autorizado',
        ];
        $order = 0;
        foreach ($sections as $key => $title) {
            DB::table('portal_information_contents')->insert([
                'kind' => 'SECTION',
                'key' => $key,
                'draft' => json_encode(['title' => $title, 'description' => '', 'links' => [], 'sort_order' => ++$order], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_information_contents');
    }
};
