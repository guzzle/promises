<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Keeps promise work isolated between execution contexts, such as fibers or
 * coroutines, that interleave in one process.
 *
 * Without a provider, Guzzle behaves as if the whole process were a single
 * context.
 *
 * @see https://github.com/guzzle/promises/blob/3.0/docs/execution-contexts.md
 */
final class ExecutionContext
{
    private static ?ExecutionContextProviderInterface $provider = null;

    private function __construct()
    {
    }

    /**
     * Sets the provider that identifies the current execution context, or
     * removes it when null is given.
     *
     * With a provider configured, Guzzle keeps a task queue for each context
     * and rejects use of a pending promise from a context that does not own it
     * with a CrossContextException.
     *
     * Configure the provider once while bootstrapping the process, before
     * promises are created. Removing or replacing the provider discards the
     * state kept for existing contexts. Providers require PHP 8.0 or newer.
     */
    public static function setProvider(?ExecutionContextProviderInterface $provider): void
    {
        if ($provider !== null && \PHP_VERSION_ID < 80000) {
            throw new \RuntimeException('Execution context providers require PHP 8.0 or newer.');
        }

        self::$provider = $provider;
        ExecutionContextState::reset($provider !== null);
    }

    /**
     * Returns the configured provider, if any.
     */
    public static function provider(): ?ExecutionContextProviderInterface
    {
        return self::$provider;
    }

    /**
     * Returns the object identifying the current execution context, or null
     * when no provider is configured or the caller is outside managed
     * contexts.
     */
    public static function current(): ?object
    {
        return self::$provider === null ? null : self::$provider->current();
    }

    /**
     * Registers a callback to run when the current execution context is
     * finished.
     *
     * Finalizers run before the context's task queue is drained, so work they
     * cancel can still run its rejection callbacks in the same context.
     *
     * @param callable(): void $finalizer
     */
    public static function onFinish(callable $finalizer): void
    {
        $context = self::current();

        if ($context === null) {
            throw new \LogicException('Cannot register a finalizer outside a managed execution context.');
        }

        ExecutionContextState::forContext($context)->finalizers[] = $finalizer;
    }

    /**
     * Runs the given context's finalizers and drains its task queue.
     *
     * Runtimes call this when a context ends, unless their provider registers
     * finalizers through FinalizingExecutionContextProviderInterface. Call it
     * from inside the context being finished, so that callbacks keep running
     * in the context that owns them. Calling it again for the same context is
     * harmless.
     *
     * Once a context is finished, callbacks that other contexts queue for it
     * are dropped until it uses promises again, for example in a later
     * shutdown callback. Callbacks already dropped are not replayed.
     */
    public static function finish(object $context): void
    {
        $state = ExecutionContextState::find($context);

        if ($state !== null) {
            $state->finish();
        }
    }
}
