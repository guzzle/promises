<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * An execution context provider that can run work when a context ends.
 *
 * @see ExecutionContext::finish()
 */
interface FinalizingExecutionContextProviderInterface extends ExecutionContextProviderInterface
{
    /**
     * Registers a callback that the runtime invokes, inside the current
     * context, when that context ends.
     *
     * Guzzle calls this at most once for each context, the first time it
     * keeps state for that context.
     *
     * @param callable(): void $finalizer
     */
    public function onFinish(callable $finalizer): void;
}
