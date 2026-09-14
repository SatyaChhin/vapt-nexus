<?php

namespace App\Services\Nessus\Exceptions;

/**
 * Nessus answered 401/403: the API keys are invalid, revoked or lack permission.
 */
class NessusAuthenticationException extends NessusException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Nessus rejected the API keys (HTTP '.$status.'). Generate new keys in Nessus under Settings > My Account > API Keys.');
    }
}
