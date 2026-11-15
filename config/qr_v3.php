<?php

return [
    // Enables the explicit student QR v3 endpoint. The default QR remains v2.
    'enabled' => (bool) env('EIDA_QR_V3_ENABLED', false),
    'kid' => env('EIDA_QR_V3_KID', ''),
    'issuer' => env('EIDA_QR_V3_ISSUER', ''),
    'audience' => env('EIDA_QR_V3_AUDIENCE', 'eida-control-qr'),
    'max_lifetime_seconds' => 172800,
];
