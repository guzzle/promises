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
    /**
     * Route task queues per context, without restricting cross-context use of
     * pending promises.
     */
    public const ISOLATE = 1;

    /**
     * Route task queues per context, and reject cross-context use of pending
     * promises with a CrossContextException.
     */
    public const STRICT = 2;

    private static ?ExecutionContextProviderInterface $provider = null;

    private static int $mode = self::ISOLATE;

    /** @var \WeakMap<object, ExecutionContextState>|null */
    private static $states;

    private static ?ContextualTaskQueue $queue = null;

    private function __construct()
    {
    }

    /**
     * Sets the provider that identifies the current execution context, or
     * removes it when null is given.
     *
     * Configure the provider once while bootstrapping the process, before
     * promises are created. Removing or replacing the provider discards the
     * state kept for existing contexts. Providers require PHP 8.0 or newer.
     *
     * @param int $mode ExecutionContext::ISOLATE or ExecutionContext::STRICT.
     */
    public static function setProvider(?ExecutionContextProviderInterface $provider, int $mode = self::ISOLATE): void
    {
        if ($mode !== self::ISOLATE && $mode !== self::STRICT) {
            throw new \InvalidArgumentException('The execution context mode must be ExecutionContext::ISOLATE or ExecutionContext::STRICT.');
        }

        if ($provider !== null && \PHP_VERSION_ID < 80000) {
            throw new \RuntimeException('Execution context providers require PHP 8.0 or newer.');
        }

        self::$provider = $provider;
        self::$mode = $mode;
        self::$states = $provider === null ? null : new \WeakMap();
        self::$queue = null;
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
     * Determines if cross-context use of pending work is rejected.
     */
    public static function isStrict(): bool
    {
        return self::$mode === self::STRICT && self::$provider !== null;
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

        self::state($context)->finalizers[] = $finalizer;
    }

    /**
     * Runs the given context's finalizers and drains its task queue.
     *
     * Runtimes call this when a context ends, unless their provider registers
     * finalizers through FinalizingExecutionContextProviderInterface. Call it
     * from inside the context being finished, so that callbacks keep running
     * in the context that owns them. Calling it again for the same context is
     * harmless.
     */
    public static function finish(object $context): void
    {
        $state = self::$states[$context] ?? null;

        if ($state === null || $state->finishing) {
            return;
        }

        $state->finishing = true;
        $failure = null;

        try {
            do {
                while ($finalizer = array_shift($state->finalizers)) {
                    try {
                        $finalizer();
                    } catch (\Throwable $e) {
                        $failure = $failure ?? $e;
                    }
                }

                try {
                    $state->queue()->run();
                } catch (\Throwable $e) {
                    $failure = $failure ?? $e;
                }
            } while ($state->hasPendingWork());
        } finally {
            if (self::$states !== null) {
                unset(self::$states[$context]);
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Returns the task queue for the current execution context.
     *
     * @internal
     */
    public static function queue(TaskQueueInterface $global): TaskQueueInterface
    {
        if (self::$provider === null) {
            return $global;
        }

        if (self::$queue === null) {
            self::$queue = new ContextualTaskQueue($global);
        } else {
            self::$queue->setGlobal($global);
        }

        return self::$queue;
    }

    /**
     * Returns the task queue of the given execution context, which may be a
     * context other than the current one.
     *
     * @internal
     */
    public static function queueFor(object $context): TaskQueue
    {
        return self::state($context)->queue();
    }

    /**
     * Returns the state for a context, creating it on first use.
     *
     * A finalizing provider registers its finalizer for the context that is
     * running, so the finalizer is registered the first time the state is
     * used from inside its own context.
     *
     * @internal
     */
    public static function state(object $context): ExecutionContextState
    {
        $state = self::$states[$context] ?? null;

        if ($state === null) {
            $state = new ExecutionContextState();
            self::$states[$context] = $state;
        }

        if ($state->registered || $context !== self::current()) {
            return $state;
        }

        $state->registered = true;

        if (self::$provider instanceof FinalizingExecutionContextProviderInterface) {
            $reference = \WeakReference::create($context);
            self::$provider->onFinish(static function () use ($reference): void {
                $context = $reference->get();

                if ($context !== null) {
                    ExecutionContext::finish($context);
                }
            });
        }

        return $state;
    }
}
