<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Exceptions;

use RuntimeException;

class AlreadyMounted extends RuntimeException
{
    public static function for(string $component): self
    {
        return new self(
            "[{$component}] is already mounted. Finish that cycle before mounting another, or use a second driver."
        );
    }
}
