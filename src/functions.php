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
 * Returns an iterator for the given value.
 *
 * @param mixed $value
 *
 * @return \Iterator
 */
function iter_for($value)
{
    if ($value instanceof \Iterator) {
        return $value;
    } elseif (is_array($value)) {
        return new \ArrayIterator($value);
    } else {
        return new \ArrayIterator([$value]);
    }
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
    if ($promise->getState() === PromiseInterface::FULFILLED) {
        return [
            'state' => PromiseInterface::FULFILLED,
            'value' => $promise->wait()
        ];
    }

    $promise->then(
        function ($value) use (&$result) {
            $result = ['state' => 'fulfilled', 'value' => $value];
        },
        function ($reason) use (&$result) {
            $result = ['state' => 'rejected', 'reason' => $reason];
        }
    );

    $promise->wait(false);

    return $result;
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
    foreach ($promises as $key => $promise) {
        $results[$key] = inspect($promise);
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
 * @param mixed $promises Iterable of PromiseInterface objects to wait on.
 *
 * @return array
 * @throws \Exception on error
 */
function unwrap($promises)
{
    $results = [];
    foreach ($promises as $key => $promise) {
        $results[$key] = $promise->wait();
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
 * @param mixed $promises Promises or values.
 *
 * @return Promise
 */
function all($promises)
{
    $results = [];
    return each(
        $promises,
        function ($value, $idx) use (&$results) {
            $results[$idx] = $value;
        },
        function ($reason, $idx, Promise $aggregate) {
            $aggregate->reject($reason);
        }
    )->then(function () use (&$results) {
        ksort($results);
        return $results;
    });
}

/**
 * Initiate a competitive race between multiple promises or values (values will
 * become immediately fulfilled promises).
 *
 * When count amount of promises have been fulfilled, the returned promise is
 * fulfilled with an array that contains the fulfillment values of the winners
 * in order of resolution.
 *
 * @param int   $count    Total number of promises.
 * @param mixed $promises Promises or values.
 *
 * @return Promise
 */
function some($count, $promises)
{
    $results = [];

    return each(
        $promises,
        function ($value, $idx, PromiseInterface $p) use (&$results, $count) {
            $results[$idx] = $value;
            if (count($results) === $count) {
                $p->resolve(null);
            }
        },
        function ($reason, $idx, Promise $aggregate) {
            $aggregate->reject($reason);
        }
    )->then(
        function () use (&$results, $count) {
            if (count($results) !== $count) {
                return new RejectedPromise('Not enough promises to fulfill count');
            }
            ksort($results);
            return array_values($results);
        }
    );
}

/**
 * Like some(), with 1 as count. However, if the promise fulfills, the
 * fulfillment value is not an array of 1 but the value directly.
 *
 * @param mixed $promises Promises or values.
 *
 * @return PromiseInterface
 */
function any($promises)
{
    return some(1, $promises)->then(function ($values) { return $values[0]; });
}

/**
 * Returns a promise that is fulfilled when all of the provided promises have
 * been fulfilled or rejected.
 *
 * The returned promise is fulfilled with an array of inspection state arrays.
 *
 * @param mixed $promises Promises or values.
 *
 * @return Promise
 * @see GuzzleHttp\Promise\inspect for the inspection state array format.
 */
function settle($promises)
{
    $results = [];

    return each(
        $promises,
        function ($value, $idx) use (&$results) {
            $results[$idx] = ['state' => 'fulfilled', 'value' => $value];
        },
        function ($reason, $idx) use (&$results) {
            $results[$idx] = ['state' => 'rejected', 'reason' => $reason];
        }
    )->then(function () use (&$results) {
        ksort($results);
        return $results;
    });
}

/**
 * Given an iterator that yields promises or values, returns a promise that is
 * fulfilled with a null value when the iterator has been consumed or the
 * aggregate promise has been fulfilled or rejected.
 *
 * $onFulfilled is a function that accepts the fulfilled value, iterator
 * index, and the aggregate promise. The callback can invoke any necessary side
 * effects and choose to resolve or reject the aggregate promise if needed.
 *
 * $onRejected is a function that accepts the rejection reason, iterator
 * index, and the aggregate promise. The callback can invoke any necessary side
 * effects and choose to resolve or reject the aggregate promise if needed.
 *
 * @param mixed    $iterable    Iterator or array to iterate over.
 * @param callable $onFulfilled
 * @param callable $onRejected
 *
 * @return Promise
 */
function each(
    $iterable,
    callable $onFulfilled = null,
    callable $onRejected = null
) {
    return (new EachPromise($iterable, [
        'fulfilled' => $onFulfilled,
        'rejected'  => $onRejected
    ]))->promise();
}

/**
 * Like each, but only allows a certain number of outstanding promises at any
 * given time.
 *
 * $concurrency may be an integer or a function that accepts the number of
 * pending promises and returns a numeric concurrency limit value to allow for
 * dynamic a concurrency size.
 *
 * @param mixed        $iterable
 * @param int|callable $concurrency
 * @param callable     $onFulfilled
 * @param callable     $onRejected
 *
 * @return mixed
 */
function each_limit(
    $iterable,
    $concurrency,
    callable $onFulfilled = null,
    callable $onRejected = null
) {
    return (new EachPromise($iterable, [
        'fulfilled'   => $onFulfilled,
        'rejected'    => $onRejected,
        'concurrency' => $concurrency
    ]))->promise();
}

/**
 * Returns true if a promise is fulfilled.
 *
 * @param PromiseInterface $promise
 *
 * @return bool
 */
function is_fulfilled(PromiseInterface $promise)
{
    return $promise->getState() === PromiseInterface::FULFILLED;
}

/**
 * Returns true if a promise is rejected.
 *
 * @param PromiseInterface $promise
 *
 * @return bool
 */
function is_rejected(PromiseInterface $promise)
{
    return $promise->getState() === PromiseInterface::REJECTED;
}

/**
 * Returns true if a promise is fulfilled or rejected.
 *
 * @param PromiseInterface $promise
 *
 * @return bool
 */
function is_settled(PromiseInterface $promise)
{
    return $promise->getState() !== PromiseInterface::PENDING;
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
    $queue = [];
    $promise = new Promise(
        function () use (&$queue) {
            while ($promise = array_pop($queue)) {
                $promise->wait(false);
            }
        },
        function () use (&$queue) {
            while ($promise = array_pop($queue)) {
                $promise->cancel();
            }
        }
    );

    $generator = $generatorFn();
    __next_coroutine($generator->current(), $generator, $promise, $queue);

    return $promise;
}

/** @internal */
function __next_coroutine(
    $yielded,
    \Generator $generator,
    PromiseInterface $promise,
    array &$queue
) {
    $queue[] = promise_for($yielded)->then(
        function ($value) use ($generator, $promise, &$queue) {
            // No longer need to track the last added promise.
            array_pop($queue);
            __process_coroutine($value, $generator, $promise, $queue);
        },
        function ($reason) use ($generator, $promise, &$queue) {
            // No longer need to track the last added promise.
            array_pop($queue);
            __process_coroutine($reason, $generator, $promise, $queue, true);
        }
    );
}

function __process_coroutine(
    $value,
    \Generator $generator,
    PromiseInterface $promise,
    array &$queue,
    $isRejection = false
) {
    if ($isRejection) {
        goto reject;
    }

    send_value: {
        try {
            $nextYield = $generator->send($value);
            if ($generator->valid()) {
                goto process_yield;
            }
            $promise->resolve($value);
            return;
        } catch (\Exception $value) {
            goto reject;
        }
    }

    process_yield: {
        // Need to use a promise to resolve a nested promise.
        if (!($nextYield instanceof PromiseInterface)
            || $nextYield->getState() === PromiseInterface::PENDING
        ) {
            // Non fulfilled promise, so create a new coroutine promise
            __next_coroutine($nextYield, $generator, $promise, $queue);
            return;
        }

        // Process the rejection
        if ($nextYield->getState() === PromiseInterface::REJECTED) {
            $value = inspect($nextYield)['reason'];
            goto reject;
        }

        // Fulfilled promise, so get the value and send it to the gen.
        $value = $nextYield->wait();
        goto send_value;
    }

    reject: {
        try {
            $nextYield = $generator->throw(exception_for($value));
            // The throw was caught, so keep iterating on the coroutine
            goto process_yield;
        } catch (\Exception $e) {
            $promise->reject($e);
        }
    }
}
