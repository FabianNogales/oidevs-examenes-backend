<?php

namespace Tests\Feature\Portal;

use App\Models\PortalNews;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $user->roles()->attach($role, ['status' => 'ACTIVE', 'assigned_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    public function test_separate_catalogs_crud_conflicts_selection_and_history(): void
    {
        $this->getJson('/api/v1/portal/news-categories')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/admin/portal/news-categories/manage')->assertUnauthorized();
        $this->admin();
        $this->postJson('/api/v1/admin/portal/news-categories/manage', ['name' => 'ACADÉMICO'])->assertUnprocessable()->assertJsonValidationErrors('name');
        foreach (['news', 'event'] as $catalog) {
            $base = '/api/v1/admin/portal/'.$catalog.'-categories';
            $id = $this->postJson($base.'/manage', ['name' => '  Investigacion  '])->assertCreated()
                ->assertJsonPath('data.name', 'Investigacion')->assertJsonPath('data.is_active', true)->json('data.id');
            $this->postJson($base.'/manage', ['name' => 'INVESTIGACION'])->assertUnprocessable()->assertJsonValidationErrors('name');
            $this->patchJson($base.'/manage/'.$id, ['name' => 'Investigacion nueva', 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
            $this->patchJson($base.'/manage/'.$id, ['name' => 'Perdida', 'revision' => 1])->assertConflict()->assertJsonPath('code', 'REVISION_CONFLICT');
            $this->patchJson($base.'/manage/'.$id.'/status', ['is_active' => false, 'revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);
            $this->getJson($base)->assertOk()->assertJsonMissing(['id' => $id, 'name' => 'Investigacion nueva']);
            $this->getJson($base.'/manage?is_active=false&search=Investigacion&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_active', false);
            $this->getJson($base.'/manage/'.$id.'/history')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.new_values.is_active', false);
            $this->patchJson($base.'/manage/'.$id.'/status', ['is_active' => true, 'revision' => 3])->assertOk();
            $this->deleteJson($base.'/manage/'.$id)->assertStatus(405);
        }
    }

    public function test_news_category_assignment_filters_and_legacy_records(): void
    {
        $admin = $this->admin();
        $base = '/api/v1/admin/portal/news';
        $input = ['title' => 'Comunicado', 'summary' => 'Resumen', 'content' => 'Contenido'];
        $this->postJson($base, $input)->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $category = DB::table('portal_news_categories')->value('id');
        $id = $this->postJson($base, $input + ['category_id' => $category])->assertCreated()->assertJsonPath('data.category.id', $category)->json('data.id');
        $this->patchJson($base.'/'.$id.'/status', ['revision' => 1, 'status' => 'PUBLISHED'])->assertOk();
        DB::table('portal_news_categories')->where('id', $category)->update(['is_active' => false]);
        $this->getJson('/api/v1/portal/news?category_id='.$category.'&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.category.id', $category);
        $this->getJson('/api/v1/portal/news/'.$id)->assertOk()->assertJsonPath('data.category.id', $category);
        $this->patchJson($base.'/'.$id, ['revision' => 2, 'title' => 'Editado'])->assertOk()->assertJsonPath('data.category.id', $category);
        foreach ([null, $category, 99999] as $invalid) {
            $this->patchJson($base.'/'.$id, ['revision' => 3, 'category_id' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
        $legacy = new PortalNews($input);
        $legacy->forceFill(['created_by' => $admin->id, 'updated_by' => $admin->id])->save();
        $this->getJson($base.'/'.$legacy->id)->assertOk()->assertJsonPath('data.category', null);
        $this->patchJson($base.'/'.$legacy->id, ['revision' => 1, 'summary' => 'Actualizado'])->assertOk()->assertJsonPath('data.category', null);
        $this->getJson($base.'?category_id=99999')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_permissions_validation_noop_and_audit_rollback(): void
    {
        $admin = $this->admin();
        $base = '/api/v1/admin/portal/news-categories/manage';
        $id = DB::table('portal_news_categories')->value('id');
        foreach (['', '   ', '<script>bad</script>', str_repeat('a', 101)] as $name) {
            $this->postJson($base, ['name' => $name])->assertUnprocessable();
        }
        $this->postJson($base, ['name' => 'Valida', 'is_active' => false])->assertUnprocessable();
        $this->patchJson($base.'/'.$id.'/status', ['revision' => 1, 'is_active' => true])->assertOk()->assertJsonPath('data.revision', 1);
        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'PortalNewsCategory']);
        $this->patchJson($base.'/99999', ['revision' => 1, 'name' => 'Ausente'])->assertNotFound();
        $this->getJson($base.'/99999/history')->assertNotFound();
        $this->mock(AuditLogService::class, fn ($mock) => $mock->shouldReceive('log')->andThrow(new \RuntimeException('Audit failed')));
        $this->patchJson($base.'/'.$id.'/status', ['revision' => 1, 'is_active' => false])->assertStatus(500);
        $this->assertDatabaseHas('portal_news_categories', ['id' => $id, 'is_active' => true, 'revision' => 1]);
        $admin->roles()->detach();
        foreach (['news', 'event'] as $catalog) {
            $path = '/api/v1/admin/portal/'.$catalog.'-categories/manage';
            $this->getJson($path)->assertForbidden();
            $this->postJson($path, ['name' => 'Sin permiso'])->assertForbidden();
            $this->patchJson($path.'/1', ['revision' => 1, 'name' => 'Sin permiso'])->assertForbidden();
            $this->patchJson($path.'/1/status', ['revision' => 1, 'is_active' => false])->assertForbidden();
            $this->getJson($path.'/1/history')->assertForbidden();
        }
    }

    public function test_filters_apply_before_pagination_and_category_changes_are_audited(): void
    {
        $this->admin();
        $base = '/api/v1/admin/portal/news';
        $ids = DB::table('portal_news_categories')->pluck('id')->all();
        $input = ['title' => 'Noticia de prueba', 'summary' => 'Resumen', 'content' => 'Contenido'];
        foreach ([$ids[0], $ids[1], $ids[0]] as $category) {
            $id = $this->postJson($base, $input + ['category_id' => $category])->assertCreated()->json('data.id');
            $this->patchJson($base.'/'.$id.'/status', ['revision' => 1, 'status' => 'PUBLISHED'])->assertOk();
        }
        foreach ([$base, '/api/v1/portal/news'] as $path) {
            $this->getJson($path.'?category_id='.$ids[0].'&per_page=1&page=2')->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.category.id', $ids[0]);
        }
        $this->patchJson($base.'/'.$id, ['revision' => 2, 'category_id' => $ids[1]])->assertOk()->assertJsonPath('data.category.id', $ids[1]);
        $this->getJson($base.'/'.$id.'/history')->assertOk()->assertJsonPath('data.0.old_values.category_id', $ids[0])->assertJsonPath('data.0.new_values.category_id', $ids[1]);
    }
}
