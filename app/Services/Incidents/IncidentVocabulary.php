<?php

namespace App\Services\Incidents;

class IncidentVocabulary
{
    public const TYPES = ['IDENTIFICATION', 'SUSPECTED_IMPERSONATION', 'INELIGIBILITY', 'WRONG_EXAM', 'ENVIRONMENT'];

    public static function type(string $value): string
    {
        return $value === 'WRONG_EXAM_QR' ? 'WRONG_EXAM' : $value;
    }

    public static function status(string $value): string
    {
        return match ($value) {
            'PENDING' => 'REPORTED', 'REJECTED' => 'REGISTERED', default => $value
        };
    }

    public static function statusSql(): string
    {
        return "CASE incidents.status WHEN 'PENDING' THEN 'REPORTED' WHEN 'REJECTED' THEN 'REGISTERED' ELSE incidents.status END";
    }

    public static function statusLabel(string $value): string
    {
        return match ($value) {
            'REPORTED' => 'Reportada', 'REGISTERED' => 'Registrada', 'OPEN' => 'Abierta',
            'UNDER_REVIEW' => 'En revisión', 'RESOLVED' => 'Resuelta', 'CLOSED' => 'Cerrada', default => $value,
        };
    }
}
