<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;

class Thennable
{
    private Promise $nextPromise;

    public function __construct()
    {
        $this->nextPromise = new Promise();
    }

    public function then(?callable $res = null, ?callable $rej = null): PromiseInterface
    {
        return $this->nextPromise->then($res, $rej);
    }

    /**
     * @param mixed $value
     */
    public function resolve($value = null): void
    {
        $this->nextPromise->resolve($value);
    }
}
