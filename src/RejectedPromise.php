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

    /** @var Promise|null */
    private $promise;

    /** @var callable|null */
    private $onRejected;

    public function __construct($reason)
    {
        if (is_object($reason) && method_exists($reason, 'then')) {
            throw new \InvalidArgumentException(
                'You cannot create a RejectedPromise with a promise.'
            );
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

        $this->onRejected = $onRejected;

        $queue = Utils::queue();
        $reason = $this->reason;
        $p = $this->promise = new Promise([$queue, 'run']);
        $queue->add(static function () use ($p, $reason, $onRejected) {
            if (Is::pending($p)) {
                self::callHandler($p, $reason, $onRejected);
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
        if ($unwrap) {
            throw Create::exceptionFor($this->reason);
        }

        // Don't run the queue to avoid deadlocks, instead directly reject the promise.
        if ($this->promise && Is::pending($this->promise)) {
            self::callHandler($this->promise, $this->reason, $this->onRejected);
        }

        return null;
    }

    public function getState()
    {
        return self::REJECTED;
    }

    public function resolve($value)
    {
        throw new \LogicException("Cannot resolve a rejected promise");
    }

    public function reject($reason)
    {
        if ($reason !== $this->reason) {
            throw new \LogicException("Cannot reject a rejected promise");
        }
    }

    public function cancel()
    {
        // pass
    }

    private static function callHandler(Promise $promise, $reason, callable $handler)
    {
        try {
            $promise->resolve($handler($reason));
        } catch (\Throwable $e) {
            $promise->reject($e);
        } catch (\Exception $e) {
            $promise->reject($e);
        }
    }
}
