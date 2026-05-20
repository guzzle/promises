Guzzle Promises Upgrade Guide
=============================

2.x to 3.0
----------

#### PHP Version and Dependencies

Guzzle Promises 3.0 requires PHP `^7.4 || ^8.0`. Guzzle Promises 2.x supported
PHP `^7.2.5 || ^8.0`.

If your application still supports PHP 7.2 or 7.3, continue using Guzzle
Promises 2.x until your minimum PHP version is raised.

#### Optional Promise Resolution Values

`PromiseInterface::resolve()` now accepts an optional value. Calling `resolve()`
without an argument fulfills the promise with `null`.

Custom implementations of `PromiseInterface` must update their method signature
from `resolve($value): void` to `resolve($value = null): void`.

#### Collection Helper Inputs

Promise collection helpers now require iterable inputs. Passing a single promise
or scalar value directly now throws a `TypeError`.

Wrap single promises or values in an array before passing them to `Create::iterFor()`,
`Each::of()`, `Each::ofLimit()`, `Each::ofLimitAll()`, `EachPromise`, or the
`Utils` collection helpers.

```php
use GuzzleHttp\Promise\Each;

// 2.x
$promise = Each::ofLimit($singlePromise, 2);

// 3.0
$promise = Each::ofLimit([$singlePromise], 2);
```

#### Collection Helper Config

`Utils::all()` and `Each::of()` now accept a trailing `$config` array with a
`concurrency` option for lazy iterables:

```php
use GuzzleHttp\Promise\Utils;

$promise = Utils::all($promises, false, ['concurrency' => 5]);
```

Only `concurrency` is honored by these wrappers. Callback config keys such as
`fulfilled` and `rejected` are ignored; pass callbacks to `Each::of()` directly
or use `EachPromise`.

#### Generic PHPDoc Types

`PromiseInterface`, `PromisorInterface`, and the built-in promise classes now
include generic PHPDoc annotations for static analysis tools. The first template
type represents the fulfillment value and the second represents the rejection
reason. This is a static-analysis-only change and does not alter runtime
behavior:

```php
use GuzzleHttp\Promise\PromiseInterface;

/** @var PromiseInterface<string, \Throwable> */
$promise = $factory->createPromise();

$value = $promise->wait();
```

Code that uses unparameterized promise types continues to work and is treated as
`PromiseInterface<mixed, mixed>`. If your project implements promise interfaces,
extends promise classes, or has stricter static analysis, you may need to update
your PHPDoc annotations to include the generic value and reason types.

#### Promise Inspection

`Utils::inspect()` and `Utils::inspectAll()` now return the actual rejection
reason delivered to rejection callbacks. They no longer unwrap
`RejectionException` instances to their inner reason.

For example, a promise rejected with a `RejectionException` now inspects with
that exception as the reason:

```php
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Promise\Utils;

$reason = new RejectionException('reason');
$result = Utils::inspect(new RejectedPromise($reason));

assert($result['reason'] === $reason);
```

Cancelled promises now inspect with a `CancellationException` reason. If you need
the string reason from a `RejectionException` or subclass, call `getReason()` on
the exception.

#### Late Rejection Callbacks

Rejection callbacks registered after a promise was resolved with a rejected
promise are now invoked with the nested rejection reason.

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

1.x to 2.0
----------

Guzzle Promises 2.0 is a major release that removes deprecated APIs, raises the
minimum PHP version, and adds PHP 7 parameter and return types. Applications that
only use the object-oriented API should usually need small changes. Applications
that call helper functions, implement package interfaces, extend package classes,
or pass invalid argument types need closer review.

#### PHP Version and Dependencies

Guzzle Promises 2.0 requires PHP `^7.2.5 || ^8.0`. Guzzle Promises 1.x
supported PHP `>=5.5`.

#### PHP 7 Type Hints and Return Types

Type hints and return types were added wherever possible. Please make sure:

- You pass values of the documented type when calling methods and functions.
- Classes that implement `PromiseInterface`, `PromisorInterface`, or
  `TaskQueueInterface` update method signatures to remain compatible.
- Classes that extend Guzzle Promises classes update any overridden method
  signatures to remain compatible.
- Code that expected package-specific exceptions for invalid argument types may
  now receive PHP `TypeError` exceptions instead.

#### Soft-Final Classes

All previously non-final non-exception classes are now final or annotated with
`@final`. If your code extends one of these classes, replace inheritance with
composition or implement the relevant interface directly.

#### Removed Function API

The static API was introduced in 1.4.0 to mitigate problems with functions
conflicting between global and local copies of the package. The function API was
removed in 2.0.0, along with the Composer `files` autoload entry that loaded
`src/functions_include.php`.

Replace namespaced function calls with the corresponding static methods in the
`GuzzleHttp\Promise` namespace:

```php
// Before:
use function GuzzleHttp\Promise\promise_for;

$promise = promise_for('value');

// After:
use GuzzleHttp\Promise\Create;

$promise = Create::promiseFor('value');
```

| Original Function | Replacement Method |
|-------------------|--------------------|
| `queue` | `Utils::queue` |
| `task` | `Utils::task` |
| `promise_for` | `Create::promiseFor` |
| `rejection_for` | `Create::rejectionFor` |
| `exception_for` | `Create::exceptionFor` |
| `iter_for` | `Create::iterFor` |
| `inspect` | `Utils::inspect` |
| `inspect_all` | `Utils::inspectAll` |
| `unwrap` | `Utils::unwrap` |
| `all` | `Utils::all` |
| `some` | `Utils::some` |
| `any` | `Utils::any` |
| `settle` | `Utils::settle` |
| `each` | `Each::of` |
| `each_limit` | `Each::ofLimit` |
| `each_limit_all` | `Each::ofLimitAll` |
| `!is_fulfilled` | `Is::pending` |
| `is_fulfilled` | `Is::fulfilled` |
| `is_rejected` | `Is::rejected` |
| `is_settled` | `Is::settled` |
| `coroutine` | `Coroutine::of` |

For the full 2.0 diff, see
https://github.com/guzzle/promises/compare/1.5.3...2.0.0.
