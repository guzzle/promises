# Implementation Notes

### Promise Resolution and Chaining Is Handled Iteratively

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

## A Promise Is the Deferred

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
$promise->resolve('foo');
// prints "foo"
```
