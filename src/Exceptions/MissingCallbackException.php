<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Exceptions;

use InvalidArgumentException;

class MissingCallbackException extends InvalidArgumentException implements WirelessException
{
    public static function make(): self
    {
        return new self(
            'Wireless::run() needs a callback to hand the component to — pass it as the second argument when there are no mount parameters.'
        );
    }
}
