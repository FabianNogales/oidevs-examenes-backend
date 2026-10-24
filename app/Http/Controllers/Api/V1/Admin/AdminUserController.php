<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function __construct(private readonly AdminUserService $users) {}

    public function index(Request $request)
    {
        return AdminUserResource::collection($this->users->listing($this->filters($request)));
    }

    public function show(User $user)
    {
        return new AdminUserResource($user->load(['teacher', 'student', 'roles']));
    }

    public function status(Request $request, User $user)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
            'roles' => ['prohibited'], 'role_id' => ['prohibited']]);

        return new AdminUserResource($this->users->status($user, $data['status'], $request));
    }

    public function roles(?User $user = null)
    {
        $assigned = $user?->roles()->wherePivot('status', 'ACTIVE')->pluck('roles.id')->all() ?? [];

        return response()->json(['data' => Role::whereIn('name', array_column(RoleName::cases(), 'value'))
            ->orderBy('id')->get()->map(fn ($role) => ['id' => $role->id, 'name' => $role->name,
                'status' => $role->status, 'assigned' => in_array($role->id, $assigned, true)])]);
    }

    public function assign(Request $request, User $user)
    {
        $data = $request->validate(['role_id' => ['required', 'integer', 'exists:roles,id']]);

        return new AdminUserResource($this->users->role($user, Role::findOrFail($data['role_id']), true, $request));
    }

    public function revoke(Request $request, User $user, Role $role)
    {
        return new AdminUserResource($this->users->role($user, $role, false, $request));
    }

    public function history(Request $request, ?User $user = null)
    {
        $filters = $this->filters($request) + $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1'], 'action' => ['sometimes', Rule::in(AdminUserService::ACTIONS)],
        ]);
        $page = $this->users->history($filters, $user);
        $page->through(fn ($event) => ['id' => $event->id, 'user_id' => $event->entity_id,
            'actor_id' => $event->user_id, 'actor_name' => $event->user?->display_name,
            'action' => $event->action, 'created_at' => $event->created_at?->toISOString(),
            'old_context' => array_intersect_key($event->old_values ?? [], array_flip(['status', 'role', 'assigned'])),
            'new_context' => array_intersect_key($event->new_values ?? [], array_flip(['status', 'role', 'assigned']))]);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]);
    }

    private function filters(Request $request): array
    {
        return $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'search' => ['sometimes', 'nullable', 'string', 'max:150']]);
    }
}
