<?php

namespace App\Providers;

use App\Services\QrV3\QrV3Factory;
use App\Services\QrV3\QrV3Service;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(QrV3Service::class,
            fn () => app(QrV3Factory::class)->make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');
            $query = http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

            return "{$frontendUrl}/reset-password?{$query}";
        });
    }
}
