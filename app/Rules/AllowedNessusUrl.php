<?php

namespace App\Rules;

use App\Services\Nessus\NessusUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedNessusUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (($error = NessusUrlGuard::check($value)) !== null) {
            $fail($error);
        }
    }
}
