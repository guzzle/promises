# Promise Interoperability

This library works with foreign promises that have a `then` method. This means
you can use Guzzle promises with [React promises](https://github.com/reactphp/promise)
for example. When a foreign promise is returned inside of a then method
callback, promise resolution will occur recursively.

```php
// Create a React promise
$deferred = new React\Promise\Deferred();
$reactPromise = $deferred->promise();

// Create a Guzzle promise that is fulfilled with a React promise.
$guzzlePromise = new GuzzleHttp\Promise\Promise();
$guzzlePromise->then(function ($value) use ($reactPromise) {
    // Do something something with the value...
    // Return the React promise
    return $reactPromise;
});
```

Please note that wait and cancel chaining is no longer possible when forwarding
a foreign promise. You will need to wrap a third-party promise with a Guzzle
promise in order to utilize wait and cancel functions with foreign promises.


### Event Loop Integration

In order to keep the stack size constant, Guzzle promises are resolved
asynchronously using a task queue. When waiting on promises synchronously, the
task queue will be automatically run to ensure that the blocking promise and
any forwarded promises are resolved. When using promises asynchronously in an
event loop, you will need to run the task queue on each tick of the loop. If
you do not run the task queue, then promises will not be resolved.

You can run the task queue using the `run()` method of the global task queue
instance.

```php
// Get the global task queue
$queue = GuzzleHttp\Promise\Utils::queue();
$queue->run();
```

For example, you could use Guzzle promises with React using a short periodic
timer. Avoid zero-interval timers because they may keep the loop busy even when
there is no promise work to run.

```php
$loop = React\EventLoop\Factory::create();
$loop->addPeriodicTimer(0.01, [$queue, 'run']);
```
