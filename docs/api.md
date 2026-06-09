# Promise API

Promise APIs are documented for static analysis as
`PromiseInterface<TValue, TReason>`. `TValue` is the fulfillment value type and
`TReason` is the rejection reason type. This typing is PHPDoc-only and does not
change runtime behavior.

### Promise Collection Helpers

`Utils::all()` returns a promise that fulfills with all fulfillment values or
rejects when any input promise rejects.

For lazy iterables, pass `concurrency` to limit how many items are pulled from
the iterable at one time:

```php
use GuzzleHttp\Promise\Utils;

$promise = Utils::all($promises, false, ['concurrency' => 5]);
```

`Each::of()` accepts the same config when you need callbacks instead of
collected values. Fulfillment callbacks receive the fulfilled value, the
iterable key, and the aggregate promise. Rejection callbacks receive the
rejection reason, the iterable key, and the aggregate promise. Callbacks may
declare only the arguments they use, and their return values are ignored.

```php
use GuzzleHttp\Promise\Each;

$promise = Each::of($promises, $onFulfilled, $onRejected, ['concurrency' => 5]);
```

This limits lazy promise creation. It does not throttle promises that have
already been created or started. Callback config keys such as `fulfilled` and
`rejected` are ignored by these wrappers; pass callbacks to `Each::of()`
directly or use `EachPromise`.

For HTTP request concurrency, use `GuzzleHttp\Pool` from `guzzlehttp/guzzle`.


### Promise

When creating a promise object, you can provide an optional `$waitFn` and
`$cancelFn`. `$waitFn` is a function that is invoked with no arguments and is
expected to resolve the promise. `$cancelFn` is a function with no arguments
that is expected to cancel the computation of a promise. It is invoked when the
`cancel()` method of a promise is called.

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise(
    function () use (&$promise) {
        $promise->resolve('waited');
    },
    function () {
        // do something that will cancel the promise computation (e.g., close
        // a socket, cancel a database query, etc...)
    }
);

assert('waited' === $promise->wait());
```

A promise has the following methods:

- `then(?callable $onFulfilled = null, ?callable $onRejected = null) : PromiseInterface`

  Appends fulfillment and rejection handlers to the promise, and returns a new
  promise resolving to the return value of the called handler. If a handler is
  omitted, the original fulfillment value or rejection reason is forwarded.

- `otherwise(callable $onRejected) : PromiseInterface`

  Appends a rejection handler callback to the promise, and returns a new promise
  resolving to the return value of the callback if it is called, or to its
  original fulfillment value if the promise is instead fulfilled.

- `wait($unwrap = true) : mixed`

  Synchronously waits on the promise to complete.

  `$unwrap` controls whether or not the value of the promise is returned for a
  fulfilled promise or if an exception is thrown if the promise is rejected.
  This is set to `true` by default.

- `cancel()`

  Attempts to cancel the promise if possible. The promise being cancelled and
  the parent most ancestor that has not yet been resolved will also be
  cancelled. Any promises waiting on the cancelled promise to resolve will also
  be cancelled.

- `getState() : string`

  Returns the state of the promise. One of `pending`, `fulfilled`, or
  `rejected`.

- `resolve($value = null)`

  Fulfills the promise with the given `$value`, or with `null` if no value is
  given.

- `reject($reason)`

  Rejects the promise with the given `$reason`.


### FulfilledPromise

A fulfilled promise can be created to represent a promise that has been
fulfilled.

```php
use GuzzleHttp\Promise\FulfilledPromise;

$promise = new FulfilledPromise('value');

// Fulfilled callbacks are immediately invoked.
$promise->then(function ($value) {
    echo $value;
});
```


### RejectedPromise

A rejected promise can be created to represent a promise that has been
rejected.

```php
use GuzzleHttp\Promise\RejectedPromise;

$promise = new RejectedPromise('Error');

// Rejected callbacks are immediately invoked.
$promise->then(null, function ($reason) {
    echo $reason;
});
```
