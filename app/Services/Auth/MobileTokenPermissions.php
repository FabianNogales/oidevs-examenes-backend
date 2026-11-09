<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class MobileTokenPermissions
{
    public const PREFIX = 'eida-control:';

    public const ABILITIES = [
        'mobile:session', 'mobile:devices', 'control:read',
        'offline:prepare', 'offline:download', 'offline:sync',
    ];

    public static function isMobile(PersonalAccessToken $token): bool
    {
        return $token->mobile_device_id !== null || str_starts_with($token->name, self::PREFIX);
    }

    public function requiredAbility(Request $request): ?string
    {
        // Match declared route templates, never client-supplied exam/actor identities.
        $route = $request->route();
        $operation = $request->method().' '.($route?->uri() ?? '');

        return match ($operation) {
            'GET api/v1/me', 'POST api/v1/auth/logout' => 'mobile:session',
            'GET api/v1/auth/devices', 'DELETE api/v1/auth/devices/{id}' => 'mobile:devices',
            'GET api/v1/teacher/exams', 'GET api/v1/teacher/exams/{exam}',
            'GET api/v1/me/collaborations', 'GET api/v1/exams/{exam_id}/offline/assignments',
            'GET api/v1/exams/{exam_id}/offline/mobile-context' => 'control:read',
            'POST api/v1/exams/{exam_id}/offline/prepare' => 'offline:prepare',
            'GET api/v1/exams/{exam_id}/offline/package',
            'GET api/v1/exams/{exam_id}/offline/packages/{package_id}',
            'GET api/v1/exams/{exam_id}/offline/packages/{package_id}/photos/{student_id}/{kind}' => 'offline:download',
            'POST api/v1/exams/{exam_id}/offline/sync',
            'GET api/v1/exams/{exam_id}/offline/sync-capabilities' => 'offline:sync',
            default => null,
        };
    }
}
