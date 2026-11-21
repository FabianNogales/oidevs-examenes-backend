<?php

namespace App\Http\Requests\Api\V1\Portal;

use App\Enums\RoleName;
use App\Services\Portal\PortalEditing;
use Illuminate\Validation\Validator;

trait RestrictsDelegatedPortalFields
{
    public function withValidator(Validator $validator): void
    {
        if ($this->user()?->hasRole(RoleName::ADMINISTRADOR)) {
            return;
        }
        $validator->after(function (Validator $validator) {
            foreach (PortalEditing::forbiddenFields(PortalEditing::section($this), $this->all()) as $field) {
                $validator->errors()->add($field, 'Este campo no está autorizado para la edición de esta sección.');
            }
        });
    }
}
