# Promise Quick Start

A promise represents the eventual result of an asynchronous operation. Register callbacks with `then()` to receive either the fulfilled value or the rejection reason.

## Callbacks

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise->then(
    function ($value) {
        echo 'The promise was fulfilled with ' . $value;
    },
    function ($reason) {
        echo 'The promise was rejected with ' . $reason;
    }
);

$promise->resolve('a value');
```

## Resolving a Promise

Call `resolve($value)` to fulfill a promise. Resolving a promise triggers fulfillment callbacks once, in the order they were added.

```php
$promise = new Promise();
$promise
    ->then(function ($value) {
        return 'Hello, ' . $value;
    })
    ->then(function ($value) {
        echo $value;
    });

$promise->resolve('reader.');
```

## Rejecting a Promise

Call `reject($reason)` to reject a promise. Rejection callbacks receive the reason.

```php
$promise = new Promise();
$promise->then(null, function ($reason) {
    echo $reason;
});

$promise->reject('Error!');
```

## Forwarding

Callbacks can return values or promises. Returned values are forwarded to the next promise in the chain. Returned promises delay the next callback until that promise is fulfilled or rejected.

```php
$promise = new Promise();
$next = new Promise();

$promise
    ->then(function () use ($next) {
        return $next;
    })
    ->then(function ($value) {
        echo $value;
    });

$promise->resolve();
$next->resolve('done');
```

## Synchronous Wait

Call `wait()` to force a promise to complete synchronously.

```php
$value = $promise->wait();
```

When creating a promise, you can provide a wait function that delivers a value or rejection when `wait()` is called.

## Cancellation

Promises may be cancelled with `cancel()`. Cancellation calls the optional cancellation function passed to the promise constructor and attempts to cancel pending child promises.
