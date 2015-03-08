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
     * Creates a promise for a value if the value is not a promise.
     *
     * @return PromiseInterface
     */
    public static function promiseFor($value)
    {
        if ($value instanceof PromiseInterface
            // Is this a thennable object?
            || method_exists($value, 'then')
        ) {
            return $value;
        }

        return new FulfilledPromise($value);
    }

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
                ? self::promiseFor($this->result)->then($onFulfilled)
                : self::promiseFor($this->result);
        }

        // It's either cancelled or rejected, so return a rejected promise
        // and immediately invoke any callbacks.
        if ($onRejected) {
            return (new RejectedPromise($this->result))->then(null, $onRejected);
        }

        return new RejectedPromise($this->result);
    }

    public function wait($unwrap = true, $defaultDelivery = null)
    {
        if ($this->state === self::PENDING) {
            if (!$this->waitFn) {
                // If there's not wait function, then resolve the promise with
                // the provided $defaultDelivery value.
                $this->resolve($defaultDelivery);
            } else {
                try {
                    // Invoke the wait fn and ensure it resolves the promise.
                    $wfn = $this->waitFn;
                    $this->waitFn = null;
                    $wfn();
                    if ($this->state === self::PENDING) {
                        throw new \LogicException('Invoking the wait callback did not resolve the promise');
                    }
                } catch (\Exception $e) {
                    // Bubble up wait exceptions to reject this promise.
                    $this->reject($e);
                }
            }
            $this->cancelFn = null;
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
        throw $result instanceof \Exception
            ? $result
            : new RejectionException($result);
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

        $this->state = self::FULFILLED;
        $this->result = $value;
        $this->cancelFn = $this->waitFn = null;

        if ($this->handlers) {
            $this->deliver($value);
        }
    }

    public function reject($reason)
    {
        if ($this->state !== self::PENDING) {
            throw new \RuntimeException("Cannot reject a {$this->state} promise");
        }

        $this->state = self::REJECTED;
        $this->result = $reason;
        $this->cancelFn = $this->waitFn = null;

        if ($this->handlers) {
            $this->deliver($reason);
        }
    }

    /**
     * Deliver a resolution or rejection to the promise and dependent handlers.
     *
     * @param mixed $value Value to deliver.
     */
    private function deliver($value)
    {
        $pending = [
            [
                'value'    => $value,
                'index'    => $this->state === self::FULFILLED ? 1 : 2,
                'handlers' => $this->handlers
            ]
        ];
        $this->handlers = [];
        $this->resolveStack($pending);
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
        if ($nextValue instanceof RejectedPromise) {
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
}
