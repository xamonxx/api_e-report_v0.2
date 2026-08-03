<?php

namespace App\Support;

class PushEndpoint
{
    public static function isAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        $allowedHosts = array_map(
            fn (string $value) => strtolower(rtrim($value, '.')),
            config('webpush.allowed_endpoint_hosts', [])
        );
        if (in_array($host, $allowedHosts, true)) {
            return true;
        }

        foreach (config('webpush.allowed_endpoint_suffixes', []) as $suffix) {
            $normalizedSuffix = '.'.ltrim(strtolower(rtrim((string) $suffix, '.')), '.');
            if ($normalizedSuffix !== '.' && str_ends_with($host, $normalizedSuffix)) {
                return true;
            }
        }

        return false;
    }
}
