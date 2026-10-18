<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

final class Aggregate
{
    private function __construct()
    {
    }

    /**
     * Given an array of promises, return a promise that is fulfilled when all
     * the items in the array are fulfilled.
     *
     * The promise's fulfillment value is an array with fulfillment values at
     * respective positions to the original array. If any promise in the array
     * rejects, the returned promise is rejected with the rejection reason.
     *
     * The config array accepts a concurrency option for lazy iterables. Other
     * config keys are ignored by this wrapper.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TReason
     *
     * @param iterable<TKey, TValue|PromiseInterface<TValue, TReason>> $promises  Promises or values.
     * @param bool                                                     $recursive If true, resolves newly-added entries until no unprocessed entries or pending promises remain.
     * @param array{concurrency?: int|(callable(int): int)}            $config    Configuration options.
     *
     * @return PromiseInterface<array<TKey, TValue>, TReason|\Throwable>
     */
    public static function all(iterable $promises, bool $recursive = false, array $config = []): PromiseInterface
    {
        $results = [];
        $promise = Each::of(
            $promises,
            function ($value, $idx) use (&$results): void {
                $results[$idx] = $value;
            },
            function ($reason, $idx, PromiseInterface $aggregate): void {
                if (State::isPending($aggregate)) {
                    $aggregate->reject($reason);
                }
            },
            $config
        )->then(function () use (&$results) {
            ksort($results);

            return $results;
        });

        if (true === $recursive) {
            $promise = $promise->then(function ($results) use (&$promises, $config) {
                if (self::shouldRecurse($promises, $results)) {
                    return self::all($promises, true, $config);
                }

                return $results;
            });
        }

        return $promise;
    }

    /**
     * Initiate a competitive race between multiple promises or values (values
     * will become immediately fulfilled promises).
     *
     * When count amount of promises have been fulfilled, the returned promise
     * is fulfilled with an array that contains the fulfillment values of the
     * winners, in the order they appear in the input.
     *
     * This promise is rejected with a {@see AggregateException} if the number
     * of fulfilled promises is less than the desired $count.
     *
     * @template TValue
     * @template TReason
     *
     * @param int                                                $count    Total number of promises.
     * @param iterable<TValue|PromiseInterface<TValue, TReason>> $promises Promises or values.
     *
     * @return PromiseInterface<list<TValue>, \Throwable>
     */
    public static function some(int $count, iterable $promises): PromiseInterface
    {
        $results = [];
        $rejections = [];

        $promise = Each::of(
            $promises,
            function ($value, $idx, PromiseInterface $p) use (&$results, $count): void {
                if (State::isSettled($p)) {
                    return;
                }
                $results[$idx] = $value;
                if (count($results) >= $count) {
                    $p->resolve(null);
                }
            },
            function ($reason) use (&$rejections): void {
                $rejections[] = $reason;
            }
        )->then(
            function () use (&$results, &$rejections, $count) {
                if (count($results) !== $count) {
                    throw new AggregateException(
                        'Not enough promises to fulfill count',
                        $rejections
                    );
                }
                ksort($results);

                return array_values($results);
            }
        );

        /** @var PromiseInterface<list<TValue>, \Throwable> $promise */
        return $promise;
    }

    /**
     * Like some(), with 1 as count. However, if the promise fulfills, the
     * fulfillment value is not an array of 1 but the value directly.
     *
     * @template TValue
     * @template TReason
     *
     * @param iterable<TValue|PromiseInterface<TValue, TReason>> $promises Promises or values.
     *
     * @return PromiseInterface<TValue, \Throwable>
     */
    public static function any(iterable $promises): PromiseInterface
    {
        return self::some(1, $promises)->then(function (array $values) {
            return $values[0];
        });
    }

    /**
     * Returns a promise that is fulfilled when all of the provided promises
     * have been fulfilled or rejected.
     *
     * The returned promise is fulfilled with an array of inspection state
     * arrays.
     *
     * The config array accepts a concurrency option for lazy iterables. Other
     * config keys are ignored by this wrapper.
     *
     * @see Await::inspect for the inspection state array format.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TReason
     *
     * @param iterable<TKey, TValue|PromiseInterface<TValue, TReason>> $promises  Promises or values.
     * @param bool                                                     $recursive If true, settles newly-added entries until no unprocessed entries or pending promises remain.
     * @param array{concurrency?: int|(callable(int): int)}            $config    Configuration options.
     *
     * @return PromiseInterface<array<TKey, array{state: PromiseInterface::FULFILLED, value: TValue}|array{state: PromiseInterface::REJECTED, reason: TReason|\Throwable}>, \Throwable>
     */
    public static function settle(iterable $promises, bool $recursive = false, array $config = []): PromiseInterface
    {
        $results = [];

        $promise = Each::of(
            $promises,
            function ($value, $idx) use (&$results): void {
                $results[$idx] = ['state' => PromiseInterface::FULFILLED, 'value' => $value];
            },
            function ($reason, $idx) use (&$results): void {
                $results[$idx] = ['state' => PromiseInterface::REJECTED, 'reason' => $reason];
            },
            $config
        )->then(function () use (&$results) {
            ksort($results);

            return $results;
        });

        if (true === $recursive) {
            $promise = $promise->then(function ($results) use (&$promises, $config) {
                if (self::shouldRecurse($promises, $results)) {
                    return self::settle($promises, true, $config);
                }

                return $results;
            });
        }

        return $promise;
    }

    /**
     * @template TKey of array-key
     *
     * @param iterable<TKey, mixed> $promises Promises or values.
     * @param array<TKey, mixed>    $results  Results already collected for a pass.
     */
    private static function shouldRecurse(iterable $promises, array $results): bool
    {
        // A consumed generator cannot be traversed again, so a recursive
        // pass has nothing further to observe.
        if ($promises instanceof \Generator) {
            return false;
        }

        foreach ($promises as $key => $promise) {
            if (!array_key_exists($key, $results)) {
                return true;
            }

            if ($promise instanceof PromiseInterface && State::isPending($promise)) {
                return true;
            }
        }

        return false;
    }
}
