<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Treats each native PHP fiber as an execution context.
 *
 * Code running outside any fiber is not inside a managed context. Fibers do
 * not notify Guzzle when they end, so schedulers should call
 * ExecutionContext::finish(\Fiber::getCurrent()) as the last step inside each
 * fiber, because finishing a context runs its callbacks in that context.
 *
 * Requires PHP 8.1 or newer.
 */
final class FiberExecutionContextProvider implements ExecutionContextProviderInterface
{
    public function __construct()
    {
        if (\PHP_VERSION_ID < 80100) {
            throw new \RuntimeException('The fiber execution context provider requires PHP 8.1 or newer.');
        }
    }

    public function current(): ?object
    {
        return \Fiber::getCurrent();
    }
}
