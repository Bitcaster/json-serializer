<?php

namespace Zumba\JsonSerializer\Test\SupportClasses;

use DateTime;

/**
 * Stand-in for Carbon\Carbon (not a dependency of this library): a DateTime
 * subclass carrying a private property plus a magic __set. This is the exact
 * shape that triggered "Cannot access property starting with \0" (Sentry
 * 4BASED-MQ5) — the (array) cast the serializer uses for any DateTimeInterface
 * produces a mangled private-property key ("\0Class\0prop"), and the generic
 * reflection-loop fallback routed that key into __set, which PHP 8 rejects.
 */
class CarbonLikeDateTime extends DateTime
{
    private string $localTranslator = 'en';

    public function __set(string $name, mixed $value): void
    {
        $this->$name = $value;
    }
}
