<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Promise\FulfilledPromise
 */
class FulfilledPromiseTest extends TestCase
{
    public function testReturnsValueWhenWaitedUpon(): void
    {
        $p = new FulfilledPromise('foo');
        $this->assertTrue(P\Is::fulfilled($p));
        $this->assertSame('foo', $p->wait(true));
    }

    public function testCannotCancel(): void
    {
        $p = new FulfilledPromise('foo');
        $this->assertTrue(P\Is::fulfilled($p));
        $p->cancel();
        $this->assertSame('foo', $p->wait());
    }

    /**
     * @expectedExceptionMessage Cannot resolve a fulfilled promise
     */
    public function testCannotResolve(): void
    {
        $this->expectException(\LogicException::class);

        $p = new FulfilledPromise('foo');
        $p->resolve('bar');
    }

    /**
     * @expectedExceptionMessage Cannot reject a fulfilled promise
     */
    public function testCannotReject(): void
    {
        $this->expectException(\LogicException::class);

        $p = new FulfilledPromise('foo');
        $p->reject('bar');
    }

    public function testCanResolveWithSameValue(): void
    {
        $p = new FulfilledPromise('foo');
        $p->resolve('foo');
        $this->assertSame('foo', $p->wait());
    }

    public function testCannotResolveWithPromise(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FulfilledPromise(new Promise());
    }

    public function testReturnsSelfWhenNoOnFulfilled(): void
    {
        $p = new FulfilledPromise('a');
        $this->assertSame($p, $p->then());
    }

    public function testAsynchronouslyInvokesOnFulfilled(): void
    {
        $p = new FulfilledPromise('a');
        $r = null;
        $f = function ($d) use (&$r): void { $r = $d; };
        $p2 = $p->then($f);
        $this->assertNotSame($p, $p2);
        $this->assertNull($r);
        P\Utils::queue()->run();
        $this->assertSame('a', $r);
    }

    public function testReturnsNewRejectedWhenOnFulfilledFails(): void
    {
        $p = new FulfilledPromise('a');
        $f = function (): void { throw new \Exception('b'); };
        $p2 = $p->then($f);
        $this->assertNotSame($p, $p2);
        try {
            $p2->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame('b', $e->getMessage());
        }
    }

    public function testOtherwiseIsSugarForRejections(): void
    {
        $c = null;
        $p = new FulfilledPromise('foo');
        $p->otherwise(function ($v) use (&$c): void { $c = $v; });
        $this->assertNull($c);
    }

    public function testDoesNotTryToFulfillTwiceDuringTrampoline(): void
    {
        $fp = new FulfilledPromise('a');
        $t1 = $fp->then(function ($v) { return $v.' b'; });
        $t1->resolve('why!');
        $this->assertSame('why!', $t1->wait());
    }
}
