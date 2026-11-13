<?php

return [
    // Enable ONLY after the production issuer actually emits QR v3. Does not enable issuance.
    'qr_v3_issued' => env('MOBILE_OFFLINE_QR_V3_ISSUED', false),
];
