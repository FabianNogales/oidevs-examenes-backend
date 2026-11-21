<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\Admin\RolePermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;

class SaveCustomRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() && $this->user()->hasRole('ADMINISTRADOR');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $maxPermissions = app(RolePermissionCatalog::class)->configurable()->count();

        return [
            'name' => ['required', 'string', 'max:100', 'regex:/\A[\pL\pN][\pL\pN ._-]*\z/u'],
            'permission_keys' => ['present', 'array', 'max:'.$maxPermissions],
            'permission_keys.*' => ['required', 'string', 'distinct:strict'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'status' => ['prohibited'], 'is_custom' => ['prohibited'], 'is_system' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['permission_keys.*.distinct' => 'No repitas un permiso.',
            'name.regex' => 'Usa letras, números, espacios, puntos, guiones o guiones bajos para el nombre.'];
    }
}
