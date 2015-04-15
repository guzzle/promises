<?php
namespace GuzzleHttp\Promise;

/**
 * Promises/A+ implementation that avoids recursion when possible.
 *
 * @link https://promisesaplus.com/
 */
class Promise implements PromiseInterface
{
    private $state = self::PENDING;
    private $result;
    private $cancelFn;
    private $waitFn;
    private $waitList;
    private $handlers = [];

    /**
     * @param callable $waitFn   Fn that when invoked resolves the promise.
     * @param callable $cancelFn Fn that when invoked cancels the promise.
     */
    public function __construct(
        callable $waitFn = null,
        callable $cancelFn = null
    ) {
        $this->waitFn = $waitFn;
        $this->cancelFn = $cancelFn;
    }

    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null
    ) {
        if ($this->state === self::PENDING) {
            return $this->createPendingThen($onFulfilled, $onRejected);
        }

        // Return a fulfilled promise and immediately invoke any callbacks.
        if ($this->state === self::FULFILLED) {
            return $onFulfilled
                ? promise_for($this->result)->then($onFulfilled)
                : promise_for($this->result);
        }

        // It's either cancelled or rejected, so return a rejected promise
        // and immediately invoke any callbacks.
        $rejection = rejection_for($this->result);
        return $onRejected ? $rejection->then(null, $onRejected) : $rejection;
    }

    public function otherwise(callable $onRejected)
    {
        return $this->then(null, $onRejected);
    }

    public function wait($unwrap = true)
    {
        // Wait on nested promises until a normal value is unwrapped/thrown.
        return $this->waitType($unwrap, true);
    }

    public function getState()
    {
        return $this->state;
    }

    public function cancel()
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        $this->waitFn = null;
        $this->waitList = null;

        if ($this->cancelFn) {
            $fn = $this->cancelFn;
            $this->cancelFn = null;
            try {
                $fn();
            } catch (\Exception $e) {
                $this->reject($e);
            }
        }

        // Reject the promise only if it wasn't rejected in a then callback.
        if ($this->state === self::PENDING) {
            $this->reject(new CancellationException('Promise has been cancelled'));
        }
    }

    public function resolve($value)
    {
        $this->settle(self::FULFILLED, $value);
    }

    public function reject($reason)
    {
        $this->settle(self::REJECTED, $reason);
    }

    private function settle($state, $value)
    {
        if ($this->state !== self::PENDING) {
            throw $this->state === $state
                ? new \RuntimeException("The promise is already {$state}.")
                : new \RuntimeException("Cannot change a {$this->state} promise to {$state}");
        }

        if ($value === $this) {
            throw new \LogicException('Cannot fulfill or reject a promise with itself');
        }

        // Clear out the state of the promise but stash the handlers.
        $this->state = $state;
        $this->result = $value;
        $handlers = $this->handlers;
        $this->handlers = null;
        $this->waitList = null;
        $this->waitFn = null;
        $this->cancelFn = null;

        if (!$handlers) {
            return;
        }

        if ($value instanceof Promise) {
            $nextState = $value->getState();
            if ($nextState === self::PENDING) {
                // We can just merge our handlers onto the next promise.
                $value->handlers = array_merge($value->handlers, $handlers);
                return;
            }
        } elseif (!method_exists($value, 'then')) {
            // The value was not a settled promise or a thenable, so resolve it
            // in the next trampoline using the correct ID.
            $id = $state === self::FULFILLED ? 1 : 2;
            // It's a success, so resolve the handlers in the trampoline.
            trampoline()->schedule(static function () use ($id, $value, $handlers) {
                foreach ($handlers as $handler) {
                    self::callHandler($id, $value, $handler);
                }
            });
            return;
        }

        // Resolve the handlers when the forwarded promise is resolved.
        $value->then(
            static function ($value) use ($handlers) {
                trampoline()->schedule(function () use ($handlers, $value) {
                    foreach ($handlers as $handler) {
                        self::callHandler(1, $value, $handler);
                    }
                });
            },
            static function ($reason) use ($handlers) {
            trampoline()->schedule(function () use ($handlers, $reason) {
                foreach ($handlers as $handler) {
                    self::callHandler(2, $reason, $handler);
                }
            });
        });
    }

    private function createPendingThen(
        callable $onFulfilled = null,
        callable $onRejected = null
    ) {
        $p = new Promise(null, [$this, 'cancel']);
        $this->handlers[] = [$p, $onFulfilled, $onRejected];
        if ($this->waitList) {
            $p->waitList = clone $this->waitList;
        } else {
            $p->waitList = new \SplQueue();
            $p->waitList->setIteratorMode(\SplQueue::IT_MODE_FIFO | \SplQueue::IT_MODE_DELETE);
        }
        $p->waitList[] = $this;

        return $p;
    }

    /**
     * Call a stack of handlers using a specific callback index and value.
     *
     * @param int   $index   1 (resolve) or 2 (reject).
     * @param mixed $value   Value to pass to the callback.
     * @param array $handler Array of handler data (promise and callbacks).
     *
     * @return array Returns the next group to resolve.
     */
    private static function callHandler($index, $value, array $handler)
    {
        /** @var PromiseInterface $promise */
        $promise = $handler[0];

        // The promise may have been cancelled or resolved before placing
        // this thunk in the trampoline.
        if ($promise->getState() !== self::PENDING) {
            return;
        }

        try {
            if (isset($handler[$index])) {
                $promise->resolve($handler[$index]($value));
            } elseif ($index === 1) {
                // Forward resolution values as-is.
                $promise->resolve($value);
            } else {
                // Forward rejections down the chain.
                $promise->reject($value);
            }
        } catch (\Exception $reason) {
            $promise->reject($reason);
        }
    }

    private function waitType($unwrap, $deep)
    {
        if ($this->state === self::PENDING) {
            if ($this->waitFn || $this->waitList) {
                $this->invokeWait();
            } else {
                // If there's not wait function, then reject the promise.
                $this->reject('Cannot wait on a promise that has '
                    . 'no internal wait function. You must provide a wait '
                    . 'function when constructing the promise to be able to '
                    . 'wait on a promise.');
            }
        }

        // If there's no promise forwarding, then return/throw what we have.
        if (!($this->result instanceof PromiseInterface)) {
            if (!$unwrap) {
                return null;
            } elseif ($this->state === self::FULFILLED) {
                return $this->result;
            }
            // It's rejected so "unwrap" and throw an exception.
            throw exception_for($this->result);
        }

        return $deep ? $this->result->wait($unwrap) : $this->result;
    }

    private function invokeWait()
    {
        $wfn = $this->waitFn;
        $this->waitFn = null;

        try {
            if ($wfn) {
                $wfn(true);
            } else {
                // This will invoke the wait functions in the list iteratively
                // without recursing into promises when waiting on forwarded
                // promise values.
                foreach ($this->waitList as $p) {
                    $result = $p->waitType(false, false);
                    if ($result instanceof PromiseInterface) {
                        $this->waitList[] = $result;
                    }
                }
            }
            trampoline()->run();
        } catch (\Exception $reason) {
            if ($this->state === self::PENDING) {
                // The promise has not been resolved yet, so reject the promise
                // with the exception.
                $this->reject($reason);
            } else {
                // The promise was already resolved, so there's a problem in
                // the application.
                throw $reason;
            }
        }

        if ($this->state === self::PENDING) {
            $this->reject('Invoking the wait callback did not resolve the promise');
        }
    }
}
