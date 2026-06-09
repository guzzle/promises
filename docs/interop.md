# Promise Interoperability

Guzzle promises work with foreign promises that expose a `then()` method. If a foreign promise is returned from a callback, Guzzle promise resolution follows that promise.

```php
$guzzlePromise->then(function ($value) use ($foreignPromise) {
    return $foreignPromise;
});
```

Waiting and cancellation cannot be chained through all foreign promise implementations. Wrap third-party promises in a Guzzle promise if your code needs `wait()` or `cancel()` behavior.

## Event Loop Integration

Guzzle promises use a task queue to keep promise resolution iterative. When using promises asynchronously in an event loop, run the queue on each loop tick. If the queue is not run, pending callbacks may not be delivered.

```php
$queue = GuzzleHttp\Promise\Utils::queue();
$queue->run();
```

With ReactPHP, use a short periodic timer. Avoid zero-interval timers because they may keep the loop busy when there is no promise work to run.

```php
$loop = React\EventLoop\Factory::create();
$loop->addPeriodicTimer(0.01, [$queue, 'run']);
```
