<?php

namespace App\Enums;

/**
 * Nessus risk factor levels (plugin "severity" 0-4).
 */
enum Severity: int
{
    case Info = 0;
    case Low = 1;
    case Medium = 2;
    case High = 3;
    case Critical = 4;

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }

    /**
     * Lowercase name used in JSON and count columns, e.g. "critical".
     */
    public function key(): string
    {
        return strtolower($this->name);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (self $s) => $s->key(), array_reverse(self::cases()));
    }
}
