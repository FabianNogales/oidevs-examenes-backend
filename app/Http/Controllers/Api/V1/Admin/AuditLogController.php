<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Audit\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return AuditLogResource::collection(AuditLog::query()->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10)->withQueryString());
    }

    public function show(string $auditLog): AuditLogResource
    {
        return AuditLogResource::make(AuditLog::findOrFail($auditLog));
    }
}
