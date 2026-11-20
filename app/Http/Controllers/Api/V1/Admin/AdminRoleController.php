<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveCustomRoleRequest;
use App\Http\Resources\Admin\AdminRoleResource;
use App\Models\Role;
use App\Services\Admin\CustomRoleService;
use App\Services\Admin\RolePermissionCatalog;

class AdminRoleController extends Controller
{
    public function permissions(RolePermissionCatalog $catalog)
    {
        return response()->json(['data' => $catalog->configurable()->map(fn ($permission) => [
            'key' => $permission->key, 'module' => $permission->module, 'label' => $permission->label,
            'description' => $permission->description, 'is_configurable' => true,
            'is_enabled' => $permission->is_enabled && RolePermissionCatalog::ENABLED[$permission->key],
        ])]);
    }

    public function show(Role $role)
    {
        return new AdminRoleResource($role->load('permissions'));
    }

    public function store(SaveCustomRoleRequest $request, CustomRoleService $service)
    {
        return (new AdminRoleResource($service->save($request->validated(), $request)))->response()->setStatusCode(201);
    }

    public function update(SaveCustomRoleRequest $request, Role $role, CustomRoleService $service)
    {
        return new AdminRoleResource($service->save($request->validated(), $request, $role));
    }
}
