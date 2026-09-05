<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected AuthenticationService $authenticationService;

    public function __construct(AuthenticationService $authenticationService)
    {
        $this->authenticationService = $authenticationService;
    }

    public function revokeSessionToken(Request $request): JsonResponse
    {
        $this->authenticationService->revokeSessionToken($request);

        return response()->json([
            'message' => 'Session revoked successfully'
        ], 200);
    }
}