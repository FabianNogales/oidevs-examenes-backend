<?php

namespace App\Services\Auth;

use App\Http\Resources\Auth\CurrentUserResource;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileAuthService
{
    public function __construct(private LoginUserResolver $resolver, private MobileOperatorAccess $access,
        private MobileDeviceService $devices, private AuditLogService $audit) {}

    public function login(array $data, Request $request): array
    {
        $resolved = $this->resolver->resolve($data['identifier'], $data['password']);
        if (! $resolved) {
            throw ValidationException::withMessages(['identifier' => 'Las credenciales no son válidas.']);
        }

        return DB::transaction(function () use ($resolved, $data, $request) {
            $actor = User::whereKey($resolved->id)->lockForUpdate()->firstOrFail();
            if (! $actor->isActive() || ! hash_equals($resolved->getAuthPassword(), $actor->getAuthPassword())) {
                throw ValidationException::withMessages(['identifier' => 'Las credenciales no son válidas.']);
            }
            if ($actor->must_change_password) {
                $this->fail('PASSWORD_CHANGE_REQUIRED', 'Debe cambiar su contraseña en EIDA Web antes de continuar.', 403);
            }
            if (! $this->access->allows($actor)) {
                $this->fail('MOBILE_OPERATOR_REQUIRED', 'Se requiere un examen propio o una colaboración vigente.', 403);
            }
            $ttl = config('mobile.token_ttl_minutes');
            if (! is_int($ttl) || $ttl < 1 || $ttl > 10080) {
                $this->fail('MOBILE_CONFIGURATION_INVALID', 'La autenticación móvil no está disponible.', 503);
            }
            $globalTtl = config('sanctum.expiration');
            if (is_numeric($globalTtl) && $globalTtl > 0) {
                $ttl = min($ttl, (int) $globalTtl);
            }
            $device = MobileDevice::firstOrNew(['user_id' => $actor->id, 'installation_id' => $data['installation_id']]);
            $device->fill(['name' => $data['device_name'], 'platform' => 'android',
                'app_version' => $data['app_version'] ?? null, 'last_login_at' => now(), 'revoked_at' => null])->save();
            // One current token per installation; other devices and web sessions survive.
            $device->tokens()->delete();
            $expires = now()->addMinutes($ttl);
            $issued = $actor->createToken(MobileTokenPermissions::PREFIX.$device->id, MobileTokenPermissions::ABILITIES, $expires);
            $issued->accessToken->forceFill(['mobile_device_id' => $device->id])->save();
            $actor->forceFill(['last_login_at' => now()])->save();
            $this->audit->log($request, $actor->id, 'MOBILE_LOGIN', MobileDevice::class, $device->id,
                null, ['expires_at' => $expires->toISOString()]);

            return ['access_token' => $issued->plainTextToken, 'token_type' => 'Bearer',
                'expires_at' => $expires->toISOString(), 'abilities' => MobileTokenPermissions::ABILITIES,
                'device' => $this->devices->present($device),
                'user' => CurrentUserResource::make($actor->load('activeRoles', 'teacher', 'student'))->resolve($request),
                'server_time' => now()->toISOString()];
        }, 3);
    }

    private function fail(string $code, string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(compact('message', 'code'), $status));
    }
}
