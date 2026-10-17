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

    /** @var (callable(bool): void)|null */
    private $waitFn;

    /** @var list<Promise<mixed, mixed>>|null */
    private ?array $waitList = null;

    /** @var list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}>|null */
    private ?array $handlers = [];

    /**
     * The execution context that owns this promise.
     *
     * @var \WeakReference<object>|null
     */
    private ?\WeakReference $owner = null;

    /** Whether the wait function is running. */
    private bool $waiting = false;

    /** The execution context running the wait function, if any. */
    private ?object $waitingContext = null;

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

        $context = ExecutionContext::current();

        if ($context !== null) {
            $this->owner = \WeakReference::create($context);
        }
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
        $this->assertUsableInCurrentContext('then');

        if ($this->state === self::PENDING) {
            $p = new Promise(null, [$this, 'cancel']);

            if ($p->owner !== null) {
                // The handler runs in the context that owns $p, so make sure
                // finishing that context drains what is queued for it.
                ExecutionContextState::forContext($p->owner->get());
            }

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
        $rejection = Create::rejectionFor($this->result);

        /** @var PromiseInterface<($onFulfilled is null ? TValue : TFulfilledValue)|($onRejected is null ? never : TRejectedValue), ($onFulfilled is null ? never : TFulfilledReason|\Throwable)|($onRejected is null ? TReason : TRejectedReason|\Throwable)> $promise */
        $promise = $onRejected ? $rejection->then(null, $onRejected) : $rejection;

        return $promise;
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
        $this->assertUsableInCurrentContext('wait');
        $this->waitIfPending();

        if ($this->result instanceof PromiseInterface) {
            return $this->result->wait($unwrap);
        }
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

        $this->assertUsableInCurrentContext('cancel');
        $this->waitFn = $this->waitList = null;

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
        $this->assertSettleableInCurrentContext('resolve', $value);
        $this->settle(self::FULFILLED, $value);
    }

    public function reject($reason): void
    {
        $this->assertSettleableInCurrentContext('reject', $reason);
        $this->settle(self::REJECTED, $reason);
    }

    /**
     * Rejects settlement from another context and adoption of a pending
     * promise owned by another context.
     *
     * @param mixed $value
     */
    private function assertSettleableInCurrentContext(string $operation, $value): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        $this->assertUsableInCurrentContext($operation);

        if ($value instanceof Coroutine) {
            $value = $value->_getResult();
        }

        if ($value instanceof Promise) {
            $value->assertUsableInCurrentContext('adopt');
        }
    }

    /**
     * @internal
     *
     * @param PromiseInterface<mixed, mixed> $promise
     */
    public static function _assertUsableInCurrentContext(PromiseInterface $promise, string $operation): void
    {
        if ($promise instanceof self) {
            $promise->assertUsableInCurrentContext($operation);
        }
    }

    /**
     * Rejects use of this promise from a context that does not own it while
     * the promise, or a promise it has adopted, is still pending.
     */
    private function assertUsableInCurrentContext(string $operation): void
    {
        if ($this->owner === null) {
            return;
        }

        $owner = $this->owner->get();

        if ($owner !== null && $owner === ExecutionContext::current()) {
            return;
        }

        if (!$this->isUnsettled()) {
            return;
        }

        $action = $operation === 'adopt' ? 'adopt' : "call {$operation}() on";

        throw new CrossContextException($owner === null
            ? "Cannot {$action} a pending promise whose execution context has ended. Settle or cancel promises before their context ends."
            : "Cannot {$action} a pending promise owned by another execution context. Create and settle promises in one context, and share only settled results.");
    }

    /**
     * Determines if this promise or any promise it has adopted is pending.
     */
    private function isUnsettled(): bool
    {
        $promise = $this;
        $seen = [];

        while (true) {
            if ($promise->state === self::PENDING) {
                return true;
            }

            $result = $promise->result;

            if (!$result instanceof Promise) {
                return $result instanceof PromiseInterface && $result->getState() === self::PENDING;
            }

            $id = spl_object_id($result);

            if (isset($seen[$id])) {
                return false;
            }

            $seen[$id] = true;
            $promise = $result;
        }
    }

    private function settle(string $state, $value): void
    {
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
            throw new \LogicException('Cannot fulfill or reject a promise with itself');
        }

        // Clear out the state of the promise but stash the handlers.
        $waitList = $this->waitList;
        $waitFn = $this->waitFn;
        $cancelFn = $this->cancelFn;
        $this->state = $state;
        $this->result = $value;
        $handlers = $this->handlers;
        $this->handlers = null;
        $this->waitList = $this->waitFn = null;
        $this->cancelFn = null;

        if (!$handlers) {
            return;
        }

        // If the value was not a settled promise or a thenable, then resolve
        // it in the task queue using the correct ID.
        if (!is_object($value) || !method_exists($value, 'then')) {
            $id = $state === self::FULFILLED ? 1 : 2;
            // It's a success, so resolve the handlers in the queue.
            self::dispatch($id, $value, $handlers, true);
        } elseif ($value instanceof Promise && State::isPending($value)) {
            // We can just merge our handlers onto the next promise.
            $value->handlers = array_merge($value->handlers, $handlers);
        } else {
            // Resolve the handlers when the forwarded promise is resolved.
            $calledBack = false;

            try {
                $value->then(
                    static function ($value) use ($handlers, &$calledBack): void {
                        $calledBack = true;
                        self::dispatch(1, $value, $handlers, false);
                    },
                    static function ($reason) use ($handlers, &$calledBack): void {
                        $calledBack = true;
                        self::dispatch(2, $reason, $handlers, false);
                    }
                );
            } catch (\Throwable $e) {
                // A value that called back before throwing has already settled
                // the handlers' promises, so this promise stays settled.
                if ($calledBack) {
                    throw $e;
                }

                // The value refused the callbacks, so leave this promise as it
                // was. A promise from another library can refuse them when it
                // waits on another context's pending promise.
                $this->state = self::PENDING;
                $this->result = null;
                $this->handlers = $handlers;
                $this->waitList = $waitList;
                $this->waitFn = $waitFn;
                $this->cancelFn = $cancelFn;

                throw $e;
            }
        }
    }

    /**
     * Runs handlers in the context that owns the promise each one settles.
     *
     * With a provider configured, handlers whose promise another live context
     * owns are queued for that context, which registered them, and handlers
     * whose owning context has been finished or has ended are dropped, because
     * nothing would run them any more. Other handlers run here, queued when
     * $queue is true.
     *
     * @param list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}> $handlers
     * @param mixed                                                                              $value
     */
    private static function dispatch(int $index, $value, array $handlers, bool $queue): void
    {
        [$local, $foreign] = ExecutionContext::provider() !== null
            ? self::groupByOwner($handlers)
            : [$handlers, []];

        foreach ($foreign as [$contextQueue, $owned]) {
            $contextQueue->add(static function () use ($index, $value, $owned): void {
                self::callHandlers($index, $value, $owned);
            });
        }

        if ($local === []) {
            return;
        }

        if (!$queue) {
            self::callHandlers($index, $value, $local);

            return;
        }

        Utils::queue()->add(static function () use ($index, $value, $local): void {
            self::callHandlers($index, $value, $local);
        });
    }

    /**
     * Splits handlers into those that run in the current context and those
     * grouped by the task queue of the other live context that owns them,
     * dropping handlers of finished or ended contexts.
     *
     * @param list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}> $handlers
     *
     * @return array{0: list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}>, 1: array<int, array{0: TaskQueue, 1: list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}>}>}
     */
    private static function groupByOwner(array $handlers): array
    {
        $current = ExecutionContext::current();
        $local = [];
        $foreign = [];

        foreach ($handlers as $handler) {
            $owner = $handler[0] instanceof self ? $handler[0]->owner : null;
            $context = $owner === null ? $current : $owner->get();

            if ($owner !== null && $context === null) {
                // The owning context has ended.
                continue;
            }

            if ($context === $current) {
                $local[] = $handler;

                continue;
            }

            $contextQueue = ExecutionContextState::queueFor($context);

            if ($contextQueue === null) {
                // The owning context has been finished and drains nothing.
                continue;
            }

            $foreign[spl_object_id($contextQueue)][0] = $contextQueue;
            $foreign[spl_object_id($contextQueue)][1][] = $handler;
        }

        return [$local, $foreign];
    }

    /**
     * @param list<array{0: PromiseInterface<mixed, mixed>, 1: callable|null, 2: callable|null}> $handlers
     * @param mixed                                                                              $value
     */
    private static function callHandlers(int $index, $value, array $handlers): void
    {
        foreach ($handlers as $handler) {
            self::callHandler($index, $value, $handler);
        }
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

        // The promise may have been cancelled or resolved before placing
        // this thunk in the queue.
        if (State::isSettled($promise)) {
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
        } elseif ($this->waiting && $this->waitingContext !== ExecutionContext::current()) {
            // The wait function belongs to the waiting context, which settles
            // the promise when it returns.
            throw new CrossContextException('Cannot call wait() on a promise that another execution context is waiting on. Wait in one context and share the settled result.');
        } elseif ($this->waitFn) {
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

        /** @psalm-suppress RedundantCondition */
        if ($this->state === self::PENDING) {
            $this->reject('Invoking the wait callback did not resolve the promise');
        }
    }

    private function invokeWaitFn(): void
    {
        try {
            $wfn = $this->waitFn;
            $this->waitFn = null;
            $this->waiting = true;
            $this->waitingContext = ExecutionContext::current();
            $wfn(true);
        } catch (\Throwable $reason) {
            if ($this->state === self::PENDING) {
                // The promise has not been resolved yet, so reject the promise
                // with the exception.
                $this->reject($reason);
            } else {
                // The promise was already resolved, so there's a problem in
                // the application.
                throw $reason;
            }
        } finally {
            $this->waiting = false;
            $this->waitingContext = null;
        }
    }

    private function invokeWaitList(): void
    {
        $waitList = $this->waitList;
        $this->waitList = null;

        try {
            foreach ($waitList as $result) {
                do {
                    $result->waitIfPending();
                    $result = $result->result;
                } while ($result instanceof Promise);

                if ($result instanceof PromiseInterface) {
                    $result->wait(false);
                }
            }
        } catch (CrossContextException $e) {
            // Nothing on the list was changed, so a later wait can use it.
            $this->waitList = $waitList;

            throw $e;
        }
    }
}
