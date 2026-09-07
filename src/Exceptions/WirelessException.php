<?php

declare(strict_types=1);

namespace RobertStanciu\Wireless\Exceptions;

use Throwable;

/**
 * Everything this package throws itself, so a caller can tell "I drove the component wrong" from
 * "the component failed" — the latter arrives as whatever the component threw.
 */
interface WirelessException extends Throwable {}
