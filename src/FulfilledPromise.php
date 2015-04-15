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

        // Waiting on the promise will add a task to the trampoline.
        $value = $this->value;
        $p = new Promise(static function () use (&$p, $value, $onFulfilled) {
            self::settle($p, $value, $onFulfilled);
        });

        // Enqueue the trampoline resolver right away. It might beat the wait
        // function.
        self::settle($p, $value, $onFulfilled);

        return $p;
    }

    public function otherwise(callable $onRejected)
    {
        return $this->then(null, $onRejected);
    }

    public function wait($unwrap = true, $defaultDelivery = null)
    {
        trampoline()->run();

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

    private static function settle(PromiseInterface $p, $value, callable $onFulfilled)
    {
        trampoline()->schedule(function () use ($p, $value, $onFulfilled) {
            if ($p->getState() === $p::PENDING) {
                try {
                    $p->resolve($onFulfilled($value));
                } catch (\Exception $e) {
                    $p->reject($e);
                }
            }
        });
    }
}
