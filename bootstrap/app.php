<?php

use App\Http\Middleware\EnsureCurrentSession;
use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        // Todos los alias de middleware consolidados en un solo arreglo
        $middleware->alias([
            'teacher.role' => \App\Http\Middleware\VerifyTeacherRole::class,
            'block.mutations' => \App\Http\Middleware\BlockMutations::class,
            'audit.logger' => \App\Http\Middleware\ActionAuditLogger::class,
            'verify.admin' => \App\Http\Middleware\VerifyAdminRole::class,
            'password.changed' => EnsurePasswordHasBeenChanged::class,
            'session.current' => EnsureCurrentSession::class,
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();