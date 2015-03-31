<?php
namespace GuzzleHttp\Tests\Promise\RejectedPromise;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\FulfilledPromise;

/**
 * @covers GuzzleHttp\Promise\FulfilledPromise
 */
class FulfilledPromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testReturnsValueWhenWaitedUpon()
    {
        $p = new FulfilledPromise('foo');
        $this->assertEquals('fulfilled', $p->getState());
        $this->assertEquals('foo', $p->wait(true));
    }

    public function testCannotCancel()
    {
        $p = new FulfilledPromise('foo');
        $this->assertEquals('fulfilled', $p->getState());
        $p->cancel();
        $this->assertEquals('foo', $p->wait());
    }

    /**
     * @expectedException \RuntimeException
     * @exepctedExceptionMessage Cannot resolve a fulfilled promise
     */
    public function testCannotResolve()
    {
        $p = new FulfilledPromise('foo');
        $p->resolve('bar');
    }

    /**
     * @expectedException \RuntimeException
     * @exepctedExceptionMessage Cannot reject a fulfilled promise
     */
    public function testCannotReject()
    {
        $p = new FulfilledPromise('foo');
        $p->reject('bar');
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

    public function testAsynchronouslyInvokesOnFulfilled()
    {
        $p = new FulfilledPromise('a');
        $r = null;
        $f = function ($d) use (&$r) { $r = $d; };
        $p2 = $p->then($f);
        $this->assertNotSame($p, $p2);
        $this->assertNull($r);
        \GuzzleHttp\Promise\trampoline()->run();
        $this->assertEquals('a', $r);
    }

    public function testReturnsNewRejectedWhenOnFulfilledFails()
    {
        $p = new FulfilledPromise('a');
        $f = function () { throw new \Exception('b'); };
        $p2 = $p->then($f);
        $this->assertNotSame($p, $p2);
        try {
            $p2->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertEquals('b', $e->getMessage());
        }
    }

    public function testOtherwiseIsSugarForRejections()
    {
        $c = null;
        $p = new FulfilledPromise('foo');
        $p->otherwise(function ($v) use (&$c) { $c = $v; });
        $this->assertNull($c);
    }
}
