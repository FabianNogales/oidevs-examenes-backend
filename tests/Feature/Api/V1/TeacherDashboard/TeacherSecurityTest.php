<?php

namespace Tests\Feature\Api\V1\TeacherDashboard;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Rutas de prueba para simular los endpoints protegidos del panel
        Route::middleware(['auth:sanctum', 'teacher.role'])->get('/test-teacher-route', function () {
            return response()->json(['message' => 'Welcome Teacher']);
        });

        Route::middleware(['auth:sanctum', 'teacher.role', 'block.mutations'])->post('/test-teacher-mutation', function () {
            return response()->json(['message' => 'Mutation allowed']);
        });
    }

    public function testNonTeacherIsRejectedWithForbiddenStatus(): void
    {
        $student = User::factory()->create(['status' => 'ACTIVE']);
        
        $roleId = DB::table('roles')->insertGetId([
            'name' => RoleName::ESTUDIANTE->value,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $student->id,
            'role_id' => $roleId,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($student, ['*']);
        
        $response = $this->getJson('/test-teacher-route');

        $response->assertStatus(403)
                 ->assertJson(['message' => 'Forbidden - Insufficient permissions']);
    }

    public function testMutationsAreBlockedForStandardTeachers(): void
    {
        $teacher = User::factory()->create(['status' => 'ACTIVE']);
        
        $roleId = DB::table('roles')->insertGetId([
            'name' => RoleName::DOCENTE->value,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $teacher->id,
            'role_id' => $roleId,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($teacher, ['*']);
        
        // Intentamos una petición POST (Mutación)
        $response = $this->postJson('/test-teacher-mutation');

        // Debe ser bloqueado por falta de nivel de autorización
        $response->assertStatus(403)
                 ->assertJson(['message' => 'Forbidden - Action requires higher authorization level']);
    }
}
