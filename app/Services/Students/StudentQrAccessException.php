<?php

namespace App\Services\Students;

use RuntimeException;

class StudentQrAccessException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'EXAM_UNAVAILABLE' => 'El examen no está disponible para este estudiante.',
            'STUDENT_NOT_ELIGIBLE' => 'El estudiante no se encuentra habilitado para este examen.',
            'QR_NOT_YET_AVAILABLE' => 'El código QR aún no está disponible. Se habilitará 24 horas antes del examen.',
            'EXAM_FINISHED' => 'El examen ya ha finalizado.',
            default => 'No se puede emitir el código QR para este examen.',
        });
    }
}
