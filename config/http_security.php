<?php

return [
    // Compatibility fallback until the deployed Vercel ingress/runtime is verified.
    // Never treat this fallback as a verified proxy allowlist. See SEC_01_SECURITY_FIXES.md.
    // A configured empty value trusts no proxies; otherwise use verified IPs/CIDRs only.
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),
];
