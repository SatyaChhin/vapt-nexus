<?php

namespace App\Services\Nessus\Exceptions;

use Illuminate\Http\Client\ConnectionException;

/**
 * Nessus could not be reached: refused, timed out, DNS or TLS failure,
 * or the URL is outside the allowed networks.
 */
class NessusConnectionException extends NessusException
{
    public static function fromConnectionError(string $baseUrl, ConnectionException $e): self
    {
        $error = $e->getMessage();

        $reason = match (true) {
            str_contains($error, 'cURL error 60'), str_contains($error, 'SSL certificate') => 'TLS certificate verification failed. For a self-signed lab certificate, disable SSL verification for this server.',
            str_contains($error, 'cURL error 28'), str_contains($error, 'timed out') => 'The connection timed out. Check that the VM is running and reachable on the host-only network.',
            str_contains($error, 'cURL error 7'), str_contains($error, 'Connection refused') => 'The connection was refused. Check that nessusd is running and listening on this port.',
            str_contains($error, 'cURL error 6'), str_contains($error, 'resolve host') => 'The host name could not be resolved.',
            str_contains($error, 'cURL error 35') => 'The TLS handshake failed. Check that the URL uses https:// and the correct port.',
            default => 'The server could not be reached.',
        };

        return new self("Unable to connect to Nessus at {$baseUrl}. {$reason}", previous: $e);
    }
}
