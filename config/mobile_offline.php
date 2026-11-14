<?php

return [
    // Confirm student delivery and Android interoperability before publishing in new packages.
    'qr_v3_issued' => (bool) env('MOBILE_OFFLINE_QR_V3_ISSUED', false),
];
