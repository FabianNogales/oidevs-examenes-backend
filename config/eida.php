<?php

return [
    'institutional_email_domains' => array_values(array_filter(array_map(
        fn (string $domain): string => strtolower(trim($domain)),
        explode(',', env('EIDA_INSTITUTIONAL_EMAIL_DOMAINS', '')),
    ))),
];
