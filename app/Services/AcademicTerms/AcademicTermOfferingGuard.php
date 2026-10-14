<?php

namespace App\Services\AcademicTerms;

use App\Models\AcademicTerm;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;

class AcademicTermOfferingGuard
{
    public function __construct(private readonly AcademicTermIntegrity $integrity) {}

    public function write(int $academicTermId, Closure $writer): mixed
    {
        // Acquire the same global lock as deactivation before locking/writing offerings.
        return $this->integrity->transaction(function () use ($academicTermId, $writer) {
            $term = AcademicTerm::find($academicTermId);
            if (! $term) {
                throw new HttpResponseException(response()->json(['message' => 'El periodo seleccionado no existe.',
                    'code' => 'VALIDATION_ERROR', 'errors' => ['academic_term_id' => ['Selecciona un periodo existente.']]], 422));
            }
            if ($term->status !== 'ACTIVE') {
                throw new HttpResponseException(response()->json(['message' => 'El periodo seleccionado está inactivo.',
                    'code' => 'ACADEMIC_TERM_INACTIVE', 'errors' => ['academic_term_id' => ['No puedes crear ni reasignar ofertas a un periodo inactivo.']]], 422));
            }

            return $writer($term);
        });
    }
}
