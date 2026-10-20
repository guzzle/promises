<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Thrown when pending work is used from an execution context that does not own
 * it.
 *
 * @see ExecutionContext::setProvider()
 */
class CrossContextException extends \LogicException
{
}
