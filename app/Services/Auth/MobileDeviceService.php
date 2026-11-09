<?php

namespace App\Services\Auth;

use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileDeviceService
{
    public function __construct(private AuditLogService $audit) {}

    public function present(MobileDevice $device): array
    {
        return [
            'id' => $device->id, 'installation_id' => $device->installation_id,
            'device_name' => $device->name, 'platform' => $device->platform, 'app_version' => $device->app_version,
            'status' => $device->revoked_at === null ? 'ACTIVE' : 'REVOKED',
            'last_login_at' => $device->last_login_at->toISOString(),
            'revoked_at' => $device->revoked_at?->toISOString(),
        ];
    }

    public function listing(User $actor): array
    {
        return MobileDevice::where('user_id', $actor->id)->orderBy('id')->get()
            ->map(fn ($device) => $this->present($device))->all();
    }

    public function revoke(User $actor, int $id, Request $request, string $reason = 'DEVICE_REVOKED'): void
    {
        DB::transaction(function () use ($actor, $id, $request, $reason) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $device = MobileDevice::where('user_id', $actor->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $device->tokens()->delete();
            if ($device->revoked_at === null) {
                $device->forceFill(['revoked_at' => now()])->save();
                $this->audit->log($request, $actor->id, 'MOBILE_DEVICE_REVOKED', MobileDevice::class, $device->id,
                    null, ['reason' => $reason]);
            }
        }, 3);
    }

    // Call under the password action's user lock and transaction.
    public function revokeForPassword(User $actor, string $reason): void
    {
        $count = $actor->tokens()->where(function ($query) {
            $query->whereNotNull('mobile_device_id')->orWhere('name', 'like', MobileTokenPermissions::PREFIX.'%');
        })->delete();
        $devices = MobileDevice::where('user_id', $actor->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        if ($count > 0 || $devices > 0) {
            $this->audit->log(request(), $actor->id, 'MOBILE_TOKENS_REVOKED', User::class, $actor->id,
                null, ['reason' => $reason, 'token_count' => $count, 'device_count' => $devices]);
        }
    }
}
