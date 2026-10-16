<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Routes queue operations to the task queue of the current execution context.
 *
 * Work outside managed contexts uses the global task queue.
 *
 * @internal
 */
final class ContextualTaskQueue implements TaskQueueInterface
{
    use NonSerializableTrait;

    private TaskQueueInterface $global;

    public function __construct(TaskQueueInterface $global)
    {
        $this->global = $global;
    }

    public function setGlobal(TaskQueueInterface $global): void
    {
        $this->global = $global;
    }

    public function isEmpty(): bool
    {
        return $this->target()->isEmpty();
    }

    /**
     * @param callable(): void $task
     */
    public function add(callable $task): void
    {
        $this->target()->add($task);
    }

    public function run(): void
    {
        $this->target()->run();
    }

    /**
     * Disables shutdown draining of the global task queue.
     *
     * @see TaskQueue::disableShutdown()
     */
    public function disableShutdown(): void
    {
        if ($this->global instanceof TaskQueue) {
            $this->global->disableShutdown();
        }
    }

    private function target(): TaskQueueInterface
    {
        $context = ExecutionContext::current();

        return $context === null ? $this->global : ExecutionContext::state($context)->queue();
    }
}
