<?php
namespace GuzzleHttp\Promise;

// Don't redefine the functions if included multiple times.
if (function_exists('GuzzleHttp\Promise\promise_for')) {
    return;
}

/**
 * Creates a promise for a value if the value is not a promise.
 *
 * @param mixed $value Promise or value.
 *
 * @return PromiseInterface
 */
function promise_for($value)
{
    if ($value instanceof PromiseInterface) {
        return $value;
    }

    // Return a Guzzle promise that shadows the given promise.
    if (method_exists($value, 'then')) {
        $wfn = method_exists($value, 'wait') ? [$value, 'wait'] : null;
        $cfn = method_exists($value, 'cancel') ? [$value, 'cancel'] : null;
        $promise = new Promise($wfn, $cfn);
        $value->then([$promise, 'resolve'], [$promise, 'reject']);
        return $promise;
    }

    return new FulfilledPromise($value);
}

/**
 * Creates a rejected promise for a reason if the reason is not a promise. If
 * the provided reason is a promise, then it is returned as-is.
 *
 * @param mixed $reason Promise or reason.
 *
 * @return PromiseInterface
 */
function rejection_for($reason)
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
 * @return \Exception
 */
function exception_for($reason)
{
    return $reason instanceof \Exception
        ? $reason
        : new RejectionException($reason);
}

/**
 * Synchronously waits on a promise to resolve and returns an inspection state
 * array.
 *
 * Returns a state associative array containing a "state" key mapping to a
 * valid promise state. If the state of the promise is "fulfilled", the array
 * will contain a "value" key mapping to the fulfilled value of the promise. If
 * the promise is rejected, the array will contain a "reason" key mapping to
 * the rejection reason of the promise.
 *
 * @param PromiseInterface $promise Promise or value.
 *
 * @return array
 */
function inspect(PromiseInterface $promise)
{
    try {
        return [
            'state' => PromiseInterface::FULFILLED,
            'value' => $promise->wait()
        ];
    } catch (RejectionException $e) {
        return ['state' => 'rejected', 'reason' => $e->getReason()];
    } catch (\Exception $e) {
        return ['state' => 'rejected', 'reason' => $e];
    }
}

/**
 * Waits on all of the provided promises, but does not unwrap rejected promises
 * as thrown exception.
 *
 * Returns an array of inspection state arrays.
 *
 * @param PromiseInterface[] $promises Traversable of promises to wait upon.
 *
 * @return array
 * @see GuzzleHttp\Promise\inspect for the inspection state array format.
 */
function inspect_all($promises)
{
    $results = [];
    foreach ($promises as $promise) {
        $results[] = inspect($promise);
    }

    return $results;
}

/**
 * Waits on all of the provided promises and returns the fulfilled values.
 *
 * Returns an array that contains the value of each promise (in the same order
 * the promises were provided). An exception is thrown if any of the promises
 * are rejected.
 *
 * @param PromiseInterface[] $promises Promises to wait on.
 *
 * @return array
 * @throws \Exception on error
 */
function unwrap($promises)
{
    $results = [];
    foreach ($promises as $promise) {
        $results[] = $promise->wait();
    }

    return $results;
}

/**
 * Given an array of promises, return a promise that is fulfilled when all the
 * items in the array are fulfilled.
 *
 * The promise's fulfillment value is an array with fulfillment values at
 * respective positions to the original array. If any promise in the array
 * rejects, the returned promise is rejected with the rejection reason.
 *
 * @param PromiseInterface[]|object|mixed $promises Promises or values.
 *
 * @return Promise
 */
function all(array $promises)
{
    $waitFn = function () use ($promises) { unwrap($promises); };
    $aggregate = new Promise($waitFn);
    _then_countdown($promises, $aggregate, count($promises));

    return $aggregate;
}

/**
 * Initiate a competitive race between multiple promises or values (values will
 * become immediately fulfilled promises).
 *
 * When count amount of promises have been fulfilled, the returned promise is
 * fulfilled with an array that contains the fulfillment values of the winners
 * in order of resolution.
 *
 * @param int                             $count    Total number of promises.
 * @param PromiseInterface[]|object|mixed $promises Promises or values.
 *
 * @return Promise
 */
function some($count, array $promises)
{
    $aggregate = new Promise(function () use (&$aggregate, $promises) {
        $iter = new \ArrayIterator($promises);
        // Keep waiting until the promise has been fulfilled with N values.
        while ($iter->valid() && $aggregate->getState() === PromiseInterface::PENDING) {
            $iter->current()->wait();
            $iter->next();
        }
        if ($aggregate->getState() === PromiseInterface::PENDING) {
            throw new \RuntimeException('Not enough promises to fulfill count');
        }
    });
    _then_countdown($promises, $aggregate, $count);

    return $aggregate;
}

/**
 * Like some(), with 1 as count. However, if the promise fulfills, the
 * fulfillment value is not an array of 1 but the value directly.
 *
 * @param array $promises Promises or values.
 *
 * @return PromiseInterface
 */
function any(array $promises)
{
    return some(1, $promises)->then(function ($values) {
        return $values[0];
    });
}

/**
 * Returns a promise that is fulfilled when all of the provided promises have
 * been fulfilled or rejected.
 *
 * The returned promise is fulfilled with an array of inspection state arrays.
 *
 * @param PromiseInterface[]|object $promises Promises or values.
 *
 * @return Promise
 * @see GuzzleHttp\Promise\inspect for the inspection state array format.
 */
function settle(array $promises)
{
    $waitFn = function () use ($promises) { unwrap($promises); };
    $aggregate = new Promise($waitFn);
    $remaining = count($promises);
    $addVal = function ($idx, $value) use ($aggregate, &$remaining, &$results) {
        $results[$idx] = $value;
        if (--$remaining === 0) {
            ksort($results);
            $aggregate->resolve($results);
        }
    };

    foreach ($promises as $idx => $promise) {
        promise_for($promise)->then(
            function ($value) use ($addVal, $idx) {
                $addVal($idx, ['state' => 'fulfilled', 'value' => $value]);
            },
            function ($reason) use ($addVal, $idx) {
                $addVal($idx, ['state' => 'rejected', 'reason' => $reason]);
            }
        );
    }

    return $aggregate;
}

/**
 * Creates a promise that is resolved using a generator that yields values or
 * promises (somewhat similar to C#'s async keyword).
 *
 * When called, the coroutine function will start an instance of the generator
 * and returns a promise that is fulfilled with its final yielded value.
 *
 * Control is returned back to the generator when the yielded promise settles.
 * This can lead to less verbose code when doing lots of sequential async calls
 * with minimal processing in between.
 *
 * NOTE: Requires PHP 5.5 or greater.
 *
 *     use GuzzleHttp\Promise;
 *
 *     function createPromise($value) {
 *         return new Promise\FulfilledPromise($value);
 *     }
 *
 *     $promise = Promise\coroutine(function () {
 *         $value = (yield createPromise('a'));
 *         try {
 *             $value = (yield createPromise($value . 'b'));
 *         } catch (\Exception $e) {
 *             // The promise was rejected.
 *         }
 *         yield $value . 'c';
 *     });
 *
 *     // Outputs "abc"
 *     $promise->then(function ($v) { echo $v; });
 *
 * @param callable $generatorFn Generator function to wrap into a promise.
 *
 * @return Promise
 * @link https://github.com/petkaantonov/bluebird/blob/master/API.md#generators inspiration
 */
function coroutine(callable $generatorFn)
{
    // Waiting on a coroutine promise will use a reference to the current
    // pending promise. This value is overwritten each time the next coroutine
    // promise is yielded, meaning we need to keep calling wait on this
    // reference until the reference is not changed.
    $promise = new Promise(function () use (&$pending) {
        if ($pending) {
            next_pending:
            $prev = $pending;
            $prev->wait();
            if ($prev !== $pending) {
                $prev = $pending;
                goto next_pending;
            }
        }
    });

    $generator = $generatorFn();
    if (!($generator instanceof \Generator)) {
        throw new \InvalidArgumentException('Function must return a generator');
    }
    $yielded = $generator->current();
    _next_coroutine($yielded, $generator, $promise, $pending);

    return $promise;
}

/** @internal */
function _next_coroutine($yielded, \Generator $generator, PromiseInterface $promise, &$pending)
{
    $pending = promise_for($yielded)->then(
        function ($value) use ($generator, $promise, &$pending) {
            try {
                retry:
                $nextYield = $generator->send($value);
                if (!$generator->valid()) {
                    // No more coroutines, so this is the last yielded value.
                    $promise->resolve($value);
                } elseif (!($nextYield instanceof PromiseInterface)
                    || $nextYield->getState() === PromiseInterface::PENDING) {
                    // Non fulfilled promise, so create a new coroutine promise
                    _next_coroutine($nextYield, $generator, $promise, $pending);
                } elseif ($nextYield->getState() === PromiseInterface::REJECTED) {
                    // Cause nextYield to throw and reject that promise.
                    $nextYield->wait();
                } else {
                    // Instead of recursing for resolved promises, goto retry.
                    $value = $nextYield->wait();
                    goto retry;
                }
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        },
        function ($reason) use ($generator, $promise, &$pending) {
            try {
                $nextYield = $generator->throw(exception_for($reason));
                // The throw was caught, so keep iterating on the coroutine.
                _next_coroutine($nextYield, $generator, $promise, $pending);
            } catch(\Exception $e) {
                $promise->reject($e);
            }
        }
    );
}

/** @internal */
function _then_countdown(array $promises, PromiseInterface $aggregate, $remaining)
{
    /** @var PromiseInterface $promise */
    foreach ($promises as $idx => $promise) {
        promise_for($promise)->then(
            function ($value) use ($idx, &$remaining, &$results, $aggregate) {
                $results[$idx] = $value;
                if (--$remaining === 0) {
                    // Ensure the results are sorted by promise order.
                    ksort($results);
                    $aggregate->resolve(array_values($results));
                }
            },
            [$aggregate, 'reject']
        );
    }
}
