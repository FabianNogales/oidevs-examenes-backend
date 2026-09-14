<?php

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifyAdminRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Configuramos una ruta de prueba protegida por el middleware que vamos a crear
        Route::middleware(['auth:sanctum', 'verify.admin'])->get('/test-admin-route', function () {
            return response()->json(['message' => 'Welcome Admin']);
        });
    }

    public function testNonAdminUserIsRejectedWithForbiddenStatus(): void
    {
        $user = User::factory()->create(['status' => 'ACTIVE']);
        
        // Creamos un rol que NO es admin y lo asignamos
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Student',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/test-admin-route');

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Forbidden - Insufficient permissions']);
    }

    public function testAdminUserCanAccessProtectedRoutes(): void
    {
        $adminUser = User::factory()->create(['status' => 'ACTIVE']);
        
        // Creamos el rol Admin y lo asignamos
        $adminRoleId = DB::table('roles')->insertGetId([
            'name' => 'Admin',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $adminUser->id,
            'role_id' => $adminRoleId,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($adminUser)->getJson('/test-admin-route');

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Welcome Admin']);
    }
}