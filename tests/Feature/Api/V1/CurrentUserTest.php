<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Tests\TestCase;

class CurrentUserTest extends TestCase
{
    public function test_current_user_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_current_user_returns_basic_authenticated_user_data(): void
    {
        $user = new User([
            'email' => 'login.test@oipass.local',
        ]);
        $user->id = 1;
        $user->status = 'ACTIVE';

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'id' => 1,
                    'email' => 'login.test@oipass.local',
                    'status' => 'ACTIVE',
                ],
            ]);
    }
}
