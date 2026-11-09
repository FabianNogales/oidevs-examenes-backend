<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_event_categories', fn (Blueprint $t) => $t->unsignedInteger('revision')->default(1));
        Schema::create('portal_news_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::table('portal_news', fn (Blueprint $t) => $t->foreignId('category_id')->nullable()->constrained('portal_news_categories')->restrictOnDelete());
        foreach (['portal_event_categories' => ['Actividades académicas'], 'portal_news_categories' => ['Académico', 'Institucional', 'Tecnología', 'Convocatorias']] as $table => $names) {
            foreach ($names as $name) {
                if (! DB::table($table)->whereRaw('LOWER(TRIM(name)) = LOWER(?)', [$name])->exists()) {
                    DB::table($table)->insertOrIgnore(['name' => $name, 'is_active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            // A database-enforced normalized key also protects simultaneous requests.
            // Refuse an ambiguous legacy catalog instead of merging IDs used by historical events.
            DB::statement('CREATE UNIQUE INDEX '.$table.'_normalized_name ON '.$table.' (LOWER(TRIM(name)))');
        }
    }

    public function down(): void
    {
        // Preserve category associations and audit history on application rollback.
    }
};
