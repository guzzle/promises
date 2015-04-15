<?php
namespace GuzzleHttp\Promise;

/**
 * A trampoline that executes tasks in a FIFO order.
 *
 * This trampoline class is used to settle promises asynchronously and
 * maintains a constant stack size. You can use the trampoline asynchronously
 * by calling the `run()` function of the global trampoline in an event loop.
 *
 *     GuzzleHttp\Promise\trampoline()->run();
 */
class Trampoline
{
    private $enableShutdown = true;
    private $queue;

    public function __construct()
    {
        $this->queue = new \SplQueue();
        $this->queue->setIteratorMode(
            \SplQueue::IT_MODE_FIFO | \SplQueue::IT_MODE_DELETE
        );

        register_shutdown_function(function () {
            if ($this->enableShutdown) {
                // Only run the trampolines if an E_ERROR didn't occur.
                $err = error_get_last();
                if (!$err || ($err['type'] ^ E_ERROR)) {
                    $this->run();
                }
            }
        });
    }

    /**
     * Returns true if the trampoline contains any thunks to execute.
     *
     * @return bool
     */
    public function hasThunks()
    {
        return !$this->queue->isEmpty();
    }

    /**
     * Adds a thunk to the queue.
     *
     * @param callable $thunk
     */
    public function enqueue(callable $thunk)
    {
        $this->queue[] = $thunk;
    }

    /**
     * Execute all of the pending thunks in the trampoline.
     */
    public function run()
    {
        foreach ($this->queue as $thunk) {
            $thunk();
        }
    }

    /**
     * The trampoline will be run and exhausted by default when the process
     * exits IFF the exit is not the result of a PHP E_ERROR error.
     *
     * You can disable running the automatic shutdown of the trampoline by
     * calling this function. If you disable the trampoline shutdown process,
     * then you MUST either run the trampoline (as a result of running your
     * event loop or manually using the run() method) or wait on each
     * outstanding promise.
     *
     * Note: This shutdown will occur before any destructors are triggered.
     */
    public function disableShutdown()
    {
        $this->enableShutdown = false;
    }
}
