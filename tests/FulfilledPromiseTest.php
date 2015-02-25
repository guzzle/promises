<?php
namespace GuzzleHttp\Tests\Promise\RejectedPromise;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\FulfilledPromise;

/**
 * @covers GuzzleHttp\Promise\FulfilledPromise
 */
class FulfilledPromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testCannotModifyState()
    {
        $p = new FulfilledPromise('foo');
        $this->assertEquals('fulfilled', $p->getState());
        $p->resolve('bar');
        $p->cancel();
        $p->reject('baz');
        $this->assertEquals('foo', $p->wait(true));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testCannotResolveWithPromise()
    {
        new FulfilledPromise(new Promise());
    }

    public function testReturnsSelfWhenNoOnFulfilled()
    {
        $p = new FulfilledPromise('a');
        $this->assertSame($p, $p->then());
    }

    public function testImmediatekyInvokesOnFulfilled()
    {
        $p = new FulfilledPromise('a');
        $r = null;
        $f = function ($d) use (&$r) { $r = $d; };
        $p2 = $p->then($f);
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p2);
        $this->assertEquals('a', $r);
    }

    public function testReturnsNewRejectedWhenOnFulfilledFails()
    {
        $p = new FulfilledPromise('a');
        $f = function () { throw new \Exception('b'); };
        $p2 = $p->then($f);
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf('GuzzleHttp\Promise\RejectedPromise', $p2);
        try {
            $p2->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertEquals('b', $e->getMessage());
        }
    }
}
