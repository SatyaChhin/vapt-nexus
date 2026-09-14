<?php

namespace App\Services\Nessus;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Restricts Nessus server URLs to the networks in config('nessus.allowed_networks').
 * The application makes server-side requests to these URLs, so this keeps it
 * from being pointed at systems outside the lab.
 */
class NessusUrlGuard
{
    /**
     * @param  list<string>|null  $allowedNetworks  Defaults to config('nessus.allowed_networks').
     * @return string|null An error message, or null when the URL is acceptable.
     */
    public static function check(string $url, ?array $allowedNetworks = null): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'The Nessus URL is not a valid URL.';
        }

        if (! in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            return 'The Nessus URL must start with https:// (or http://).';
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'The Nessus URL must not contain credentials, a query string or a fragment.';
        }

        if (isset($parts['path']) && $parts['path'] !== '/') {
            return 'The Nessus URL must not contain a path. Use e.g. https://192.168.56.10:8834';
        }

        $networks = $allowedNetworks ?? config('nessus.allowed_networks', []);

        if ($networks === []) {
            return null;
        }

        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            return "The host {$host} could not be resolved.";
        }

        foreach ($addresses as $address) {
            if (! IpUtils::checkIp($address, $networks)) {
                return sprintf(
                    'The Nessus URL must point to an allowed network (%s). Adjust NESSUS_ALLOWED_NETWORKS to change this.',
                    implode(', ', $networks),
                );
            }
        }

        return null;
    }
}
