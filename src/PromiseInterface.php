<?php
namespace GuzzleHttp\Promise;

/**
 * Represents the eventual outcome of a deferred.
 */
interface PromiseInterface
{
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
     * Get the state of the promise.
     *
     * State can be one of: pending, fulfilled, rejected, or cancelled.
     *
     * @return string
     */
    public function getState();

    /**
     * Resolve the promise with the given value.
     *
     * @param mixed $value
     */
    public function resolve($value);

    /**
     * Reject the promise with the given reason.
     *
     * @param mixed $reason
     */
    public function reject($reason);

    /**
     * Cancels the promise if possible.
     */
    public function cancel();

    /**
     * Waits until the promise completes if possible.
     *
     * Pass $unwrap as true to unwrap the result of the promise, either
     * returning the resolved value or throwing the rejected exception.
     *
     * If the promise cannot be waited on, then the promise will be resolve
     * with the $defaultResolutionValue.
     *
     * @param bool  $unwrap
     * @param mixed $defaultResolution
     *
     * @return mixed
     * @throws \Exception
     */
    public function wait($unwrap = true, $defaultResolution = null);
}
