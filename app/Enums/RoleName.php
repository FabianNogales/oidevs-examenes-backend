<?php

namespace App\Enums;

enum RoleName: string
{
    case ADMINISTRADOR = 'ADMINISTRADOR';
    case DOCENTE = 'DOCENTE';
    case ESTUDIANTE = 'ESTUDIANTE';

    /** Known permanent names only; mirrors SQL UPPER(TRIM(roles.name)). */
    public static function fromStoredName(string $name): ?self
    {
        return self::tryFrom(strtoupper(trim($name, ' ')));
    }
}
