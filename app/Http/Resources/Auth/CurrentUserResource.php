<?php

namespace App\Http\Resources\Auth;

use App\Enums\RoleName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrentUserResource extends JsonResource
{
    /**
     * Contrato publico del usuario autenticado.
     *
     * No debe exponer password, active_session_id ni datos internos de sesion.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'status' => $this->status,
            'must_change_password' => $this->must_change_password,
            'roles' => $this->whenLoaded(
                'activeRoles',
                fn () => $this->activeRoles->pluck('name')
                    ->map(fn (string $name) => RoleName::fromStoredName($name)?->value ?? $name)
                    ->unique()->values()->all(),
                [],
            ),
        ];
    }
}
