<?php

namespace GuzzleHttp\Promise;

/**
 * A promise that has been fulfilled.
 *
 * Thenning off of this promise will invoke the onFulfilled callback
 * immediately and ignore other callbacks.
 */
class FulfilledPromise implements PromiseInterface
{
    private $value;

    /** @var Promise|null */
    private $promise;

    /** @var callable|null */
    private $onFulfilled;

    public function __construct($value)
    {
        if (is_object($value) && method_exists($value, 'then')) {
            throw new \InvalidArgumentException(
                'You cannot create a FulfilledPromise with a promise.'
            );
        }

        $this->value = $value;
    }

    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null
    ) {
        // Return itself if there is no onFulfilled function.
        if (!$onFulfilled) {
            return $this;
        }

        $this->onFulfilled = $onFulfilled;

        $queue = Utils::queue();
        $p = $this->promise = new Promise([$queue, 'run']);
        $value = $this->value;
        $queue->add(static function () use ($p, $value, $onFulfilled) {
            if (Is::pending($p)) {
                self::callHandler($p, $value, $onFulfilled);
            }
        });

        return $p;
    }

    public function otherwise(callable $onRejected)
    {
        return $this->then(null, $onRejected);
    }

    public function wait($unwrap = true, $defaultDelivery = null)
    {
        // Don't run the queue to avoid deadlocks, instead directly resolve the promise.
        if ($this->promise && Is::pending($this->promise)) {
            self::callHandler($this->promise, $this->value, $this->onFulfilled);
        }

        return $unwrap ? $this->value : null;
    }

    public function getState()
    {
        return self::FULFILLED;
    }

    public function resolve($value)
    {
        if ($value !== $this->value) {
            throw new \LogicException("Cannot resolve a fulfilled promise");
        }
    }

    public function reject($reason)
    {
        throw new \LogicException("Cannot reject a fulfilled promise");
    }

    public function cancel()
    {
        // pass
    }

    private static function callHandler(Promise $promise, $value, callable $handler)
    {
        try {
            $promise->resolve($handler($value));
        } catch (\Throwable $e) {
            $promise->reject($e);
        } catch (\Exception $e) {
            $promise->reject($e);
        }
    }
}
