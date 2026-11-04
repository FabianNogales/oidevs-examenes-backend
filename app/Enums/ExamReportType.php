<?php

namespace App\Enums;

enum ExamReportType: string
{
    case ENROLLED = 'ENROLLED';
    case ATTENDANCE = 'ATTENDANCE';
    case PENDING_ABSENT = 'PENDING_ABSENT';
    case INCIDENTS = 'INCIDENTS';
    case DISCIPLINARY = 'DISCIPLINARY';

    public function label(): string
    {
        return match ($this) {
            self::ENROLLED => 'Estudiantes inscritos', self::ATTENDANCE => 'Asistencia confirmada',
            self::PENDING_ABSENT => 'Pendientes o ausentes', self::INCIDENTS => 'Incidencias e ingresos irregulares',
            self::DISCIPLINARY => 'Infracciones y expulsiones',
        };
    }
}
