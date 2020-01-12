<?php
namespace GuzzleHttp\Promise;

/**
 * Get the global task queue used for promise resolution.
 *
 * This task queue MUST be run in an event loop in order for promises to be
 * settled asynchronously. It will be automatically run when synchronously
 * waiting on a promise.
 *
 * <code>
 * while ($eventLoop->isRunning()) {
 *     GuzzleHttp\Promise\queue()->run();
 * }
 * </code>
 *
 * @param TaskQueueInterface $assign Optionally specify a new queue instance.
 *
 * @return TaskQueueInterface
 */
function queue(TaskQueueInterface $assign = null)
{
    return Utils::queue($assign);
}

/**
 * Adds a function to run in the task queue when it is next `run()` and returns
 * a promise that is fulfilled or rejected with the result.
 *
 * @param callable $task Task function to run.
 *
 * @return PromiseInterface
 */
function task(callable $task)
{
    return Utils::task($task);
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
    return Utils::promiseFor($value);
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
    return Utils::rejectionFor($reason);
}

/**
 * Create an exception for a rejected promise value.
 *
 * @param mixed $reason
 *
 * @return \Throwable
 */
function exception_for($reason)
{
    return Utils::exceptionFor($reason);
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
    return Utils::iterFor($value);
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
    return Utils::inspect($promise);
}

/**
 * Waits on all of the provided promises, but does not unwrap rejected promises
 * as thrown exception.
 *
 * Returns an array of inspection state arrays.
 *
 * @param iterable<PromiseInterface> $promises Traversable of promises to wait upon.
 *
 * @return array
 * @see GuzzleHttp\Promise\inspect for the inspection state array format.
 */
function inspect_all(iterable $promises)
{
    return Utils::inspectAll($promises);
}

/**
 * Waits on all of the provided promises and returns the fulfilled values.
 *
 * Returns an array that contains the value of each promise (in the same order
 * the promises were provided). An exception is thrown if any of the promises
 * are rejected.
 *
 * @param iterable<PromiseInterface> $promises Iterable of PromiseInterface objects to wait on.
 *
 * @return array
 * @throws \Throwable on error
 */
function unwrap(iterable $promises)
{
    return Utils::unwrap($promises);
}

/**
 * Given an array of promises, return a promise that is fulfilled when all the
 * items in the array are fulfilled.
 *
 * The promise's fulfillment value is an array with fulfillment values at
 * respective positions to the original array. If any promise in the array
 * rejects, the returned promise is rejected with the rejection reason.
 *
 * @param mixed $promises  Promises or values.
 * @param bool  $recursive If true, resolves new promises that might have
 *                         been added to the stack during its own resolution.
 *
 * @return PromiseInterface
 */
function all($promises, bool $recursive = false)
{
    return Utils::all($promises, $recursive);
}

/**
 * Initiate a competitive race between multiple promises or values (values will
 * become immediately fulfilled promises).
 *
 * When count amount of promises have been fulfilled, the returned promise is
 * fulfilled with an array that contains the fulfillment values of the winners
 * in order of resolution.
 *
 * This promise is rejected with a {@see GuzzleHttp\Promise\AggregateException}
 * if the number of fulfilled promises is less than the desired $count.
 *
 * @param int   $count    Total number of promises.
 * @param mixed $promises Promises or values.
 *
 * @return PromiseInterface
 */
function some(int $count, $promises)
{
    return Utils::some($count, $promises);
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
    return Utils::any($promises);
}

/**
 * Returns a promise that is fulfilled when all of the provided promises have
 * been fulfilled or rejected.
 *
 * The returned promise is fulfilled with an array of inspection state arrays.
 *
 * @param mixed $promises Promises or values.
 *
 * @return PromiseInterface
 * @see GuzzleHttp\Promise\inspect for the inspection state array format.
 */
function settle($promises)
{
    return Utils::settle($promises);
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
 * @param mixed    $iterable
 * @param callable $onFulfilled
 * @param callable $onRejected
 *
 * @return PromiseInterface
 */
function each(
    $iterable,
    callable $onFulfilled = null,
    callable $onRejected = null
) {
    return Utils::each($iterable, $onFulfilled, $onRejected);
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
 * @return PromiseInterface
 */
function each_limit(
    $iterable,
    $concurrency,
    callable $onFulfilled = null,
    callable $onRejected = null
) {
    return Utils::eachLimit($iterable, $concurrency, $onFulfilled, $onRejected);
}

/**
 * Like each_limit, but ensures that no promise in the given $iterable argument
 * is rejected. If any promise is rejected, then the aggregate promise is
 * rejected with the encountered rejection.
 *
 * @param mixed        $iterable
 * @param int|callable $concurrency
 * @param callable     $onFulfilled
 *
 * @return PromiseInterface
 */
function each_limit_all(
    $iterable,
    $concurrency,
    callable $onFulfilled = null
) {
    return Utils::eachLimitAll($iterable, $concurrency, $onFulfilled);
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
    return Utils::isFulfilled($promise);
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
    return Utils::isRejected($promise);
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
    return Utils::isSettled($promise);
}

/**
 * @see Coroutine
 *
 * @param callable $generatorFn
 *
 * @return PromiseInterface
 */
function coroutine(callable $generatorFn)
{
    return Utils::coroutine($generatorFn);
}
