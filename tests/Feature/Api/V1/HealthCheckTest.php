<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_returns_api_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'version',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OiDevs API is running')
            ->assertJsonPath('data.version', 'v1');
    }
}
