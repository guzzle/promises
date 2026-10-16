<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * State kept for one execution context.
 *
 * @internal
 */
final class ExecutionContextState
{
    use NonSerializableTrait;

    /** @var \WeakMap<object, ExecutionContextState>|null */
    private static $states;

    /** @var list<callable(): void> */
    public array $finalizers = [];

    /** Whether the provider has been asked to finish this context. */
    public bool $registered = false;

    private bool $finishing = false;

    /** Whether the context has been finished, so work queued for it is dropped. */
    private bool $finished = false;

    private ?TaskQueue $taskQueue = null;

    /**
     * Discards the state kept for every context, and keeps state from now on
     * only when execution contexts are enabled.
     */
    public static function reset(bool $enabled): void
    {
        self::$states = $enabled ? new \WeakMap() : null;
    }

    /**
     * Returns the state for a context, creating it on first use.
     *
     * A finalizing provider registers its finalizer for the context that is
     * running, so the finalizer is registered the first time the state is
     * used from inside its own context. A finished context that uses its state
     * again gets new state, so it is finished again.
     */
    public static function forContext(object $context): self
    {
        $state = self::find($context);
        $running = $context === ExecutionContext::current();

        if ($state === null || ($state->finished && $running)) {
            $state = new self();
            self::$states[$context] = $state;
        }

        if ($state->registered || !$running) {
            return $state;
        }

        $state->registered = true;
        $provider = ExecutionContext::provider();

        if ($provider instanceof FinalizingExecutionContextProviderInterface) {
            $reference = \WeakReference::create($context);
            $provider->onFinish(static function () use ($reference): void {
                $context = $reference->get();

                if ($context !== null) {
                    ExecutionContext::finish($context);
                }
            });
        }

        return $state;
    }

    /**
     * Returns the state kept for a context, if any.
     */
    public static function find(object $context): ?self
    {
        return self::$states[$context] ?? null;
    }

    /**
     * Returns the task queue of the given context, which may be a context
     * other than the current one, or null when the context has been finished
     * and has not used promises since, so work queued for it is dropped.
     */
    public static function queueFor(object $context): ?TaskQueue
    {
        $state = self::forContext($context);

        return $state->finished ? null : $state->queue();
    }

    public function queue(): TaskQueue
    {
        if ($this->taskQueue === null) {
            $this->taskQueue = new TaskQueue(false);
        }

        return $this->taskQueue;
    }

    /**
     * Runs the context's finalizers and drains its task queue until neither
     * has work left, then rethrows the first failure.
     */
    public function finish(): void
    {
        if ($this->finishing) {
            return;
        }

        $this->finishing = true;
        $failure = null;

        try {
            do {
                while ($finalizer = array_shift($this->finalizers)) {
                    try {
                        $finalizer();
                    } catch (\Throwable $e) {
                        $failure = $failure ?? $e;
                    }
                }

                try {
                    $this->queue()->run();
                } catch (\Throwable $e) {
                    $failure = $failure ?? $e;
                }
            } while ($this->hasPendingWork());
        } finally {
            $this->finished = true;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function hasPendingWork(): bool
    {
        return $this->finalizers !== []
            || ($this->taskQueue !== null && !$this->taskQueue->isEmpty());
    }
}
