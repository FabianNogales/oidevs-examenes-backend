<?php

namespace App\Http\Requests\Api\V1\Subjects;

class ConfirmSubjectImportRequest extends PreviewSubjectImportRequest
{
    public function rules(): array
    {
        return parent::rules() + ['preview_id' => ['required', 'string', 'uuid']];
    }

    public function messages(): array
    {
        return parent::messages() + ['preview_id.*' => 'Envía el identificador UUID de una vista previa válida.'];
    }
}
