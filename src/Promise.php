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
     * Thenable whose eventual state this promise adopts (Promises/A+ 2.3.2).
     * While set, the promise stays pending and ignores resolve() and reject()
     * (Promises/A+ 2.3.3.3.3).
     */
    private ?object $adopted = null;

    /** @var (callable(bool): void)|null */
    private $waitFn;

    /** @var list<Promise<mixed, mixed>>|null */
    private ?array $waitList = null;

    /** @var list<array{0: Promise<mixed, mixed>, 1: callable|null, 2: callable|null, 3?: true}>|null */
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

    /**
     * Returns whether the promise is settled or adopting the state of a
     * thenable, so that resolve() and reject() no longer decide its outcome.
     *
     * @internal
     */
    public function isResolved(): bool
    {
        return $this->state !== self::PENDING || $this->adopted !== null;
    }

    public function cancel(): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        $this->waitFn = $this->waitList = null;

        $adopted = $this->adopted;

        if ($this->cancelFn) {
            $fn = $this->cancelFn;
            $this->cancelFn = null;
            try {
                $fn();
            } catch (\Throwable $e) {
                if ($adopted === null) {
                    $this->reject($e);
                } else {
                    $this->failAdoption($adopted, $e);
                }
            }
        }

        // A promise still locked to the thenable it forwarded the cancellation
        // to follows the outcome that produced, and is otherwise unlocked for
        // the rejection below to take effect.
        if ($adopted !== null && $this->adopted === $adopted) {
            $this->adopted = null;
            if ($adopted instanceof self && $adopted->state !== self::PENDING) {
                $this->settleWith($adopted->state, $adopted->result);
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

    private function settle(string $state, mixed $value): void
    {
        if ($this->adopted !== null) {
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

            $this->adopt($value);

            return;
        }

        $this->settleWith($state, $value);
    }

    /**
     * Settles the promise with a resolution that already passed the resolution
     * procedure: the value of a fulfillment is never a thenable, and a
     * rejection reason stays verbatim (Promises/A+ 2.3.2.3).
     */
    private function settleWith(string $state, mixed $value): void
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
     * Adopts the eventual state of a thenable. The state of an already-settled
     * promise is adopted synchronously, as Promises/A+ only observes state
     * through then() callbacks, which stay asynchronous.
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
        // The resolver has committed to the thenable, so the original wait and
        // cancel functions no longer apply; waiting and cancellation are
        // forwarded to the adopted thenable instead, cancellation at most once.
        $this->waitFn = $this->waitList = null;
        $this->cancelFn = method_exists($thenable, 'cancel') ? [$thenable, 'cancel'] : null;

        if ($thenable instanceof self) {
            // A marker entry among the adopted promise's handlers settles this
            // promise, which then dispatches its own handlers.
            $thenable->handlers[] = [$this, null, null, true];

            return;
        }

        try {
            $thenable->then(
                function (mixed $value) use ($thenable): void {
                    if ($this->adopted === $thenable) {
                        $this->adopted = null;
                        $this->settle(self::FULFILLED, $value);
                    }
                },
                function (mixed $reason) use ($thenable): void {
                    if ($this->adopted === $thenable) {
                        $this->adopted = null;
                        $this->settleWith(self::REJECTED, $reason);
                    }
                }
            );
        } catch (\Throwable $e) {
            if ($this->adopted === $thenable) {
                $this->adopted = null;
                $this->settleWith(self::REJECTED, $e);
            }
        }
    }

    /**
     * Rejects the promise with an exception thrown while waiting on or
     * cancelling the thenable it adopts.
     *
     * @throws \Throwable if the failure is not the promise's own outcome: an
     *                    adopted Promise reports its own, and a promise no
     *                    longer locked to the thenable has already moved on.
     */
    private function failAdoption(object $adopted, \Throwable $e): void
    {
        if ($adopted instanceof self || $this->adopted !== $adopted) {
            throw $e;
        }

        $this->adopted = null;
        $this->settleWith(self::REJECTED, $e);
    }

    /**
     * Call a stack of handlers using a specific callback index and value.
     *
     * @param int   $index   1 (resolve) or 2 (reject).
     * @param mixed $value   Value to pass to the callback.
     * @param array $handler Array of handler data (promise and callbacks).
     */
    private static function callHandler(int $index, mixed $value, array $handler): void
    {
        /** @var Promise<mixed, mixed> $promise */
        $promise = $handler[0];

        if (isset($handler[3])) {
            // Adoption marker: the promise adopts this settlement directly,
            // unless cancellation already unlocked it.
            if ($promise->adopted !== null) {
                $promise->adopted = null;
                $promise->settleWith($index === 1 ? self::FULFILLED : self::REJECTED, $value);
            }

            return;
        }

        // The promise may have been cancelled or resolved before placing
        // this thunk in the queue.
        if ($promise->isResolved()) {
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
                // Forward rejections down the chain, verbatim even when the
                // reason is a thenable that reject() would refuse.
                $promise->settleWith(self::REJECTED, $value);
            }
        } catch (\Throwable $reason) {
            $promise->reject($reason);
        }
    }

    private function waitIfPending(): void
    {
        if ($this->adopted !== null) {
            // The outcome of the adopted thenable may already be in the queue.
            Utils::queue()->run();
        }

        // Each pass waits on the promise at the end of the chain of adopted
        // promises, found here so that the stack does not grow with the chain.
        $last = $this;
        while ($this->state === self::PENDING) {
            if ($last->state !== self::PENDING) {
                // The queue has not carried its outcome up the chain.
                $last = $this;
            }
            while ($last->adopted instanceof self && $last->adopted->state === self::PENDING) {
                $last = $last->adopted;
            }
            $last->waitOnce();
            if ($last !== $this) {
                Utils::queue()->run();
            }
        }
    }

    /**
     * Waits on what this pending promise itself depends on, which settles it
     * or leaves it adopting another thenable.
     */
    private function waitOnce(): void
    {
        $adopted = $this->adopted;

        if ($adopted instanceof self) {
            // The adopted promise has settled, but its handlers are being
            // called further up the stack and have not reached this promise.
            $this->settleWith($adopted->state, $adopted->result);

            return;
        }

        if ($adopted !== null) {
            if (method_exists($adopted, 'wait')) {
                try {
                    $adopted->wait(false);
                } catch (\Throwable $reason) {
                    $this->failAdoption($adopted, $reason);
                }
                Utils::queue()->run();
            }
        } else {
            if ($this->waitFn) {
                $this->invokeWaitFn();
            } elseif ($this->waitList) {
                $this->invokeWaitList();
            } else {
                // If there's no wait function, then reject the promise.
                $this->reject('Cannot wait on a promise that has '
                    .'no internal wait function. You must provide a wait '
                    .'function when constructing the promise to be able to '
                    .'wait on a promise.');
            }
            Utils::queue()->run();
        }

        /** @psalm-suppress RedundantCondition */
        if ($this->state === self::PENDING && $this->adopted === $adopted) {
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
            $result->waitIfPending();
        }
    }
}
