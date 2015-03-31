<?php
namespace GuzzleHttp\Promise;

/**
 * A trampoline that executes tasks in a FIFO order.
 *
 * This trampoline class is used to settle promises asynchronously and
 * maintains a constant stack size. You can use the trampoline asynchronously
 * by calling the `step()` function of the global trampoline in an event
 * loop. This will execute the thunks in the trampoline at the time in which
 * the `step()` function is invoked. Use the `run()` function to execute all
 * thunks, even new thunks that are added while running the trampoline.
 *
 *     GuzzleHttp\Promise\trampoline()->step();
 *
 * This class uses a linked list approach in order to efficiently keep track
 * of the first and last nodes in the trampoline. For performance reasons, this
 * is achieved using arrays and references rather than SplDoublyLinkedList or
 * SplQueue.
 */
class Trampoline
{
    private $head;
    private $tail;
    private $enableShutdown = true;

    public function __construct()
    {
        $node = ['next' => null];
        $this->head =& $node ;
        $this->tail =& $node;

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
        return isset($this->head['thunk']);
    }

    /**
     * Adds a thunk to the queue.
     *
     * @param callable $thunk
     */
    public function enqueue(callable $thunk)
    {
        $this->tail['thunk'] = $thunk;
        $next = ['next' => null];
        $this->tail['next'] = &$next;
        $this->tail =& $this->tail['next'];
    }

    /**
     * Execute all of the pending thunks in the trampoline.
     */
    public function run()
    {
        /** @var callable $thunk */
        while (isset($this->head['thunk'])) {
            $thunk = $this->head['thunk'];
            $this->head =& $this->head['next'];
            $thunk();
        }
    }

    /**
     * Execute all of the currently available thunks in the trampoline without
     * executing any thunks adding during this step.
     */
    public function step()
    {
        $currentTail = $this->tail;

        while (isset($this->head['thunk'])) {
            $thunk = $this->head['thunk'];
            $this->head =& $this->head['next'];
            $thunk();
            if ($this->head === $currentTail) {
                break;
            }
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
