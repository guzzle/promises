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

    /** @var list<callable(): void> */
    public array $finalizers = [];

    public bool $finishing = false;

    /** Whether the provider has been asked to finish this context. */
    public bool $registered = false;

    private ?TaskQueue $taskQueue = null;

    public function queue(): TaskQueue
    {
        if ($this->taskQueue === null) {
            $this->taskQueue = new TaskQueue(false);
        }

        return $this->taskQueue;
    }

    public function hasPendingWork(): bool
    {
        return $this->finalizers !== []
            || ($this->taskQueue !== null && !$this->taskQueue->isEmpty());
    }
}
