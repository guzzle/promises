<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

final class Create
{
    private function __construct()
    {
    }

    /**
     * Returns `$value` when it is already a Guzzle promise, wraps foreign
     * thenables in a Guzzle promise, or returns a fulfilled promise for plain
     * values.
     *
     * @template TValue
     * @template TPromise of PromiseInterface<mixed, mixed> = PromiseInterface<mixed, mixed>
     *
     * @param TValue|TPromise $value Promise or value.
     *
     * @return ($value is PromiseInterface ? TPromise : FulfilledPromise<TValue, never>)
     */
    public static function promiseFor($value): PromiseInterface
    {
        if ($value instanceof PromiseInterface) {
            return $value;
        }

        // Return a Guzzle promise that shadows the given promise.
        if (is_object($value) && method_exists($value, 'then')) {
            $promise = new Promise();
            $promise->resolve($value);

            return $promise;
        }

        return new FulfilledPromise($value);
    }

    /**
     * Returns a rejected promise for `$reason`, which must not be a promise or
     * other thenable.
     *
     * @template TReason
     *
     * @param TReason $reason
     *
     * @return RejectedPromise<never, TReason>
     *
     * @throws \InvalidArgumentException if the reason is a promise or other
     *                                   thenable.
     */
    public static function rejectionFor($reason): PromiseInterface
    {
        if (is_object($reason) && method_exists($reason, 'then')) {
            throw new \InvalidArgumentException('You cannot reject a promise with another promise.');
        }

        return new RejectedPromise($reason);
    }

    /**
     * Returns throwable reasons as-is, or wraps non-throwable reasons in
     * `RejectionException`.
     *
     * @template TReason
     *
     * @param TReason $reason
     */
    public static function exceptionFor($reason): \Throwable
    {
        if ($reason instanceof \Throwable) {
            return $reason;
        }

        return new RejectionException($reason);
    }

    /**
     * Returns an iterator for arrays, iterators, iterator aggregates, and
     * traversables.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param iterable<TKey, TValue> $value
     *
     * @return \Iterator<TKey, TValue>
     */
    public static function iterFor(iterable $value): \Iterator
    {
        if ($value instanceof \Iterator) {
            return $value;
        }

        if (is_array($value)) {
            return new \ArrayIterator($value);
        }

        if ($value instanceof \IteratorAggregate) {
            return self::iterFor($value->getIterator());
        }

        return new \IteratorIterator($value);
    }
}
