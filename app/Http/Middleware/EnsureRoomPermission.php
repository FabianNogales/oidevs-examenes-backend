<?php

namespace App\Http\Middleware;

use App\Services\Rooms\RoomAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoomPermission
{
    public function __construct(private readonly RoomAuthorization $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $this->access->authorize($request->user(), $permission);

        return $next($request);
    }
}
