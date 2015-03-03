# Guzzle Promises

A+ promise implementation that handles promise chaining and resolution
iteratively.

## Iterative Resolution

By shuffling pending handlers from one owner to another, promises are resolved
iteratively, allowing for "infinite" then chaining (because the stack size
remains constant).

```php
<?php
require 'vendor/autoload.php';

use GuzzleHttp\Promise\Promise;

$parent = new Promise();
$p = $parent;

for ($i = 0; $i < 1000; $i++) {
    $p = $p->then(function ($v) {
        echo xdebug_get_stack_depth() . ', ';
        return $v + 1;
    });
}

$parent->resolve(0);
var_dump($p->wait()); // int(1000)
```

## Foreign promises

This library also works with foreign promises that have a "then" method. When
a foreign promise is returned inside of a then method callback, promise
resolution will occur recursively.
