<?php

return [
    // Semantic keys only: frontend renders its own icons, never supplied HTML/SVG.
    'icon_keys' => ['academic', 'calendar', 'book', 'info', 'link', 'users'],

    // Exact domain or dot-delimited subdomain; never a substring match.
    'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', env('EIDA_SERVICE_ALLOWED_DOMAINS', 'umss.edu.bo'))))),
    'allow_subdomains' => env('EIDA_SERVICE_ALLOW_SUBDOMAINS', true),
];
