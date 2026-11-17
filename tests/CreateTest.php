<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

class CreateTest extends TestCase
{
    public function testCreatesPromiseForValue(): void
    {
        $p = P\Create::promiseFor('foo');
        $this->assertInstanceOf(FulfilledPromise::class, $p);
    }

    public function testReturnsPromiseForPromise(): void
    {
        $p = new Promise();
        $this->assertSame($p, P\Create::promiseFor($p));
    }

    public function testReturnsPromiseForThennable(): void
    {
        $p = new Thennable();
        $wrapped = P\Create::promiseFor($p);
        $this->assertNotSame($p, $wrapped);
        $this->assertInstanceOf(PromiseInterface::class, $wrapped);
        $p->resolve('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $wrapped->wait());
    }

    public function testReturnsRejectedPromiseForThenableThatThrows(): void
    {
        $thenable = new class {
            public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
            {
                throw new \RuntimeException('foo');
            }
        };

        $p = P\Create::promiseFor($thenable);
        $this->assertTrue(P\State::isRejected($p));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('foo');

        $p->wait();
    }

    public function testReturnsRejectedPromiseForThenableThatThrowsAfterKeepingCallback(): void
    {
        $thenable = new class {
            public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
            {
                P\Utils::queue()->add(function () use ($onFulfilled): void {
                    $onFulfilled('bar');
                });

                throw new \RuntimeException('foo');
            }
        };

        $p = P\Create::promiseFor($thenable);
        P\Utils::queue()->run();
        $this->assertTrue(P\State::isRejected($p));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('foo');

        $p->wait();
    }

    public function testIgnoresExceptionThrownByThenableAfterItSettles(): void
    {
        $thenable = new class {
            public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
            {
                $onFulfilled('foo');

                throw new \RuntimeException('bar');
            }
        };

        $this->assertSame('foo', P\Create::promiseFor($thenable)->wait());
    }

    public function testReturnsRejection(): void
    {
        $p = P\Create::rejectionFor('fail');
        $this->assertInstanceOf(RejectedPromise::class, $p);
        $this->assertSame('fail', PropertyHelper::get($p, 'reason'));
    }

    public function testReturnsPromisesAsIsInRejectionFor(): void
    {
        $a = new Promise();
        $b = P\Create::rejectionFor($a);
        $this->assertSame($a, $b);
    }

    public function testIterForReturnsIterator(): void
    {
        $iter = new \ArrayIterator();
        $this->assertSame($iter, P\Create::iterFor($iter));
    }
}
