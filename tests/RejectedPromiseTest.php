<?php
namespace GuzzleHttp\Tests\RejectedPromise;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;

/**
 * @covers GuzzleHttp\Promise\RejectedPromise
 */
class RejectedPromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testCannotModifyState()
    {
        $p = new RejectedPromise('foo');
        $this->assertEquals('rejected', $p->getState());
        $p->resolve('bar');
        $p->cancel();
        $p->reject('baz');
        try {
            $p->wait(true);
            $this->fail();
        } catch (\Exception $e) {
            $this->assertEquals('rejected', $p->getState());
            $this->assertContains('foo', $e->getMessage());
        }
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

    public function testImmediatekyInvokesOnRejected()
    {
        $p = new RejectedPromise('a');
        $r = null;
        $f = function ($reason) use (&$r) { $r = $reason; };
        $p->then(null, $f);
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
}
