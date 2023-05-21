<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Promise\RejectedPromise
 */
class RejectedPromiseTest extends TestCase
{
    public function testThrowsReasonWhenWaitedUpon(): void
    {
        $p = new RejectedPromise('foo');
        $this->assertTrue(P\Is::rejected($p));
        try {
            $p->wait(true);
            $this->fail();
        } catch (\Exception $e) {
            $this->assertTrue(P\Is::rejected($p));
            $this->assertStringContainsString('foo', $e->getMessage());
        }
    }

    public function testCannotCancel(): void
    {
        $p = new RejectedPromise('foo');
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
    }

    /**
     * @exepctedExceptionMessage Cannot resolve a rejected promise
     */
    public function testCannotResolve(): void
    {
        $this->expectException(\LogicException::class);

        $p = new RejectedPromise('foo');
        $p->resolve('bar');
    }

    /**
     * @expectedExceptionMessage Cannot reject a rejected promise
     */
    public function testCannotReject(): void
    {
        $this->expectException(\LogicException::class);

        $p = new RejectedPromise('foo');
        $p->reject('bar');
    }

    public function testCanRejectWithSameValue(): void
    {
        $p = new RejectedPromise('foo');
        $p->reject('foo');
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testThrowsSpecificException(): void
    {
        $e = new \Exception();
        $p = new RejectedPromise($e);
        try {
            $p->wait(true);
            $this->fail();
        } catch (\Exception $e2) {
            $this->assertSame($e, $e2);
        }
    }

    public function testCannotResolveWithPromise(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RejectedPromise(new Promise());
    }

    public function testReturnsSelfWhenNoOnReject(): void
    {
        $p = new RejectedPromise('a');
        $this->assertSame($p, $p->then());
    }

    public function testInvokesOnRejectedAsynchronously(): void
    {
        $p = new RejectedPromise('a');
        $r = null;
        $f = function ($reason) use (&$r): void { $r = $reason; };
        $p->then(null, $f);
        $this->assertNull($r);
        P\Utils::queue()->run();
        $this->assertSame('a', $r);
    }

    public function testReturnsNewRejectedWhenOnRejectedFails(): void
    {
        $p = new RejectedPromise('a');
        $f = function (): void { throw new \Exception('b'); };
        $p2 = $p->then(null, $f);
        $this->assertNotSame($p, $p2);
        try {
            $p2->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame('b', $e->getMessage());
        }
    }

    public function testWaitingIsNoOp(): void
    {
        $p = new RejectedPromise('a');
        $p->wait(false);
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testOtherwiseIsSugarForRejections(): void
    {
        $p = new RejectedPromise('foo');
        $p->otherwise(function ($v) use (&$c): void { $c = $v; });
        P\Utils::queue()->run();
        $this->assertSame('foo', $c);
    }

    public function testCanResolveThenWithSuccess(): void
    {
        $actual = null;
        $p = new RejectedPromise('foo');
        $p->otherwise(function ($v) {
            return $v.' bar';
        })->then(function ($v) use (&$actual): void {
            $actual = $v;
        });
        P\Utils::queue()->run();
        $this->assertSame('foo bar', $actual);
    }

    public function testDoesNotTryToRejectTwiceDuringTrampoline(): void
    {
        $fp = new RejectedPromise('a');
        $t1 = $fp->then(null, function ($v) { return $v.' b'; });
        $t1->resolve('why!');
        $this->assertSame('why!', $t1->wait());
    }
}
