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

        // Waiting on the promise will add a task to the trampoline.
        $reason = $this->reason;
        $p = new Promise(static function () use (&$p, $reason, $onRejected) {
            self::settle($p, $reason, $onRejected);
        });

        // Enqueue the trampoline resolver right away. It might beat the wait
        // function.
        self::settle($p, $reason, $onRejected);

        return $p;
    }

    public function otherwise(callable $onRejected)
    {
        return $this->then(null, $onRejected);
    }

    public function wait($unwrap = true, $defaultDelivery = null)
    {
        trampoline()->run();

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

    private static function settle(PromiseInterface $p, $reason, callable $onRejected)
    {
        trampoline()->enqueue(function () use ($p, $reason, $onRejected) {
            if ($p->getState() === $p::PENDING) {
                try {
                    // Return a resolved promise if onRejected does not throw.
                    $p->resolve($onRejected($reason));
                } catch (\Exception $e) {
                    // onRejected threw, so return a rejected promise.
                    $p->reject(new RejectedPromise($e));
                }
            }
        });
    }
}
