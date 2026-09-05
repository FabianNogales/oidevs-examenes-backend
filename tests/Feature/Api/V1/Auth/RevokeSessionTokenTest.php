<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RevokeSessionTokenTest extends TestCase
{
    use RefreshDatabase;

    public function testUserCanRevokeSessionTokenSuccessfully(): void
    {
        $user = User::factory()->create(['status' => 'ACTIVE']);
        
        // Simulamos que el usuario tiene un token activo
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Session revoked successfully']);
                 
        // Verificamos que el token haya sido eliminado de la base de datos
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}