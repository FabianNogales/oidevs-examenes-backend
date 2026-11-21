<?php

namespace Tests\Feature\Security;

use App\Support\BrowserOrigins;
use Fruitcake\Cors\CorsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// These routes exercise the real global middleware and named limiters without a database.
class HttpBoundarySecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'http_security.trusted_proxies' => ['192.0.2.10']]);
        Route::get('/api/v1/_sec01/boundary', fn (Request $request) => response()->json([
            'ip' => $request->ip(), 'host' => $request->getHost(), 'secure' => $request->isSecure(),
            'image_url' => route('portal.public.news.image', ['news' => 41]),
        ]));
    }

    public function test_untrusted_peer_cannot_supply_forwarding_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])->withHeaders([
            'X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Host' => 'attacker.example.test',
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Prefix' => '/forged',
        ])->getJson('http://backend.example.test/api/v1/_sec01/boundary')->assertOk()
            ->assertJsonPath('ip', '198.51.100.40')->assertJsonPath('host', 'backend.example.test')
            ->assertJsonPath('secure', false)
            ->assertJsonPath('image_url', 'http://backend.example.test/api/v1/portal/news/41/image');
    }

    public function test_verified_proxy_preserves_https_and_photo_prefix_but_not_forwarded_host(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->withHeaders([
            'X-Forwarded-For' => '203.0.113.66, 198.51.100.40',
            'X-Forwarded-Host' => 'attacker.example.test', 'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443', 'X-Forwarded-Prefix' => '/backend',
        ])->getJson('http://backend.example.test/api/v1/_sec01/boundary')->assertOk()
            ->assertJsonPath('ip', '198.51.100.40')->assertJsonPath('host', 'backend.example.test')
            ->assertJsonPath('secure', true)
            ->assertJsonPath('image_url', 'https://backend.example.test/backend/api/v1/portal/news/41/image');
    }

    public function test_empty_proxy_list_ignores_declared_ip(): void
    {
        config(['http_security.trusted_proxies' => '']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])->withHeader('X-Forwarded-For', '203.0.113.9')
            ->getJson('/api/v1/_sec01/boundary')->assertOk()->assertJsonPath('ip', '198.51.100.40');
    }

    public function test_production_cors_preflight_only_grants_explicit_origins_and_preserves_csrf_headers(): void
    {
        config(['cors.allowed_origins' => BrowserOrigins::allowed(null, 'https://portal.example.test', false),
            'cors.allowed_origins_patterns' => [], 'cors.supports_credentials' => true]);
        foreach (['https://portal.example.test' => true, 'http://localhost:5173' => false,
            'http://127.0.0.1:5173' => false, 'https://attacker.example.test' => false, 'null' => false] as $origin => $allowed) {
            $this->flushHeaders();
            $response = $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,x-xsrf-token,authorization'])
                ->options('/login');
            $this->assertSame($origin, $response->baseRequest->headers->get('Origin'));
            $this->assertSame(['https://portal.example.test'], config('cors.allowed_origins'));
            if ($allowed) {
                $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
                    ->assertHeader('Access-Control-Allow-Credentials', 'true');
                $this->assertStringContainsString('x-xsrf-token', strtolower($response->headers->get('Access-Control-Allow-Headers')));
            } else {
                $response->assertHeaderMissing('Access-Control-Allow-Origin')
                    ->assertHeaderMissing('Access-Control-Allow-Credentials');
            }
        }
        config(['cors.allowed_origins' => []]);
        $this->withHeader('Origin', 'null')->options('/login')->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_disallowed_origin_has_no_cors_authorization_after_an_allowed_request(): void
    {
        $allowed = 'https://portal.example.test';
        config(['cors.allowed_origins' => [$allowed], 'cors.allowed_origins_patterns' => [], 'cors.supports_credentials' => true]);
        $this->app->instance(CorsService::class, new CorsService);

        // Keep the same application and CORS service across requests. Both
        // preflight and actual responses must remain isolated by request origin.
        foreach (['OPTIONS', 'GET'] as $method) {
            foreach ([$allowed, 'https://attacker.example.test', $allowed, 'http://localhost:5173', $allowed, 'null'] as $origin) {
                $this->flushHeaders();
                $headers = ['Origin' => $origin];
                if ($method === 'OPTIONS') {
                    $headers['Access-Control-Request-Method'] = 'GET';
                }
                $response = $this->withHeaders($headers)->json($method, '/api/v1/_sec01/boundary');
                $this->assertSame($origin, $response->baseRequest->headers->get('Origin'));
                if ($origin === $allowed) {
                    $response->assertSuccessful()->assertHeader('Access-Control-Allow-Origin', $allowed)
                        ->assertHeader('Access-Control-Allow-Credentials', 'true');
                } else {
                    $response->assertHeaderMissing('Access-Control-Allow-Origin')
                        ->assertHeaderMissing('Access-Control-Allow-Credentials')
                        ->assertHeaderMissing('Access-Control-Allow-Methods')
                        ->assertHeaderMissing('Access-Control-Allow-Headers');
                }
                $this->assertContains('Origin', $response->baseResponse->getVary());
            }
        }
    }

    public function test_login_limits_cannot_be_bypassed_by_changing_declared_or_peer_ip(): void
    {
        // Exercise the unresolved ingress compatibility mode too: the account
        // limiter must hold even when the declared client IP keeps changing.
        config(['http_security.trusted_proxies' => '*']);
        foreach (['login', 'mobile-login'] as $limiter) {
            Route::post('/api/v1/_sec01/'.$limiter, fn () => response()->noContent())->middleware('throttle:'.$limiter);
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $identifier = $attempt % 2 ? ' SEC01@umss.edu.bo ' : 'sec01@umss.edu.bo';
                $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(20 + $attempt)])
                    ->withHeader('X-Forwarded-For', '203.0.113.'.(20 + $attempt))
                    ->postJson('/api/v1/_sec01/'.$limiter, ['identifier' => $identifier, 'password' => 'Incorrect1']);
                $attempt < 5 ? $response->assertNoContent() : $response->assertStatus(429)->assertHeader('Retry-After');
            }
        }
    }
}
