<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MobileLoginRequest;
use App\Services\Auth\MobileAuthService;
use Illuminate\Http\JsonResponse;

class MobileAuthController extends Controller
{
    public function login(MobileLoginRequest $request, MobileAuthService $service): JsonResponse
    {
        return response()->json(['data' => $service->login($request->validated(), $request)])
            ->header('Cache-Control', 'private, no-store');
    }
}
