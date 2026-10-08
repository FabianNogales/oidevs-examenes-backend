<?php

namespace App\Http\Requests\Api\V1\Students;

use App\Models\Student;
use App\Models\User;
use App\Services\Auth\LoginUserResolver;
use Closure;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends StoreStudentRequest
{
    public function rules(): array
    {
        /** @var Student $student */
        $student = $this->route('student');
        $rules = parent::rules();
        // SIS/CI excluyen Student; el correo debe excluir su User, con otro ID.
        $rules['sis_code'] = ['bail', 'required', 'string', 'max:255', Rule::unique('students', 'sis_code')->ignore($student->id)];
        $rules['identity_number'] = ['bail', 'required', 'string', 'max:255', 'regex:/\A[0-9]+\z/', Rule::unique('students', 'identity_number')->ignore($student->id)];
        $rules['email'] = [
            'bail', 'required', 'string', 'email', 'max:255',
            function (string $attribute, mixed $value, Closure $fail) use ($student): void {
                if (! app(LoginUserResolver::class)->hasInstitutionalEmailDomain($value)) {
                    $fail('El correo debe pertenecer a un dominio institucional configurado.');
                }

                // Comparación sin mayúsculas, permitiendo conservar el correo propio.
                if (User::whereRaw('LOWER(email) = ?', [$value])->where('id', '<>', $student->user_id)->exists()) {
                    $fail('El correo institucional ya está registrado.');
                }
            },
        ];

        foreach ($rules as &$fieldRules) {
            array_unshift($fieldRules, 'sometimes');
        }
        unset($fieldRules);

        return $rules;
    }
}
