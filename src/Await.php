<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise;

final class Await
{
    private function __construct()
    {
    }

    /**
     * Synchronously waits on a promise to settle and returns an inspection
     * state array.
     *
     * Returns a state associative array containing a "state" key mapping to a
     * valid promise state. If the state of the promise is "fulfilled", the
     * array will contain a "value" key mapping to the fulfilled value of the
     * promise. If the promise is rejected, the array will contain a "reason"
     * key mapping to the rejection reason of the promise.
     *
     * @template TValue
     * @template TReason
     *
     * @param PromiseInterface<TValue, TReason> $promise Promise to inspect.
     *
     * @return array{state: PromiseInterface::FULFILLED, value: TValue}|array{state: PromiseInterface::REJECTED, reason: TReason|\Throwable}|array{state: PromiseInterface::PENDING}
     */
    public static function inspect(PromiseInterface $promise): array
    {
        $result = null;
        $getResult = static function () use (&$result): ?array {
            return $result;
        };

        $inspection = $promise->then(
            static function ($value) use (&$result): void {
                $result = ['state' => PromiseInterface::FULFILLED, 'value' => $value];
            },
            static function ($reason) use (&$result): void {
                $result = ['state' => PromiseInterface::REJECTED, 'reason' => $reason];
            }
        );

        try {
            $inspection->wait(false);
        } catch (\Throwable $e) {
            $settled = $getResult();
            if (null !== $settled) {
                return $settled;
            }

            if (State::isSettled($promise)) {
                try {
                    Utils::queue()->run();
                } catch (\Throwable $queueError) {
                    return ['state' => PromiseInterface::REJECTED, 'reason' => $queueError];
                }

                $settled = $getResult();
                if (null !== $settled) {
                    return $settled;
                }
            }

            return ['state' => PromiseInterface::REJECTED, 'reason' => $e];
        }

        return $getResult() ?? ['state' => $promise->getState()];
    }

    /**
     * Waits on all of the provided promises, but does not unwrap rejected
     * promises as a thrown exception.
     *
     * Returns an array of inspection state arrays keyed like the input
     * iterable.
     *
     * @see inspect for the inspection state array format.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TReason
     *
     * @param iterable<TKey, PromiseInterface<TValue, TReason>> $promises Traversable of promises to wait upon.
     *
     * @return array<TKey, array{state: PromiseInterface::FULFILLED, value: TValue}|array{state: PromiseInterface::REJECTED, reason: TReason|\Throwable}|array{state: PromiseInterface::PENDING}>
     */
    public static function inspectAll(iterable $promises): array
    {
        $results = [];
        foreach ($promises as $key => $promise) {
            $results[$key] = self::inspect($promise);
        }

        return $results;
    }

    /**
     * Waits on all of the provided promises and returns the fulfilled values.
     *
     * Returns an array that contains the value of each promise (in the same
     * order the promises were provided). An exception is thrown if any of the
     * promises are rejected.
     *
     * @template TKey of array-key
     * @template TValue
     * @template TReason
     *
     * @param iterable<TKey, PromiseInterface<TValue, TReason>> $promises Iterable of PromiseInterface objects to wait on.
     *
     * @return array<TKey, TValue>
     *
     * @throws \Throwable on error
     */
    public static function unwrap(iterable $promises): array
    {
        $results = [];
        foreach ($promises as $key => $promise) {
            $results[$key] = $promise->wait();
        }

        return $results;
    }
}
