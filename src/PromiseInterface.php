<?php
namespace GuzzleHttp\Promise;

/**
 * A promise represents the eventual result of an asynchronous operation.
 *
 * The primary way of interacting with a promise is through its then method,
 * which registers callbacks to receive either a promise’s eventual value or
 * the reason why the promise cannot be fulfilled.
 *
 * @link https://promisesaplus.com/
 */
interface PromiseInterface
{
    const PENDING = 'pending';
    const FULFILLED = 'fulfilled';
    const REJECTED = 'rejected';

    /**
     * Create a new promise that chains off of the current promise.
     *
     * @param callable $onFulfilled Invoked when the promise fulfills.
     * @param callable $onRejected  Invoked when the promise is rejected.
     *
     * @return PromiseInterface
     */
    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null
    );

    /**
     * Get the state of the promise ("pending", "rejected", or "fulfilled").
     *
     * The three states can be checked against the constants defined on
     * PromiseInterface: PENDING, FULFILLED, and REJECTED.
     *
     * @return string
     */
    public function getState();

    /**
     * Resolve the promise with the given value.
     *
     * @param mixed $value
     * @throws \RuntimeException if the promise is already resolved.
     */
    public function resolve($value);

    /**
     * Reject the promise with the given reason.
     *
     * @param mixed $reason
     * @throws \RuntimeException if the promise is already resolved.
     */
    public function reject($reason);

    /**
     * Cancels the promise if possible.
     *
     * @link https://github.com/promises-aplus/cancellation-spec/issues/7
     */
    public function cancel();

    /**
     * Waits until the promise completes if possible.
     *
     * Pass $unwrap as true to unwrap the result of the promise, either
     * returning the resolved value or throwing the rejected exception.
     *
     * If the promise cannot be waited on, then the promise will be resolve
     * with the $defaultResolutionValue if a value is provided. Implementations
     * will need to be able to differentiate between providing `null` and
     * providing no value at all (e.g., use func_num_args() < 2). If not
     * default resolution is provided, then a \LogicException is thrown.
     *
     * @param bool  $unwrap
     * @param mixed $defaultResolution
     *
     * @return mixed
     * @throws \LogicException
     * @throws \Exception
     */
    public function wait($unwrap = true, $defaultResolution = null);
}
