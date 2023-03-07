<?php

namespace GuzzleHttp\Promise;

final class Create
{
    /**
     * Creates a promise for a value if the value is not a promise.
     *
     * @template ValueType
     * @template ReasonType
     *
     * @param ValueType|PromiseInterface<ValueType, ReasonType> $value Promise, a promise-like object (TODO: duck typing, not expressable as type) or value.
     *
     * @return PromiseInterface<ValueType, ReasonType>
     */
    public static function promiseFor($value)
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
     * @template ValueType
     * @template ReasonType
     *
     * @param ReasonType|PromiseInterface<ValueType, ReasonType> $reason Promise or reason.
     *
     * @return PromiseInterface<ValueType, ReasonType>
     */
    public static function rejectionFor($reason)
    {
        if ($reason instanceof PromiseInterface) {
            return $reason;
        }

        return new RejectedPromise($reason);
    }

    /**
     * Create an exception for a rejected promise value.
     *
     * @param mixed $reason
     *
     * @return \Exception|\Throwable
     */
    public static function exceptionFor($reason)
    {
        if ($reason instanceof \Exception || $reason instanceof \Throwable) {
            return $reason;
        }

        return new RejectionException($reason);
    }

    /**
     * Returns an iterator for the given value.
     *
     * @param mixed $value
     *
     * @return \Iterator
     */
    public static function iterFor($value)
    {
        if ($value instanceof \Iterator) {
            return $value;
        }

        if (is_array($value)) {
            return new \ArrayIterator($value);
        }

        return new \ArrayIterator([$value]);
    }
}
