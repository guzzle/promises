<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * @implements PromiseInterface<mixed, mixed>
 */
class NotPromiseInstance extends Thennable implements PromiseInterface
{
    private Promise $nextPromise;

    public function __construct()
    {
        $this->nextPromise = new Promise();
    }

    /**
     * @param (callable(mixed): mixed)|null $res
     * @param (callable(mixed): mixed)|null $rej
     *
     * @return PromiseInterface<mixed, mixed>
     */
    public function then(?callable $res = null, ?callable $rej = null): PromiseInterface
    {
        return $this->nextPromise->then($res, $rej);
    }

    /**
     * @param callable(mixed): mixed $onRejected
     *
     * @return PromiseInterface<mixed, mixed>
     */
    public function otherwise(callable $onRejected): PromiseInterface
    {
        return $this->then($onRejected);
    }

    /**
     * @param mixed $value
     */
    public function resolve($value = null): void
    {
        $this->nextPromise->resolve($value);
    }

    /**
     * @param mixed $reason
     */
    public function reject($reason): void
    {
        $this->nextPromise->reject($reason);
    }

    /**
     * @return mixed
     */
    public function wait(bool $unwrap = true)
    {
    }

    public function cancel(): void
    {
    }

    public function getState(): string
    {
        return $this->nextPromise->getState();
    }
}
