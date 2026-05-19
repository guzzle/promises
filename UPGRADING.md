# Guzzle Promises Upgrade Guide


## 2.x to 3.0

### PHP Version

Guzzle Promises 3.0 requires PHP 7.4 or later. PHP 7.2 and 7.3 are no longer supported.


### Promise Inspection

`Utils::inspect()` and `Utils::inspectAll()` now return the actual rejection reason delivered to rejection callbacks. They no longer unwrap `RejectionException` instances to their inner reason.

For example, a promise rejected with a `RejectionException` now inspects with that exception as the reason:

```php
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Promise\Utils;

$reason = new RejectionException('reason');
$result = Utils::inspect(new RejectedPromise($reason));

assert($result['reason'] === $reason);
```

Cancelled promises now inspect with a `CancellationException` reason. If you need the string reason from a `RejectionException` or subclass, call `getReason()` on the exception.


### Late Rejection Callbacks

Rejection callbacks registered after a promise was resolved with a rejected promise are now invoked with the nested rejection reason.

```php
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\Utils;

$promise = new Promise();
$promise->resolve(new RejectedPromise('reason'));

$promise->then(null, function ($reason): void {
    assert($reason === 'reason');
});

Utils::queue()->run();
```
