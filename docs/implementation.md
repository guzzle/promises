# Implementation Notes

These notes explain design choices in `guzzlehttp/promises`. They are useful when debugging promise chains or integrating promises into event loops.

## Resolution and Chaining Are Iterative

Promise resolution and chaining are handled iteratively rather than recursively. This allows very long chains without growing the PHP call stack.

When a promise is fulfilled or rejected with a non-promise value, pending handlers are moved through the chain and delivered without recursion. When a promise resolves to another promise, pending handlers are transferred to the new promise and delivered when that promise resolves.

## A Promise Is Also the Deferred

Some promise libraries use separate deferred and promise objects. Guzzle's `Promise` is both: the same object receives callbacks and can also be resolved or rejected.

This design lets promises of the same class transfer internal handler state efficiently while keeping handlers externally immutable.

```php
use GuzzleHttp\Promise\Promise;

$promise = new Promise();
$promise->then(function ($value) {
    echo $value;
});

$promise->resolve('done');
```
