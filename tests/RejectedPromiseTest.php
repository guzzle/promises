<?php

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;

/**
 * @covers GuzzleHttp\Promise\RejectedPromise
 */
class RejectedPromiseTest extends TestCase
{
    public function testThrowsReasonWhenWaitedUpon()
    {
        $p = new RejectedPromise('foo');
        $this->assertTrue(P\Is::rejected($p));
        try {
            $p->wait(true);
            $this->fail();
        } catch (\Exception $e) {
            $this->assertTrue(P\Is::rejected($p));
            $this->assertTrue(strpos($e->getMessage(), 'foo') !== false, "'" . $e->getMessage() . " does not contain 'foo'");
        }
    }

    public function testCannotCancel()
    {
        $p = new RejectedPromise('foo');
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testCannotResolve()
    {
        $this->setExpectedException(\LogicException::class, 'Cannot resolve a rejected promise');

        $p = new RejectedPromise('foo');
        $p->resolve('bar');
    }

    public function testCannotReject()
    {
        $this->setExpectedException(\LogicException::class, 'Cannot reject a rejected promise');

        $p = new RejectedPromise('foo');
        $p->reject('bar');
    }

    public function testCanRejectWithSameValue()
    {
        $p = new RejectedPromise('foo');
        $p->reject('foo');
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testThrowsSpecificException()
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

    public function testCannotResolveWithPromise()
    {
        $this->setExpectedException(\InvalidArgumentException::class);

        new RejectedPromise(new Promise());
    }

    public function testReturnsSelfWhenNoOnReject()
    {
        $p = new RejectedPromise('a');
        $this->assertSame($p, $p->then());
    }

    public function testInvokesOnRejectedAsynchronously()
    {
        $p = new RejectedPromise('a');
        $r = null;
        $f = function ($reason) use (&$r) { $r = $reason; };
        $p->then(null, $f);
        $this->assertNull($r);
        P\Utils::queue()->run();
        $this->assertSame('a', $r);
    }

    public function testReturnsNewRejectedWhenOnRejectedFails()
    {
        $p = new RejectedPromise('a');
        $f = function () { throw new \Exception('b'); };
        $p2 = $p->then(null, $f);
        $this->assertNotSame($p, $p2);
        try {
            $p2->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame('b', $e->getMessage());
        }
    }

    public function testWaitingIsNoOp()
    {
        $p = new RejectedPromise('a');
        $p->wait(false);
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testOtherwiseIsSugarForRejections()
    {
        $p = new RejectedPromise('foo');
        $p->otherwise(function ($v) use (&$c) { $c = $v; });
        P\Utils::queue()->run();
        $this->assertSame('foo', $c);
    }

    public function testCanResolveThenWithSuccess()
    {
        $actual = null;
        $p = new RejectedPromise('foo');
        $p->otherwise(function ($v) {
            return $v . ' bar';
        })->then(function ($v) use (&$actual) {
            $actual = $v;
        });
        P\Utils::queue()->run();
        $this->assertSame('foo bar', $actual);
    }

    public function testDoesNotTryToRejectTwiceDuringTrampoline()
    {
        $fp = new RejectedPromise('a');
        $t1 = $fp->then(null, function ($v) { return $v . ' b'; });
        $t1->resolve('why!');
        $this->assertSame('why!', $t1->wait());
    }
}
