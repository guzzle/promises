# Promise Quick Start

A *promise* represents the eventual result of an asynchronous operation. The
primary way of interacting with a promise is through its `then` method, which
registers callbacks to receive either a promise's eventual value or the reason
why the promise cannot be fulfilled.

### Callbacks

Callbacks are registered with the `then` method by providing an optional
`$onFulfilled` followed by an optional `$onRejected` function.


```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise->then(
    // $onFulfilled
    function ($value) {
        echo 'The promise was fulfilled.';
    },
    // $onRejected
    function ($reason) {
        echo 'The promise was rejected.';
    }
);
```

*Resolving* a promise means that you either fulfill a promise with a *value* or
reject a promise with a *reason*. Resolving a promise triggers callbacks
registered with the promise's `then` method. These callbacks are triggered
only once and in the order in which they were added.

### Resolving a Promise

Promises are fulfilled using the `resolve($value = null)` method. Calling
`resolve()` without an argument fulfills the promise with `null`. Resolving a
promise with any value other than a `GuzzleHttp\Promise\RejectedPromise` will
trigger all of the onFulfilled callbacks (resolving a promise with a rejected
promise will reject the promise and trigger the `$onRejected` callbacks).

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise
    ->then(function ($value) {
        // Return a value and don't break the chain
        return "Hello, " . $value;
    })
    // This then is executed after the first then and receives the value
    // returned from the first then.
    ->then(function ($value) {
        echo $value;
    });

// Resolving the promise triggers the $onFulfilled callbacks and outputs
// "Hello, reader."
$promise->resolve('reader.');
```

### Promise Forwarding

Promises can be chained one after the other. Each then in the chain is a new
promise. The return value of a promise is what's forwarded to the next
promise in the chain. Returning a promise in a `then` callback will cause the
subsequent promises in the chain to only be fulfilled when the returned promise
has been fulfilled. The next promise in the chain will be invoked with the
resolved value of the promise.

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$nextPromise = new Promise();

$promise
    ->then(function ($value) use ($nextPromise) {
        echo $value;
        return $nextPromise;
    })
    ->then(function ($value) {
        echo $value;
    });

// Triggers the first callback and outputs "A"
$promise->resolve('A');
// Triggers the second callback and outputs "B"
$nextPromise->resolve('B');
```

### Promise Rejection

When a promise is rejected, the `$onRejected` callbacks are invoked with the
rejection reason.

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise->then(null, function ($reason) {
    echo $reason;
});

$promise->reject('Error!');
// Outputs "Error!"
```

### Rejection Forwarding

If an exception is thrown in an `$onRejected` callback, subsequent
`$onRejected` callbacks are invoked with the thrown exception as the reason.

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise->then(null, function ($reason) {
    throw new Exception($reason);
})->then(null, function ($reason) {
    assert($reason->getMessage() === 'Error!');
});

$promise->reject('Error!');
```

You can also forward a rejection down the promise chain by returning a
`GuzzleHttp\Promise\RejectedPromise` in either an `$onFulfilled` or
`$onRejected` callback.

```php
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;

$promise = new Promise();
$promise->then(null, function ($reason) {
    return new RejectedPromise($reason);
})->then(null, function ($reason) {
    assert($reason === 'Error!');
});

$promise->reject('Error!');
```

If an exception is not thrown in a `$onRejected` callback and the callback
does not return a rejected promise, downstream `$onFulfilled` callbacks are
invoked using the value returned from the `$onRejected` callback.

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise
    ->then(null, function ($reason) {
        return "It's ok";
    })
    ->then(function ($value) {
        assert($value === "It's ok");
    });

$promise->reject('Error!');
```


## Synchronous Wait

You can synchronously force promises to complete using a promise's `wait`
method. When creating a promise, you can provide a wait function that is used
to synchronously force a promise to complete. When a wait function is invoked
it is expected to deliver a value to the promise or reject the promise. If the
wait function does not deliver a value, then an exception is thrown. The wait
function provided to a promise constructor is invoked when the `wait` function
of the promise is called.

```php
$promise = new Promise(function () use (&$promise) {
    $promise->resolve('foo');
});

// Calling wait will return the value of the promise.
echo $promise->wait(); // outputs "foo"
```

If a throwable is encountered while invoking the wait function of a promise,
the promise is rejected with the throwable and the throwable is thrown.

```php
$promise = new Promise(function () use (&$promise) {
    throw new Exception('foo');
});

$promise->wait(); // throws the exception.
```

Calling `wait` on a promise that has been fulfilled will not trigger the wait
function. It will simply return the previously resolved value.

```php
$promise = new Promise(function () { die('this is not called!'); });
$promise->resolve('foo');
echo $promise->wait(); // outputs "foo"
```

Calling `wait` on a promise that has been rejected will throw. If the rejection
reason is an instance of `\Throwable` the reason is thrown.
Otherwise, a `GuzzleHttp\Promise\RejectionException` is thrown and the reason
can be obtained by calling the `getReason` method of the exception.

```php
$promise = new Promise();
$promise->reject('foo');
$promise->wait();
```

> PHP Fatal error:  Uncaught exception 'GuzzleHttp\Promise\RejectionException' with message 'The promise was rejected with value: foo'

### Unwrapping a Promise

When synchronously waiting on a promise, you are joining the state of the
promise into the current state of execution (i.e., return the value of the
promise if it was fulfilled or throw an exception if it was rejected). This is
called "unwrapping" the promise. Waiting on a promise will by default unwrap
the promise state.

You can force a promise to resolve and *not* unwrap the state of the promise
by passing `false` to the first argument of the `wait` function:

```php
$promise = new Promise();
$promise->reject('foo');
// This will not throw an exception. It simply ensures the promise has
// been resolved.
$promise->wait(false);
```

When unwrapping a promise, the resolved value of the promise will be waited
upon until the unwrapped value is not a promise. This means that if you resolve
promise A with a promise B and unwrap promise A, the value returned by the
wait function will be the value delivered to promise B.

**Note**: when you do not unwrap the promise, no value is returned.


### Inspecting a Promise

`Utils::inspect($promise)` waits for a promise to settle and returns an array
describing its final state. For rejected promises, the `reason` entry is the
actual rejection reason delivered to rejection callbacks.

This means `RejectionException` and subclasses are not unwrapped by `inspect()`.
For example, cancelled promises inspect with a `CancellationException` reason.


## Cancellation

You can cancel a promise that has not yet been fulfilled using the `cancel()`
method of a promise. When creating a promise you can provide an optional
cancel function that when invoked cancels the action of computing a resolution
of the promise.
