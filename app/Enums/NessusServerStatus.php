<?php

namespace App\Enums;

enum NessusServerStatus: string
{
    /** Never tested. */
    case Unknown = 'unknown';

    /** Reachable, ready and the API keys were accepted. */
    case Connected = 'connected';

    /** Reachable and the keys work, but Nessus is still loading plugins. */
    case NotReady = 'not_ready';

    /** Reachable, but Nessus rejected the API keys. */
    case Unauthorized = 'unauthorized';

    /** Not reachable, TLS failure or unexpected response. */
    case Failed = 'failed';
}
