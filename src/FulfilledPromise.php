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

    public function __construct($value)
    {
        if (method_exists($value, 'then')) {
            throw new \InvalidArgumentException(
                'You cannot create a FulfilledPromise with a promise.');
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

        try {
            // Return a new fulfilled if onFulfilled does not throw.
            return Promise::promiseFor($onFulfilled($this->value));
        } catch (\Exception $e) {
            // Return a rejected promise be onFulfilled failed.
            return new RejectedPromise($e);
        }
    }

    public function wait($unwrap = true, $defaultDelivery = null)
    {
        return $unwrap ? $this->value : null;
    }

    public function getState()
    {
        return self::FULFILLED;
    }

    public function resolve($value)
    {
        throw new \RuntimeException("Cannot resolve a fulfilled promise");
    }

    public function reject($reason)
    {
        throw new \RuntimeException("Cannot reject a fulfilled promise");
    }

    public function cancel()
    {
        // pass
    }
}
