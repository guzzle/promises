<?php
namespace GuzzleHttp\Tests\RejectedPromise;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;

/**
 * @covers GuzzleHttp\Promise\RejectedPromise
 */
class RejectedPromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testThrowsReasonWhenWaitedUpon()
    {
        $p = new RejectedPromise('foo');
        $this->assertEquals('rejected', $p->getState());
        try {
            $p->wait(true);
            $this->fail();
        } catch (\Exception $e) {
            $this->assertEquals('rejected', $p->getState());
            $this->assertContains('foo', $e->getMessage());
        }
    }

    public function testCannotCancel()
    {
        $p = new RejectedPromise('foo');
        $p->cancel();
        $this->assertEquals('rejected', $p->getState());
    }

    /**
     * @expectedException \RuntimeException
     * @exepctedExceptionMessage Cannot resolve a rejected promise
     */
    public function testCannotResolve()
    {
        $p = new RejectedPromise('foo');
        $p->resolve('bar');
    }

    /**
     * @expectedException \RuntimeException
     * @exepctedExceptionMessage Cannot reject a rejected promise
     */
    public function testCannotReject()
    {
        $p = new RejectedPromise('foo');
        $p->reject('bar');
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

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testCannotResolveWithPromise()
    {
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
        \GuzzleHttp\Promise\trampoline()->run();
        $this->assertEquals('a', $r);
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
            $this->assertEquals('b', $e->getMessage());
        }
    }

    public function testWaitingIsNoOp()
    {
        $p = new RejectedPromise('a');
        $p->wait(false);
    }

    public function testOtherwiseIsSugarForRejections()
    {
        $p = new RejectedPromise('foo');
        $p->otherwise(function ($v) use (&$c) { $c = $v; });
        \GuzzleHttp\Promise\trampoline()->run();
        $this->assertSame('foo', $c);
    }
}
