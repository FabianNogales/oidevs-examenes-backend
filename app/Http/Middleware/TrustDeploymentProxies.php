<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TrustDeploymentProxies extends TrustProxies
{
    protected function getTrustedHeaderNames()
    {
        $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX;

        // Local Vite changes Host and supplies the browser's host through xfwd.
        // Vercel documents X-Forwarded-Host as identical to Host; it is redundant there.
        return app()->environment('local') ? $headers | Request::HEADER_X_FORWARDED_HOST : $headers;
    }

    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $configured = config('http_security.trusted_proxies', '*');
        if ($configured === '*') {
            // Preserve current transport handling until ingress can be verified.
            return $this->setTrustedProxyIpAddressesToTheCallingIp($request);
        }

        $proxies = is_array($configured) ? $configured : explode(',', (string) $configured);
        $proxies = array_values(array_filter(array_map('trim', $proxies), fn ($proxy) => $proxy !== ''));
        foreach ($proxies as $proxy) {
            [$ip, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
            $maximum = str_contains($ip, ':') ? 128 : 32;
            if (filter_var($ip, FILTER_VALIDATE_IP) === false
                || ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix < 1 || (int) $prefix > $maximum))) {
                throw new InvalidArgumentException('TRUSTED_PROXIES must contain verified IP addresses or CIDRs.');
            }
        }
        $this->setTrustedProxyIpAddressesToSpecificIps($request, $proxies);
    }
}
