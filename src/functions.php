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
 * Waits on all of the provided promises, but does not unwrap rejected promises
 * as thrown exception.
 *
 * Returns an array of results where each result is an associative array that
 * contains a 'state' key mapping to either "fulfilled" or "rejected", and
 * either a 'value' key mapping to a fulfilled promise value or a 'reason' key
 * mapping to a rejected promise reason.
 *
 * @param PromiseInterface[] $promises Promises or values.
 *
 * @return array
 */
function wait(array $promises)
{
    $results = [];
    foreach ($promises as $promise) {
        try {
            $results[] = [
                'state' => 'fulfilled',
                'value' => promise_for($promise)->wait()
            ];
        } catch (RejectionException $e) {
            $results[] = ['state' => 'rejected', 'reason' => $e->getReason()];
        } catch (\Exception $e) {
            $results[] = ['state' => 'rejected','reason' => $e];
        }
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
 * @param PromiseInterface[] $promises          Array of promises or values to
 *                                              wait on.
 * @param mixed              $defaultResolution Default value to fulfill a
 *                                              promise with if the promise has
 *                                              no internal wait function.
 * @return array
 * @throws \Exception on error.
 */
function join(array $promises, $defaultResolution = null)
{
    $results = [];
    if (func_num_args() < 2) {
        // Don't provide a default if none was provided to this function.
        foreach ($promises as $promise) {
            $results[] = promise_for($promise)->wait(true);
        }
    } else {
        foreach ($promises as $promise) {
            $results[] = promise_for($promise)->wait(true, $defaultResolution);
        }
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
    $waitFn = function () use ($promises) { join($promises); };
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
 * The returned promise is fulfilled with an array that contains associative
 * arrays for each promise in the order in which they were provided. Each
 * result in the array is an associative array that contains a 'state' key
 * mapping to either "fulfilled" or "rejected", and either a 'value' key
 * mapping to a fulfilled promise value or a 'reason' key mapping to a rejected
 * promise reason.
 *
 * @param PromiseInterface[]|object $promises Promises or values.
 *
 * @return Promise
 */
function settle(array $promises)
{
    $waitFn = function () use ($promises) { join($promises); };
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
    $promise = new Promise();
    $generator = $generatorFn();
    if (!($generator instanceof \Iterator)) {
        throw new \InvalidArgumentException('Function must return an iterator');
    }
    $yielded = $generator->current();
    _next_coroutine($yielded, $generator, $promise);

    return $promise;
}

/** @internal */
function _next_coroutine($yielded, \Generator $generator, PromiseInterface $promise)
{
    promise_for($yielded)->then(
        function ($value) use ($generator, $promise) {
            try {
                $nextYield = $generator->send($value);
                if (!$generator->valid()) {
                    $promise->resolve($value);
                } else {
                    _next_coroutine($nextYield, $generator, $promise);
                }
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        },
        function ($reason) use ($generator, $promise) {
            try{
                $nextYield = $generator->throw(exception_for($reason));
                _next_coroutine($nextYield, $generator, $promise);
            } catch(\Exception $e){
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
