<?php

namespace App\Enums;

enum InfractionType: string
{
    case COPYING = 'COPYING';
    case CHEATING = 'CHEATING';
    case IMPERSONATION = 'IMPERSONATION';
}
