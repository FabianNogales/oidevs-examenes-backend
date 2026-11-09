<?php

namespace App\Services\QrV3;

use RuntimeException;

class QrV3Exception extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('QR v3: '.$reason);
    }
}
