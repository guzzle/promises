<?php
namespace GuzzleHttp\Promise;

/**
 * Promises/A+ implementation that avoids recursion when possible.
 *
 * In order to benefit from iterative promises, you MUST extend from this
 * promise so that promises can reach into each others' private properties to
 * shift handler ownership.
 *
 * @link https://promisesaplus.com/
 */
class Promise implements PromiseInterface
{
    private $state = self::PENDING;
    private $handlers = [];
    private $waitFn;
    private $cancelFn;
    private $result;

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
            $p = new Promise([$this, 'wait'], [$this, 'cancel']);
            // Keep track of this dependent promise so that we resolve it
            // later when a value has been delivered.
            $this->handlers[] = [$p, $onFulfilled, $onRejected];
            return $p;
        }

        // Return a fulfilled promise and immediately invoke any callbacks.
        if ($this->state === self::FULFILLED) {
            return $onFulfilled
                ? promise_for($this->result)->then($onFulfilled)
                : promise_for($this->result);
        }

        // It's either cancelled or rejected, so return a rejected promise
        // and immediately invoke any callbacks.
        if ($onRejected) {
            return (new RejectedPromise($this->result))->then(null, $onRejected);
        }

        return new RejectedPromise($this->result);
    }

    public function wait($unwrap = true)
    {
        if ($this->state === self::PENDING) {
            $this->cancelFn = null;
            if ($this->waitFn) {
                $this->invokeWait();
            } else {
                // If there's not wait function, then reject the promise.
                $this->reject('Cannot wait on a promise that has '
                    . 'no internal wait function. You must provide a wait '
                    . 'function when constructing the promise to be able to '
                    . 'wait on a promise.');
            }
        }

        if (!$unwrap) {
            return null;
        }

        // Wait on nested promises until a normal value is unwrapped/thrown.
        $result = $this->result;
        while ($result instanceof PromiseInterface) {
            $result = $result->wait();
        }

        if ($this->state === self::FULFILLED) {
            return $result;
        }

        // It's rejected or cancelled, so "unwrap" and throw an exception.
        throw exception_for($result);
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
        if ($this->cancelFn) {
            $fn = $this->cancelFn;
            $this->cancelFn = null;
            try {
                $fn();
            } catch (\Exception $e) {
                $this->reject($e);
                return;
            }
        }

        // Reject the promise only if it wasn't rejected in a then callback.
        if ($this->state === self::PENDING) {
            $this->reject(new CancellationException('Promise has been cancelled'));
        }
    }

    public function resolve($value)
    {
        if ($this->state !== self::PENDING) {
            throw new \RuntimeException("Cannot resolve a {$this->state} promise");
        }

        if ($value === $this) {
            throw new \RuntimeException('Cannot resolve a promise with itself');
        }

        $this->state = self::FULFILLED;
        $this->result = $value;
        $this->cancelFn = $this->waitFn = null;

        if ($this->handlers) {
            $pending = [['value' => $value, 'index' => 1, 'handlers' => $this->handlers]];
            $this->handlers = [];
            $this->resolveStack($pending);
        }
    }

    public function reject($reason)
    {
        if ($this->state !== self::PENDING) {
            throw new \RuntimeException("Cannot reject a {$this->state} promise");
        }

        if ($reason === $this) {
            throw new \RuntimeException('Cannot reject a promise with itself');
        }

        $this->state = self::REJECTED;
        $this->result = $reason;
        $this->cancelFn = $this->waitFn = null;

        if ($this->handlers) {
            $pending = [['value' => $reason, 'index' => 2, 'handlers' => $this->handlers]];
            $this->handlers = [];
            $this->resolveStack($pending);
        }
    }

    /**
     * Resolve a stack of pending groups of handlers.
     *
     * @param array $pending Array of groups of handlers.
     */
    private function resolveStack(array $pending)
    {
        while ($group = array_pop($pending)) {
            // If the resolution value is a promise, then merge the handlers
            // into the promise to be notified when it is fulfilled.
            if ($group['value'] instanceof Promise) {
                if ($nextGroup = $this->resolveForwardPromise($group)) {
                    $pending[] = $nextGroup;
                    $nextGroup = null;
                }
                continue;
            }

            // Thennables that are not of our internal type must be recursively
            // resolved using a then() call.
            if (method_exists($group['value'], 'then')) {
                $this->passToNextPromise($group['value'], $group['handlers']);
                continue;
            }

            // Resolve or reject dependent handlers immediately.
            foreach ($group['handlers'] as $handler) {
                $pending[] = $this->callHandler(
                    $group['index'],
                    $group['value'],
                    $handler
                );
            }
        }
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
    private function callHandler($index, $value, array $handler)
    {
        try {
            // Use the result of the callback if available, otherwise just
            // forward the result down the chain as appropriate.
            if (isset($handler[$index])) {
                $nextValue = $handler[$index]($value);
            } elseif ($index === 1) {
                // Forward resolution values as-is.
                $nextValue = $value;
            } else {
                // Forward rejections down the chain.
                return $this->handleRejection($value, $handler);
            }
        } catch (\Exception $reason) {
            return $this->handleRejection($reason, $handler);
        }

        // You can return a rejected promise to forward a rejection.
        if ($nextValue instanceof PromiseInterface
            && $nextValue->getState() === self::REJECTED
        ) {
            return $this->handleRejection($nextValue, $handler);
        }

        $nextHandlers = $handler[0]->handlers;
        $handler[0]->handlers = [];
        $handler[0]->resolve($nextValue);

        // Resolve the listeners of this promise
        return [
            'value'    => $nextValue,
            'index'    => 1,
            'handlers' => $nextHandlers
        ];
    }

    /**
     * Reject the listeners of a promise.
     *
     * @param mixed $reason  Rejection resaon.
     * @param array $handler Handler to reject.
     *
     * @return array
     */
    private function handleRejection($reason, array $handler)
    {
        $nextHandlers = $handler[0]->handlers;
        $handler[0]->handlers = [];
        $handler[0]->reject($reason);

        return [
            'value'    => $reason,
            'index'    => 2,
            'handlers' => $nextHandlers
        ];
    }

    /**
     * The resolve value was a promise, so forward remaining resolution to the
     * resolution of the forwarding promise.
     *
     * @param array $group Group to forward.
     *
     * @return array|null Returns a new group if the promise is delivered or
     *                    returns null if the promise is pending or cancelled.
     */
    private function resolveForwardPromise(array $group)
    {
        /** @var Promise $promise */
        $promise = $group['value'];
        $handlers = $group['handlers'];
        $state = $promise->getState();
        if ($state === self::PENDING) {
            // The promise is an instance of Promise, so merge in the
            // dependent handlers into the promise.
            $promise->handlers = array_merge($promise->handlers, $handlers);
            return null;
        } elseif ($state === self::FULFILLED) {
            return [
                'value'    => $promise->result,
                'handlers' => $handlers,
                'index'    => 1
            ];
        } else { // rejected
            return [
                'value'    => $promise->result,
                'handlers' => $handlers,
                'index'    => 2
            ];
        }
    }

    /**
     * Resolve the dependent handlers recursively when the promise resolves.
     *
     * This function is invoked for thennable objects that are not an instance
     * of Promise (because we have no internal access to the handlers).
     *
     * @param mixed $promise  Promise that is being depended upon.
     * @param array $handlers Dependent handlers.
     */
    private function passToNextPromise($promise, array $handlers)
    {
        $promise->then(
            function ($value) use ($handlers) {
                // resolve the handlers with the given value.
                $stack = [];
                foreach ($handlers as $handler) {
                    $stack[] = $this->callHandler(1, $value, $handler);
                }
                $this->resolveStack($stack);
            }, function ($reason) use ($handlers) {
                // reject the handlers with the given reason.
                $stack = [];
                foreach ($handlers as $handler) {
                    $stack[] = $this->callHandler(2, $reason, $handler);
                }
                $this->resolveStack($stack);
            }
        );
    }

    private function invokeWait()
    {
        $wfn = $this->waitFn;
        $this->waitFn = null;

        try {
            // Invoke the wait fn and ensure it resolves the promise.
            $wfn();
        } catch (\Exception $reason) {
            // Encountering an exception in a wait method has two possibilities:
            // 1) The promise is already fulfilled/rejected, so ignore the
            //    exception. This can happen when waiting triggers callbacks
            //    that resolve the promise before an exception is thrown.
            // 2) The promise is still pending, so reject the promise with the
            //    encountered exception.
            if ($this->state === self::PENDING) {
                $this->reject($reason);
            }
        }

        if ($this->state === self::PENDING) {
            $this->reject('Invoking the wait callback did not resolve the promise');
        }
    }
}
