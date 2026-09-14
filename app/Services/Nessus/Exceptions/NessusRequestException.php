<?php

namespace App\Services\Nessus\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Nessus answered with an unexpected HTTP error (4xx other than auth, 5xx).
 */
class NessusRequestException extends NessusException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function fromResponse(string $method, string $path, Response $response): self
    {
        $error = $response->json('error');
        $detail = is_string($error) && $error !== '' ? ': '.Str::limit($error, 200) : '';

        return new self(
            sprintf('Nessus returned HTTP %d for %s %s%s', $response->status(), $method, $path, $detail),
            $response->status(),
        );
    }
}
