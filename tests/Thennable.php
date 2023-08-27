<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\Promise;

class Thennable
{
    private $nextPromise;

    public function __construct()
    {
        $this->nextPromise = new Promise();
    }

    public function then(callable $res = null, callable $rej = null)
    {
        return $this->nextPromise->then($res, $rej);
    }

    public function resolve($value): void
    {
        $this->nextPromise->resolve($value);
    }
}
