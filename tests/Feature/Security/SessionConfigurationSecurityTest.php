<?php

namespace Tests\Feature\Security;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Env;
use ReflectionProperty;
use Tests\TestCase;

// Configuration and array-session checks do not access the database.
class SessionConfigurationSecurityTest extends TestCase
{
    private function sessionConfiguration(array $variables): array
    {
        return $this->configuration('session', $variables);
    }

    private function configuration(string $name, array $variables): array
    {
        // Isolate config evaluation from .env and restore the existing repository.
        // An array-only adapter does not change $_ENV, $_SERVER or process env.
        $property = new ReflectionProperty(Env::class, 'repository');
        $original = $property->getValue();
        $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        foreach ($variables as $key => $value) {
            $repository->set($key, $value);
        }
        $property->setValue(null, $repository);
        try {
            return require config_path($name.'.php');
        } finally {
            $property->setValue(null, $original);
        }
    }

    public function test_production_cookies_default_to_secure_without_breaking_local_http(): void
    {
        $this->assertTrue($this->sessionConfiguration(['APP_ENV' => 'production'])['secure']);
        $this->assertFalse($this->sessionConfiguration(['APP_ENV' => 'local'])['secure']);
        $this->assertFalse($this->sessionConfiguration(['APP_ENV' => 'testing'])['secure']);
        $this->assertTrue($this->sessionConfiguration(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true'])['secure']);
        $this->assertFalse($this->sessionConfiguration(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
    }

    public function test_session_encryption_remains_an_explicit_storage_migration(): void
    {
        $this->assertFalse($this->sessionConfiguration(['APP_ENV' => 'production'])['encrypt']);
        $this->assertTrue($this->sessionConfiguration(['APP_ENV' => 'production', 'SESSION_ENCRYPT' => 'true'])['encrypt']);
        config(['session.driver' => 'array', 'session.encrypt' => true]);
        $manager = app('session');
        $manager->forgetDrivers();
        $store = $manager->driver();
        $this->assertInstanceOf(EncryptedStore::class, $store);
        $store->start();
        $store->put('authentication_marker', 'synthetic-sensitive-marker');
        $id = $store->getId();
        $store->save();
        $this->assertStringNotContainsString('synthetic-sensitive-marker', $store->getHandler()->read($id));
    }

    public function test_existing_session_lifetime_and_browser_cookie_flags_are_preserved(): void
    {
        $configuration = $this->sessionConfiguration(['APP_ENV' => 'production']);
        $this->assertSame(5, $configuration['lifetime']);
        $this->assertTrue($configuration['http_only']);
        $this->assertSame('lax', $configuration['same_site']);
        $this->assertSame('/', $configuration['path']);
        $this->assertSame('15', $this->sessionConfiguration(['SESSION_LIFETIME' => '15'])['lifetime']);
    }

    public function test_sanctum_does_not_add_a_global_cutoff_to_explicit_mobile_expiry(): void
    {
        $configuration = $this->configuration('sanctum', ['APP_ENV' => 'production',
            'FRONTEND_URL' => 'https://portal.example.test', 'SANCTUM_EXPIRATION' => '5']);
        $this->assertNull($configuration['expiration']);
        $this->assertSame(['portal.example.test'], $configuration['stateful']);
    }
}
