<?php

namespace App\Support;

class BrowserOrigins
{
    public static function allowed(?string $configured, ?string $frontend, bool $local): array
    {
        $values = [...explode(',', $configured ?? ''), $frontend ?? ''];
        if ($local) {
            $values = [...$values, 'http://127.0.0.1:5173', 'http://localhost:5173'];
        }

        return array_values(array_unique(array_filter(array_map(self::normalize(...), $values))));
    }

    public static function stateful(?string $configured, array $origins): array
    {
        if ($configured === null) {
            return array_values(array_unique(array_map(fn ($origin) => substr($origin, strpos($origin, '://') + 3), $origins)));
        }

        $domains = [];
        foreach (explode(',', $configured) as $domain) {
            $domain = trim($domain);
            if (in_array(strtolower($domain), ['', 'null', '*'], true)) {
                continue;
            }
            $origin = self::normalize('http://'.$domain);
            if ($domain !== '' && $origin !== null && substr($origin, 7) === strtolower($domain)) {
                $domains[] = strtolower($domain);
            }
        }

        return array_values(array_unique($domains));
    }

    private static function normalize(string $value): ?string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || str_contains($parts['host'], '*') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
