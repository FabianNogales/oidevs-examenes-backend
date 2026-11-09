<?php

return [
    // Explicit, bounded lifetime; offline queues survive token expiration.
    'token_ttl_minutes' => (int) env('EIDA_MOBILE_TOKEN_TTL_MINUTES', 720),
];
