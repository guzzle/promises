<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Identifies the execution context, such as a fiber or coroutine, that is
 * currently running.
 *
 * @see ExecutionContext::setProvider()
 */
interface ExecutionContextProviderInterface
{
    /**
     * Returns an object identifying the current execution context, or null
     * when the caller is not inside a managed context.
     *
     * The returned object must be the same instance for every call within one
     * context, must differ between contexts that are alive at the same time,
     * and must become unreachable once the context has ended, so that state
     * keyed by it can be released.
     */
    public function current(): ?object;
}
