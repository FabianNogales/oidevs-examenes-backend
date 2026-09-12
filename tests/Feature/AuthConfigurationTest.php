<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthConfigurationTest extends TestCase
{
    public function test_public_registration_routes_are_not_available(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_profile_information_route_is_not_available(): void
    {
        $this->assertFalse(Route::has('user-profile-information.update'));
    }

    public function test_two_factor_routes_are_not_available(): void
    {
        $this->assertFalse(Route::has('two-factor.login'));
        $this->assertFalse(Route::has('two-factor.login.store'));
        $this->assertFalse(Route::has('two-factor.enable'));
        $this->assertFalse(Route::has('two-factor.disable'));
        $this->assertFalse(Route::has('two-factor.qr-code'));
        $this->assertFalse(Route::has('two-factor.recovery-codes'));
    }

    public function test_passkey_routes_are_not_available(): void
    {
        $this->assertFalse(Route::has('passkey.login'));
        $this->assertFalse(Route::has('passkey.login-options'));
        $this->assertFalse(Route::has('passkey.store'));
        $this->assertFalse(Route::has('passkey.destroy'));
    }

    public function test_sanctum_csrf_cookie_route_is_available(): void
    {
        $this->assertTrue(Route::has('sanctum.csrf-cookie'));

        $this->get('/sanctum/csrf-cookie')->assertNoContent();
    }
}
