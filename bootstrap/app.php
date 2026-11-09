<?php

use App\Http\Middleware\ActionAuditLogger;
use App\Http\Middleware\BlockMutations;
use App\Http\Middleware\EnforceMobileTokenAccess;
use App\Http\Middleware\EnsureCurrentSession;
use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\VerifyAdminRole;
use App\Http\Middleware\VerifyExamAccess;
use App\Http\Middleware\VerifyTeacherRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Render terminates HTTPS before forwarding requests to this container.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO |
            Request::HEADER_X_FORWARDED_PREFIX
        );

        $middleware->statefulApi();
        $middleware->api(prepend: [EnforceMobileTokenAccess::class]);

        // Todos los alias de middleware consolidados en un solo arreglo
        $middleware->alias([
            'teacher.role' => VerifyTeacherRole::class,
            'block.mutations' => BlockMutations::class,
            'audit.logger' => ActionAuditLogger::class,
            'verify.admin' => VerifyAdminRole::class,
            'password.changed' => EnsurePasswordHasBeenChanged::class,
            'session.current' => EnsureCurrentSession::class,
            'role' => EnsureUserHasRole::class,
            'verify.exam.access' => VerifyExamAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'La sesión no es válida o ha expirado.'], 401);
            }
        });
    })->create();
