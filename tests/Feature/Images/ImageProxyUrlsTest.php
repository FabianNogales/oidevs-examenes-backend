<?php

namespace Tests\Feature\Images;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// No RefreshDatabase: these tests neither migrate nor access application data.
class ImageProxyUrlsTest extends TestCase
{
    private function imageRoutes(): array
    {
        return [
            'students.profile-photo' => ['v' => 'image-version'],
            'students.profile-photo.admin' => ['student' => 17],
            'students.historical-photo.show' => ['student' => 17],
            'entry-control.profile-photo' => ['exam_id' => 23, 'student_id' => 17],
            'entry-control.historical-photo' => ['exam_id' => 23, 'student_id' => 17],
            'eligibilities.profile-photo' => ['exam' => 23, 'student' => 17],
            'collaborators.profile-photo' => ['exam_id' => 23, 'user_id' => 31],
            'portal.admin.news.image' => ['news' => 41],
            'portal.public.news.image' => ['news' => 41],
        ];
    }

    public function test_generated_image_urls_preserve_the_forwarded_proxy_prefix(): void
    {
        $this->assertImageUrls('/backend', 'https://localhost:5173');
    }

    public function test_direct_backend_image_urls_do_not_gain_a_proxy_prefix(): void
    {
        $this->assertImageUrls('', 'http://127.0.0.1:8000');
    }

    private function assertImageUrls(string $prefix, string $origin): void
    {
        $routes = $this->imageRoutes();
        Route::get('/_image-url-contract', fn () => array_map(
            fn ($name, $parameters) => route($name, $parameters), array_keys($routes), $routes
        ));
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Prefix' => $prefix, 'X-Forwarded-Proto' => parse_url($origin, PHP_URL_SCHEME)])
            ->getJson($origin.'/_image-url-contract')->assertOk();
        foreach (array_values($routes) as $index => $parameters) {
            $name = array_keys($routes)[$index];
            $this->assertSame($origin.$prefix.route($name, $parameters, false), $response->json($index), $name);
        }
    }

    public function test_private_image_routes_reject_anonymous_requests_as_json(): void
    {
        foreach ($this->imageRoutes() as $name => $parameters) {
            if ($name === 'portal.public.news.image') {
                continue;
            }
            $this->getJson(route($name, $parameters, false))->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/json');
        }
    }
}
