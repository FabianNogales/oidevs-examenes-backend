<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogService
{
    public function log(
        Request $request,
        ?int $userId,
        string $action,
        string $entityType = 'ADMIN_PANEL',
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}