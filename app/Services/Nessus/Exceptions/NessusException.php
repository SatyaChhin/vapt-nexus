<?php

namespace App\Services\Nessus\Exceptions;

use RuntimeException;

/**
 * Base class for Nessus API failures. Messages are safe to show to users:
 * they never contain API keys or request headers.
 */
abstract class NessusException extends RuntimeException {}
