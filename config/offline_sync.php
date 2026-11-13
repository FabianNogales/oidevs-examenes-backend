<?php

return [
    // Verification only; does not enable issuance or modify online QR v2.
    'qr_v3_verify_enabled' => (bool) env('EIDA_OFFLINE_SYNC_QR_V3_VERIFY', false),
];
