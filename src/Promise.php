<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * Promises/A+ implementation that avoids recursion when possible.
 *
 * @template TValue = mixed
 * @template TReason = mixed
 *
 * @implements PromiseInterface<TValue, TReason>
 *
 * @see https://promisesaplus.com/
 *
 * @final
 */
class Promise implements PromiseInterface
{
    use NonSerializableTrait;

    /** @var self::PENDING|self::FULFILLED|self::REJECTED */
    private string $state = self::PENDING;

    /** @var TValue|TReason|PromiseInterface<TValue, TReason>|null */
    private $result;

    /** @var (callable(): void)|null */
    private $cancelFn;

    /**
     * Thenable this promise is locked to while it adopts the thenable's
     * eventual state (Promises/A+ 2.3.2). While set, the promise is
     * "resolved but pending": it stays pending and ignores further calls
     * to resolve() and reject() (Promises/A+ 2.3.3.3.3).
     */
    private ?object $adopted = null;

    /** @var (callable(bool): void)|null */
    private $waitFn;

    /** @var list<Promise<mixed, mixed>>|null */
    private ?array $waitList = null;

    /** @var list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}>|null */
    private ?array $handlers = [];

    /**
     * @param (callable(bool): void)|null $waitFn   Fn that when invoked resolves the promise.
     * @param (callable(): void)|null     $cancelFn Fn that when invoked cancels the promise.
     */
    public function __construct(
        ?callable $waitFn = null,
        ?callable $cancelFn = null
    ) {
        $this->waitFn = $waitFn;
        $this->cancelFn = $cancelFn;
    }

    /**
     * @template TFulfilledValue = never
     * @template TFulfilledReason = never
     * @template TRejectedValue = never
     * @template TRejectedReason = never
     *
     * @param (callable(TValue): (TFulfilledValue|PromiseInterface<TFulfilledValue, TFulfilledReason>))|null $onFulfilled Invoked when the promise fulfills.
     * @param (callable(TReason): (TRejectedValue|PromiseInterface<TRejectedValue, TRejectedReason>))|null   $onRejected  Invoked when the promise is rejected.
     *
     * @return PromiseInterface<($onFulfilled is null ? TValue : TFulfilledValue)|($onRejected is null ? never : TRejectedValue), ($onFulfilled is null ? never : TFulfilledReason|\Throwable)|($onRejected is null ? TReason : TRejectedReason|\Throwable)>
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): PromiseInterface {
        if ($this->state === self::PENDING) {
            $p = new Promise(null, [$this, 'cancel']);
            $this->handlers[] = [$p, $onFulfilled, $onRejected];
            $p->waitList = $this->waitList;
            $p->waitList[] = $this;

            return $p;
        }

        // Return a fulfilled promise and immediately invoke any callbacks.
        if ($this->state === self::FULFILLED) {
            $promise = Create::promiseFor($this->result);

            return $promise->then($onFulfilled, $onRejected);
        }

        // It's either cancelled or rejected, so return a rejected promise
        // and immediately invoke any callbacks.
        if (!is_object($this->result) || !method_exists($this->result, 'then')) {
            $rejection = Create::rejectionFor($this->result);

            /** @var PromiseInterface<($onFulfilled is null ? TValue : TFulfilledValue)|($onRejected is null ? never : TRejectedValue), ($onFulfilled is null ? never : TFulfilledReason|\Throwable)|($onRejected is null ? TReason : TRejectedReason|\Throwable)> $promise */
            $promise = $onRejected ? $rejection->then(null, $onRejected) : $rejection;

            return $promise;
        }

        // The reason is a thenable rejection reason adopted verbatim from
        // another implementation; it is passed to handlers verbatim as well,
        // as rejection reasons are never adopted.
        if (!$onRejected) {
            return $this;
        }

        $queue = Utils::queue();
        $p = new Promise([$queue, 'run']);
        $reason = $this->result;
        $queue->add(static function () use ($p, $reason, $onFulfilled, $onRejected): void {
            self::callHandler(2, $reason, [$p, $onFulfilled, $onRejected]);
        });

        return $p;
    }

    /**
     * @template TRejectedValue = never
     * @template TRejectedReason = never
     *
     * @param callable(TReason): (TRejectedValue|PromiseInterface<TRejectedValue, TRejectedReason>) $onRejected Invoked when the promise is rejected.
     *
     * @return PromiseInterface<TValue|TRejectedValue, TRejectedReason|\Throwable>
     */
    public function otherwise(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    public function wait(bool $unwrap = true)
    {
        $this->waitIfPending();

        if ($unwrap) {
            if ($this->state === self::FULFILLED) {
                return $this->result;
            }
            // It's rejected so "unwrap" and throw an exception.
            throw Create::exceptionFor($this->result);
        }

        return null;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function cancel(): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        $this->waitFn = $this->waitList = null;

        // Cancelling a promise that adopted another thenable's state also
        // cancels the adopted thenable and unlocks this promise so that the
        // rejection below can take effect.
        if (null !== $adopted = $this->adopted) {
            $this->adopted = null;
            if (method_exists($adopted, 'cancel')) {
                $adopted->cancel();
            }
        }

        if ($this->cancelFn) {
            $fn = $this->cancelFn;
            $this->cancelFn = null;
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->reject($e);
            }
        }

        // Reject the promise only if it wasn't rejected in a then callback.
        /** @psalm-suppress RedundantCondition */
        if ($this->state === self::PENDING) {
            $this->reject(new CancellationException('Promise has been cancelled'));
        }
    }

    public function resolve($value = null): void
    {
        $this->settle(self::FULFILLED, $value);
    }

    public function reject($reason): void
    {
        $this->settle(self::REJECTED, $reason);
    }

    private function settle(string $state, $value): void
    {
        if ($this->adopted !== null) {
            // The promise already adopts the state of another thenable, so
            // its fate is locked and further resolutions are ignored
            // (Promises/A+ 2.3.3.3.3).
            return;
        }

        if ($this->state !== self::PENDING) {
            // Ignore calls with the same resolution.
            if ($state === $this->state && $value === $this->result) {
                return;
            }
            throw $this->state === $state
                ? new \LogicException("The promise is already {$state}.")
                : new \LogicException("Cannot change a {$this->state} promise to {$state}");
        }

        if ($value === $this) {
            // Reject with a \TypeError instead (Promises/A+ 2.3.1).
            $state = self::REJECTED;
            $value = new \TypeError('Cannot fulfill or reject a promise with itself');
        }

        if (is_object($value) && method_exists($value, 'then')) {
            if ($state === self::REJECTED) {
                throw new \InvalidArgumentException('You cannot reject a promise with another promise.');
            }

            // Resolving with a thenable does not settle the promise. Instead
            // the promise stays pending and adopts the thenable's eventual
            // state (Promises/A+ 2.3.2).
            $this->adopt($value);

            return;
        }

        $this->settleWith($state, $value);
    }

    /**
     * Settles the promise with a resolution that already passed the
     * resolution procedure: the value of a fulfillment is never a thenable,
     * and a rejection reason stays verbatim (Promises/A+ 2.3.2.3).
     */
    private function settleWith(string $state, $value): void
    {
        // Clear out the state of the promise but stash the handlers.
        $this->state = $state;
        $this->result = $value;
        $handlers = $this->handlers;
        $this->handlers = null;
        $this->waitList = $this->waitFn = null;
        $this->cancelFn = null;
        $this->adopted = null;

        if (!$handlers) {
            return;
        }

        $id = $state === self::FULFILLED ? 1 : 2;
        Utils::queue()->add(static function () use ($id, $value, $handlers): void {
            foreach ($handlers as $handler) {
                self::callHandler($id, $value, $handler);
            }
        });
    }

    /**
     * Locks this promise to a thenable so that it adopts the thenable's
     * eventual state (Promises/A+ 2.3.2), staying pending until it settles.
     *
     * The state of an already-settled promise is adopted synchronously:
     * Promises/A+ only observes state through then() callbacks, which stay
     * asynchronous.
     */
    private function adopt(object $thenable): void
    {
        if ($thenable instanceof FulfilledPromise) {
            $this->settleWith(self::FULFILLED, $thenable->value());

            return;
        }

        if ($thenable instanceof RejectedPromise) {
            $this->settleWith(self::REJECTED, $thenable->reason());

            return;
        }

        if ($thenable instanceof self && $thenable->state !== self::PENDING) {
            $this->settleWith($thenable->state, $thenable->result);

            return;
        }

        $this->adopted = $thenable;
        // The resolver has committed to the thenable, so the original wait
        // and cancel functions no longer apply; waiting and cancellation are
        // forwarded to the adopted thenable instead.
        $this->waitFn = $this->waitList = null;
        $this->cancelFn = null;

        if ($thenable instanceof self) {
            // Merge the handlers onto the adopted promise behind a marker
            // entry that settles this promise first, keeping dispatch as flat
            // as merging handlers onto the next promise did before adoption.
            $thenable->handlers[] = [$this, null, null, true];
            if ($this->handlers) {
                $thenable->handlers = array_merge($thenable->handlers, $this->handlers);
            }
            $this->handlers = [];

            return;
        }

        $thenable->then(
            function ($value) use ($thenable): void {
                if ($this->adopted === $thenable) {
                    $this->adopted = null;
                    $this->settleWith(self::FULFILLED, $value);
                }
            },
            function ($reason) use ($thenable): void {
                if ($this->adopted === $thenable) {
                    $this->adopted = null;
                    $this->settleWith(self::REJECTED, $reason);
                }
            }
        );
    }

    /**
     * Call a stack of handlers using a specific callback index and value.
     *
     * @param int   $index   1 (resolve) or 2 (reject).
     * @param mixed $value   Value to pass to the callback.
     * @param array $handler Array of handler data (promise and callbacks).
     */
    private static function callHandler(int $index, $value, array $handler): void
    {
        /** @var PromiseInterface<mixed, mixed> $promise */
        $promise = $handler[0];

        if (isset($handler[3])) {
            // Adoption marker: the promise adopts this settlement directly,
            // unless cancellation already unlocked it.
            if ($promise instanceof self && $promise->adopted !== null) {
                $promise->adopted = null;
                $promise->settleWith($index === 1 ? self::FULFILLED : self::REJECTED, $value);
            }

            return;
        }

        // The promise may have been cancelled or resolved before placing
        // this thunk in the queue.
        if (Is::settled($promise)) {
            return;
        }

        try {
            if (isset($handler[$index])) {
                /*
                 * If $f throws an exception, then $handler will be in the exception
                 * stack trace. Since $handler contains a reference to the callable
                 * itself we get a circular reference. We clear the $handler
                 * here to avoid that memory leak.
                 */
                $f = $handler[$index];
                unset($handler);
                $promise->resolve($f($value));
            } elseif ($index === 1) {
                // Forward resolution values as-is.
                $promise->resolve($value);
            } else {
                // Forward rejections down the chain.
                $promise->reject($value);
            }
        } catch (\Throwable $reason) {
            $promise->reject($reason);
        }
    }

    private function waitIfPending(): void
    {
        if ($this->state !== self::PENDING) {
            return;
        } elseif ($this->waitFn) {
            $this->invokeWaitFn();
        } elseif ($this->waitList) {
            $this->invokeWaitList();
        } elseif ($this->adopted === null) {
            // If there's no wait function, then reject the promise.
            $this->reject('Cannot wait on a promise that has '
                .'no internal wait function. You must provide a wait '
                .'function when constructing the promise to be able to '
                .'wait on a promise.');
        }

        Utils::queue()->run();

        // Waiting on a promise that adopted a thenable's state waits on the
        // thenable, repeatedly in case the thenable settles with yet another
        // thenable that is adopted in turn. The promise is pending as long
        // as it is locked to an adopted thenable.
        while (null !== $adopted = $this->adopted) {
            if (!method_exists($adopted, 'wait')) {
                break;
            }
            $adopted->wait(false);
            Utils::queue()->run();
            if ($this->adopted === $adopted) {
                // Waiting on the adopted thenable did not settle it.
                break;
            }
        }

        /** @psalm-suppress RedundantCondition */
        if ($this->state === self::PENDING) {
            // Unlock the promise so that the rejection can take effect.
            $this->adopted = null;
            $this->reject('Invoking the wait callback did not resolve the promise');
        }
    }

    private function invokeWaitFn(): void
    {
        try {
            $wfn = $this->waitFn;
            $this->waitFn = null;
            $wfn(true);
        } catch (\Throwable $reason) {
            if ($this->state === self::PENDING && $this->adopted === null) {
                // The promise has not been resolved yet, so reject the promise
                // with the exception.
                $this->reject($reason);
            } else {
                // The promise was already resolved, so there's a problem in
                // the application.
                throw $reason;
            }
        }
    }

    private function invokeWaitList(): void
    {
        $waitList = $this->waitList;
        $this->waitList = null;

        foreach ($waitList as $result) {
            // A settled promise never holds another promise as its result:
            // fulfillment values are adopted and thenable rejection reasons
            // are refused, so there is no result chain to follow.
            $result->waitIfPending();
        }
    }
}
