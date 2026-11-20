<?php

namespace App\Services\Admin;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffAccountService
{
    public function __construct(private AdministratorOperation $administrator, private AdminUserService $users,
        private InitialPasswordService $passwords, private AuditLogService $audit) {}

    public function create(array $input, Request $request): User
    {
        try {
            return $this->administrator->run($request, function () use ($input, $request) {
                $role = Role::findOrFail($input['role_id']);
                abort_unless($role->isCustom() && $role->status === 'ACTIVE', 422, 'Selecciona un rol personalizado activo.');
                $user = new User;
                $user->forceFill(['email' => $input['email'], 'status' => 'ACTIVE',
                    'staff_first_names' => $input['first_names'], 'staff_last_names' => $input['last_names'],
                    'staff_identity_number' => $input['identity_number']]);
                $this->passwords->initialize($user, $input['identity_number']);
                // Use the exact HU22 assignment, audit and revocation path inside this transaction.
                $user = $this->users->role($user, $role, true, $request);
                $this->audit->log($request, $request->user()->id, 'STAFF_ACCOUNT_CREATED', 'User', $user->id,
                    null, ['role_id' => $role->id, 'role' => $role->name, 'status' => 'ACTIVE']);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Revisa el correo y el CI: alguno ya está registrado.']);
        }
    }
}
