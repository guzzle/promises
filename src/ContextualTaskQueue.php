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

    private static ?self $instance = null;

    private TaskQueueInterface $global;

    private function __construct(TaskQueueInterface $global)
    {
        $this->global = $global;
    }

    /**
     * Returns the given global queue, or a queue that routes tasks to the
     * current execution context when a provider is configured.
     */
    public static function route(TaskQueueInterface $global): TaskQueueInterface
    {
        if (ExecutionContext::provider() === null) {
            return $global;
        }

        if (self::$instance === null) {
            self::$instance = new self($global);
        } else {
            self::$instance->global = $global;
        }

        return self::$instance;
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

        return $context === null ? $this->global : ExecutionContextState::forContext($context)->queue();
    }
}
