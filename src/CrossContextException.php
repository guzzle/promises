<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Thrown in strict execution context mode when pending work is used from an
 * execution context that does not own it.
 *
 * @see ExecutionContext::STRICT
 */
class CrossContextException extends \LogicException
{
}
