<?php

namespace App\Enums;

enum RoleName: string
{
    case ADMINISTRADOR = 'ADMINISTRADOR';
    case DOCENTE = 'DOCENTE';
    case ESTUDIANTE = 'ESTUDIANTE';
}
