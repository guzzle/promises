<?php
namespace GuzzleHttp\Promise;

/**
 * A promise that has been rejected.
 *
 * Thenning off of this promise will invoke the onRejected callback
 * immediately and ignore other callbacks.
 */
class RejectedPromise implements PromiseInterface
{
    private $reason;

    public function __construct($reason)
    {
        if (method_exists($reason, 'then')) {
            throw new \InvalidArgumentException(
                'You cannot create a RejectedPromise with a promise.');
        }

        $this->reason = $reason;
    }

    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null
    ) {
        // If there's no onRejected callback then just return self.
        if (!$onRejected) {
            return $this;
        }

        try {
            // Return a resolved promise if onRejected does not throw.
            return promise_for($onRejected($this->reason));
        } catch (\Exception $e) {
            // onRejected threw, so return a rejected promise.
            return new static($e);
        }
    }

    public function wait($unwrap = true, $defaultDelivery = null)
    {
        if ($unwrap) {
            throw exception_for($this->reason);
        }
    }

    public function getState()
    {
        return self::REJECTED;
    }

    public function resolve($value)
    {
        throw new \RuntimeException("Cannot resolve a rejected promise");
    }

    public function reject($reason)
    {
        throw new \RuntimeException("Cannot reject a rejected promise");
    }

    public function cancel()
    {
        // pass
    }
}
