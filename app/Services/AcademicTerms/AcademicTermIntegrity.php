<?php

namespace App\Services\AcademicTerms;

use App\Models\AcademicTerm;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AcademicTermIntegrity
{
    public function __construct(private readonly AcademicTermLock $lock) {}

    public function transaction(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            $this->lock->acquire();

            return $operation();
        }, 3);
    }

    public function validatePayload(array $data, bool $creating): array
    {
        $originalName = $data['name'] ?? null;
        if (is_string($originalName)) {
            $data['name'] = trim($originalName);
        }
        $date = function ($attribute, $value, $fail) {
            if (! is_string($value) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
                || (int) $parts[1] < 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $fail('Usa una fecha calendario válida YYYY-MM-DD entre los años 0001 y 9999.');
            }
        };
        $validator = Validator::make($data, [
            'name' => ['bail', 'required', 'string', 'min:2', 'max:255', 'regex:/[\p{L}\p{N}]/u', 'not_regex:/\p{Cc}/u'],
            'start_date' => ['bail', 'required', 'string', $date],
            'end_date' => ['bail', 'required', 'string', $date],
        ] + ($creating ? ['status' => ['required', 'string', Rule::in(['ACTIVE', 'INACTIVE'])]] : []), [
            'name.*' => 'El nombre debe tener entre 2 y 255 caracteres, contener letras o números y no incluir controles.',
            'start_date.required' => 'La fecha de inicio es obligatoria.',
            'end_date.required' => 'La fecha de fin es obligatoria.',
            'start_date.string' => 'La fecha de inicio debe ser un texto YYYY-MM-DD.',
            'end_date.string' => 'La fecha de fin debe ser un texto YYYY-MM-DD.',
            'status.*' => 'El estado es obligatorio y debe ser ACTIVE o INACTIVE.',
        ]);
        $validator->after(function ($validator) use ($data, $creating, $originalName) {
            foreach (array_diff(array_keys($data), $creating ? ['name', 'start_date', 'end_date', 'status'] : ['name', 'start_date', 'end_date']) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido en esta operación.');
            }
            if (is_string($originalName) && preg_match('/\p{Cc}/u', $originalName)) {
                $validator->errors()->add('name', 'El nombre no puede contener caracteres de control.');
            }
            if (! $validator->errors()->has('start_date') && ! $validator->errors()->has('end_date')
                && ($data['end_date'] ?? '') < ($data['start_date'] ?? '')) {
                $validator->errors()->add('end_date', 'La fecha de fin no puede ser anterior a la fecha de inicio.');
            }
        });
        if ($validator->fails()) {
            $this->reject(422, 'VALIDATION_ERROR', 'Revisa los campos indicados.', $validator->errors()->toArray());
        }

        return $validator->validated();
    }

    public function assertCanSave(array $data, ?int $id = null): ?AcademicTerm
    {
        $this->lock->acquire();
        $current = $id === null ? null : $this->find($id);
        $others = AcademicTerm::query();
        if ($id !== null) {
            $others->where('id', '!=', $id);
        }
        $normalization = DB::getDriverName() === 'pgsql' ? 'lower(btrim(name))' : 'lower(trim(name))';
        if ((clone $others)->whereRaw($normalization.' = ?', [mb_strtolower(trim($data['name']))])->exists()) {
            $this->reject(422, 'ACADEMIC_TERM_NAME_DUPLICATE', 'Revisa los campos indicados.', ['name' => ['Ya existe un periodo con este nombre.']]);
        }
        $overlap = (clone $others)->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->orderBy('id')->first();
        if ($overlap) {
            $message = 'Las fechas se solapan con '.$overlap->name.'.';
            $this->reject(422, 'ACADEMIC_TERM_OVERLAP', 'Revisa los campos indicados.', ['start_date' => [$message], 'end_date' => [$message]], $overlap);
        }
        if (($current?->status ?? $data['status'] ?? 'INACTIVE') === 'ACTIVE') {
            $this->assertNoOtherActive($id);
        }

        return $current;
    }

    public function assertCanChangeStatus(int $id, string $status): AcademicTerm
    {
        $this->lock->acquire();
        if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $this->reject(422, 'VALIDATION_ERROR', 'Revisa los campos indicados.', ['status' => ['El estado debe ser ACTIVE o INACTIVE.']]);
        }
        $term = $this->find($id);
        if ($status === 'ACTIVE') {
            $this->assertNoOtherActive($id);
        }

        return $term;
    }

    private function find(int $id): AcademicTerm
    {
        $term = AcademicTerm::find($id);
        if (! $term) {
            $this->reject(404, 'NOT_FOUND', 'El periodo solicitado no existe.', []);
        }

        return $term;
    }

    private function assertNoOtherActive(?int $id): void
    {
        $query = AcademicTerm::where('status', 'ACTIVE');
        if ($id !== null) {
            $query->where('id', '!=', $id);
        }
        if ($conflict = $query->orderBy('id')->first()) {
            $this->reject(409, 'ACADEMIC_TERM_ACTIVE_CONFLICT', 'Desactiva '.$conflict->name.' antes de activar otro periodo.',
                ['status' => ['Ya existe un periodo activo.']], $conflict);
        }
    }

    private function reject(int $status, string $code, string $message, array $errors, ?AcademicTerm $conflict = null): never
    {
        $response = compact('message', 'code', 'errors');
        if ($conflict) {
            $response['conflicting_period'] = ['id' => (int) $conflict->id, 'name' => $conflict->name,
                'start_date' => $conflict->start_date->toDateString(), 'end_date' => $conflict->end_date->toDateString(), 'status' => $conflict->status];
        }
        throw new HttpResponseException(response()->json($response, $status));
    }
}
