<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

final class Create
{
    private function __construct()
    {
    }

    /**
     * Creates a promise for a value if the value is not a promise.
     *
     * @template TValue
     * @template TPromise of PromiseInterface<mixed, mixed> = PromiseInterface<mixed, mixed>
     *
     * @param TValue|TPromise $value Promise or value.
     *
     * @return ($value is PromiseInterface ? TPromise : FulfilledPromise<TValue, mixed>)
     */
    public static function promiseFor($value): PromiseInterface
    {
        if ($value instanceof PromiseInterface) {
            return $value;
        }

        // Return a Guzzle promise that shadows the given promise.
        if (is_object($value) && method_exists($value, 'then')) {
            $wfn = method_exists($value, 'wait') ? [$value, 'wait'] : null;
            $cfn = method_exists($value, 'cancel') ? [$value, 'cancel'] : null;
            $promise = new Promise($wfn, $cfn);
            $value->then([$promise, 'resolve'], [$promise, 'reject']);

            return $promise;
        }

        return new FulfilledPromise($value);
    }

    /**
     * Creates a rejected promise for a reason if the reason is not a promise.
     * If the provided reason is a promise, then it is returned as-is.
     *
     * @template TReason
     * @template TValue = mixed
     * @template TPromise of PromiseInterface<mixed, mixed> = PromiseInterface<mixed, mixed>
     *
     * @param TReason|TPromise $reason Promise or reason.
     *
     * @return ($reason is PromiseInterface ? TPromise : RejectedPromise<TValue, TReason>)
     */
    public static function rejectionFor($reason): PromiseInterface
    {
        if ($reason instanceof PromiseInterface) {
            return $reason;
        }

        return new RejectedPromise($reason);
    }

    /**
     * Create an exception for a rejected promise value.
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
     * Returns an iterator for the given value.
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
