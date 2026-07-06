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

    public function testIterForIteratesIteratorAggregate(): void
    {
        $result = P\Create::iterFor(new \ArrayObject(['a' => 1, 'b' => 2]));

        $this->assertSame(['a' => 1, 'b' => 2], iterator_to_array($result));
    }

    public function testIterForIteratesGeneratorBackedAggregate(): void
    {
        $aggregate = new class implements \IteratorAggregate {
            public function getIterator(): \Generator
            {
                yield 'a' => 1;
            }
        };

        $this->assertSame(['a' => 1], iterator_to_array(P\Create::iterFor($aggregate)));
    }
}
