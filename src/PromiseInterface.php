<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

/**
 * A promise represents the eventual result of an asynchronous operation.
 *
 * The primary way of interacting with a promise is through its then method,
 * which registers callbacks to receive either a promise’s eventual value or
 * the reason why the promise cannot be fulfilled.
 *
 * @see https://promisesaplus.com/
 * @template T
 */
interface PromiseInterface
{
    public const PENDING = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED = 'rejected';

    /**
     * Appends fulfillment and rejection handlers to the promise, and returns
     * a new promise resolving to the return value of the called handler.
     *
     * @template U
     * @template V
     * @param null|callable(T): U $onFulfilled Invoked when the promise fulfills.
     * @param null|callable(mixed): V $onRejected  Invoked when the promise is rejected.
     *
     * @return ($onFulfilled is null ? ($onRejected is null ? PromiseInterface<T> : PromiseInterface<T|V>) : ($onRejected is null ? PromiseInterface<T|U> : PromiseInterface<U|V>))
     */
    public function then(
        ?callable $onFulfilled = null,
        ?callable $onRejected = null
    ): PromiseInterface;

    /**
     * Appends a rejection handler callback to the promise, and returns a new
     * promise resolving to the return value of the callback if it is called,
     * or to its original fulfillment value if the promise is instead
     * fulfilled.
     *
     * @template V
     * @param callable(mixed): V $onRejected Invoked when the promise is rejected.
     *
     * @return PromiseInterface<T|V>
     */
    public function otherwise(callable $onRejected): PromiseInterface;

    /**
     * Get the state of the promise ("pending", "rejected", or "fulfilled").
     *
     * The three states can be checked against the constants defined on
     * PromiseInterface: PENDING, FULFILLED, and REJECTED.
     *
     * @return 'pending'|'fulfilled'|'rejected'
     */
    public function getState(): string;

    /**
     * Resolve the promise with the given value.
     *
     * @param T $value
     *
     * @throws \RuntimeException if the promise is already resolved.
     */
    public function resolve($value): void;

    /**
     * Reject the promise with the given reason.
     *
     * @param mixed $reason
     *
     * @throws \RuntimeException if the promise is already resolved.
     */
    public function reject($reason): void;

    /**
     * Cancels the promise if possible.
     *
     * @see https://github.com/promises-aplus/cancellation-spec/issues/7
     */
    public function cancel(): void;

    /**
     * Waits until the promise completes if possible.
     *
     * Pass $unwrap as true to unwrap the result of the promise, either
     * returning the resolved value or throwing the rejected exception.
     *
     * If the promise cannot be waited on, then the promise will be rejected.
     *
     * @param bool $unwrap
     *
     * @return ($unwrap is true ? T : void)
     *
     * @throws \LogicException if the promise has no wait function or if the
     *                         promise does not settle after waiting.
     */
    public function wait(bool $unwrap = true);
}
