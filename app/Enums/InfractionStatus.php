<?php

namespace App\Enums;

enum InfractionStatus: string
{
    case REPORTED = 'REPORTED';
    case CONFIRMED = 'CONFIRMED';
    case RESOLVED = 'RESOLVED';
}
