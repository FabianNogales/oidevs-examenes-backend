<?php

namespace Tests\Feature\Api\V1\TeacherDashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherAuditTest extends TestCase
{
    use RefreshDatabase;

    public function testSuccessfulDashboardRequestGeneratesAuditLog(): void
    {
        $teacher = User::factory()->create(['status' => 'ACTIVE']);
        
        // Asignamos el rol "Docente"
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Docente',
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

        // Hacemos una petición al endpoint que ya existe y que devuelve 200 OK
        $response = $this->getJson('/api/v1/teacher/dashboard/subjects');

        $response->assertStatus(200);

        // Validamos que el sistema haya insertado el registro de auditoría automáticamente
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $teacher->id,
            'action' => 'READ',
            'entity_type' => 'TeacherDashboard',
        ]);
    }
}