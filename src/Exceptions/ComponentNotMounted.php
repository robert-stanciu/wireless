<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Exceptions;

use RuntimeException;

class ComponentNotMounted extends RuntimeException
{
    public static function for(string $component): self
    {
        return new self(
            "[{$component}] has not been mounted. Call mount() first, or use Wireless::run(), which mounts and finishes for you."
        );
    }
}
