# Promise API

This page summarizes the main classes and helpers provided by `guzzlehttp/promises`.

## Promise Collection Helpers

`GuzzleHttp\Promise\Utils` provides helpers for working with multiple promises.

- `Utils::all()` waits for all promises to fulfill or rejects when one rejects.
- `Utils::some()` waits for a specific number of promises to fulfill.
- `Utils::any()` waits for the first fulfilled promise.
- `Utils::settle()` waits for every promise and returns inspection arrays for both fulfilled and rejected promises.
- `Utils::unwrap()` waits for promises and returns fulfilled values, throwing if any promise rejects.
- `Utils::inspect()` returns an inspection array for a single promise.
- `Utils::inspectAll()` returns inspection arrays for multiple promises.
- `Utils::queue()` returns the global task queue.

## Promise

`GuzzleHttp\Promise\Promise` is the main promise implementation. It supports `then()`, `otherwise()`, `wait()`, `cancel()`, `resolve()`, and `reject()`.

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise->then(function ($value) {
    echo $value;
});

$promise->resolve('done');
```

## FulfilledPromise

`GuzzleHttp\Promise\FulfilledPromise` is already fulfilled when constructed. Use it when an API needs a promise but the value is already available.

## RejectedPromise

`GuzzleHttp\Promise\RejectedPromise` is already rejected when constructed. Use it to forward or return an immediate rejection.

## Inspecting Promises

`Utils::inspect()` returns an array describing a promise state. Fulfilled promises include a `value`; rejected promises include a `reason`.

```php
$state = GuzzleHttp\Promise\Utils::inspect($promise);
```
