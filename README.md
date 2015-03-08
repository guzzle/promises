# Guzzle Promises

[A+ promise](https://promisesaplus.com/) implementation that handles promise
chaining and resolution iteratively, allowing for "infinite" promise chaining
while keeping the stack size constant.


## Why another promise library?

This library differs from many existing promise implementations in a few
important ways:

1. Promise resolution and chaining is handled iteratively
2. A Guzzle promise is the deferred.
3. A Guzzle promise supports a synchronous wait function.
4. A Guzzle promise can interop with any object that has a `then` function.


## Promise resolution and chaining is handled iteratively

By shuffling pending handlers from one owner to another, promises are
resolved iteratively, allowing for "infinite" then chaining.

```php
<?php
require 'vendor/autoload.php';

use GuzzleHttp\Promise\Promise;

$parent = new Promise();
$p = $parent;

for ($i = 0; $i < 1000; $i++) {
    $p = $p->then(function ($v) {
        // The stack size remains constant (a good thing)
        echo xdebug_get_stack_depth() . ', ';
        return $v + 1;
    });
}

$parent->resolve(0);
var_dump($p->wait()); // int(1000)

```

When a promise is fulfilled or rejected with a non-promise value, the promise
then takes ownership of the handlers of each child promise and delivers values
down the chain without using recursion.

When a promise is resolved with another promise, the original promise transfers
all of its pending handlers to the new promise. When the new promise is
eventually resolved, all of the pending handlers are delivered the forwarded
value.


## A promise is the deferred.

Some promise libraries implement promises using a deferred object to represent
a computation and a promise object to represent the delivery of the result of
the computation. This is a nice separation of computation and delivery because
consumers of the promise cannot modify the value that will be eventually
delivered.

One side effect of being able to implement promise resolution and chaining
iteratively is that you need to be able for one promise to reach into the state
of another promise to shuffle around ownership of handlers. In order to achieve
this without making the handlers of a promise publicly mutable, a promise is
also the deferred value, allowing promises of the same parent class to reach
into and modify the private properties of promises of the same type. While this
does allow consumers of the value to modify the resolution or rejection of the
deferred, it is a small price to pay for keeping the stack size constant.

```php
$promise = new Promise();
$promise->then(function ($value) { echo $value; });
// The promise is the deferred value, so you can deliver a value to it.
$promise->deliver('foo');
// prints "foo"
```


## Synchronous wait function

When creating a promise, you can provide a wait function that is used to
synchronously force a promise to complete. When a wait function is invoked it
is expected to deliver a value to the promise or reject the promise. If the
wait function does not deliver a value, then an exception is thrown. The wait
function provided to a promise constructor is invoked when the `wait` function
of the promise is called.

```php
$promise = new Promise(function () use (&$promise) {
    $promise->deliver('foo');
});

// Calling wait will return the value of the promise.
echo $promise->wait(); // outputs "foo"
```

If an exception is encountered while invoking the wait function of a promise,
the promise is rejected with the exception and the exception is thrown.

```php
$promise = new Promise(function () use (&$promise) {
    throw new \Exception('foo');
});

$promise->wait(); // throws the exception.
```

Calling `wait` on a promise that has been fulfilled will not trigger the wait
function. It will simply return the previously delivered value.

```php
$promise = new Promise(function () { die('this is not called!'); });
$promise->deliver('foo');
echo $promise->wait(); // outputs "foo"
```

Calling `wait` on a promise that has been rejected will throw an exception. If
the rejection reason is an instance of `\Exception` the reason is thrown.
Otherwise, a `GuzzleHttp\Promise\RejectionException` is thrown and the reason
can be obtained by calling the `getReason` method of the exception.

```php
$promise = new Promise();
$promise->reject('foo');
$promise->wait();
```

> PHP Fatal error:  Uncaught exception 'GuzzleHttp\Promise\RejectionException' with message 'The promise was rejected with value: foo'


### Unwrapping a promise

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

When unwrapping a promise, the delivered value of the promise will be waited
upon until the unwrapped value is not a promise. This means that if you resolve
promise A with a promise B and unwrap promise A, the value returned by the
wait function will be the value delivered to promise B.

**Note**: when you do not unwrap the promise, no value is returned.


### Default value

Not all promises have an internal wait function that can be used to
synchronously resolve a promise. Calling `wait` on such a promise will cause
a `\LogicException` to be thrown.

```php
$promise = new Promise();
echo $promise->wait();
```

> PHP Fatal error:  Uncaught exception 'LogicException' with message 'Cannot wait on a promise that has no internal wait function'

You can work around this when calling the `wait` function of a promise by
providing a default value to resolve the promise with if there is no internal
wait function.

```php
$promise = new Promise();
echo $promise->wait(true, 'hello!'); // outputs "hello!"
```


## Promise interop

This library works with foreign promises that have a `then` method. This means
you can use Guzzle promises with [React promises](https://github.com/reactphp/promise)
for example. When a foreign promise is returned inside of a then method
callback, promise resolution will occur recursively.

```php
// Create a React promise
$deferred = new React\Promise\Deferred();
$reactPromise = $deferred->promise();

// Create a Guzzle promise that is fulfilled with a React promise.
$guzzlePromise = new \GuzzleHttp\Promise\Promise();
$guzzlePromise->then(function ($value) use ($reactPromise) {
    // Do something something with the value...
    // Return the React promise
    return $reactPromise;
});
```

Please note that wait and cancel chaining is no longer possible when forwarding
a foreign promise. You will need to wrap a third-party promise with a Guzzle
promise in order to utilize wait and cancel functions with foreign promises.
