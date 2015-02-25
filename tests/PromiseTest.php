<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;

/**
 * @covers GuzzleHttp\Promise\Promise
 */
class PromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesPromiseForValue()
    {
        $p = Promise::promiseFor('foo');
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
    }

    public function testReturnsPromiseForPromise()
    {
        $p = new Promise();
        $this->assertSame($p, Promise::promiseFor($p));
    }

    public function testReturnsPromiseForThennable()
    {
        $p = new Thennable();
        $this->assertSame($p, Promise::promiseFor($p));
    }

    public function testCannotResolveNonPendingPromise()
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->resolve('bar');
        $this->assertEquals('foo', $p->wait());
    }

    public function testCannotRejectNonPendingPromise()
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->reject('bar');
        $this->assertEquals('foo', $p->wait());
    }

    public function testInvokesWaitFunction()
    {
        $p = new Promise(function () use (&$p) { $p->resolve('10'); });
        $this->assertEquals('10', $p->wait());
    }

    public function testResolvesWithDefaultWhenNoWaitFunction()
    {
        $p = new Promise();
        $this->assertEquals('10', $p->wait(true, '10'));
    }

    /**
     * @expectedException \LogicException
     */
    public function testThrowsWhenWaitFailsToResolve()
    {
        $p = new Promise(function () {});
        $p->wait();
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage foo
     */
    public function testThrowsWhenUnwrapIsRejectedWithNonException()
    {
        $p = new Promise(function () use (&$p) { $p->reject('foo'); });
        $p->wait();
    }

    /**
     * @expectedException \UnexpectedValueException
     * @expectedExceptionMessage foo
     */
    public function testThrowsWhenUnwrapIsRejectedWithException()
    {
        $e = new \UnexpectedValueException('foo');
        $p = new Promise(function () use (&$p, $e) { $p->reject($e); });
        $p->wait();
    }

    public function testDoesNotUnwrapExceptionsWhenDisabled()
    {
        $p = new Promise(function () use (&$p) { $p->reject('foo'); });
        $this->assertEquals('pending', $p->getState());
        $p->wait(false);
        $this->assertEquals('rejected', $p->getState());
    }

    public function testRejectsSelfWhenWaitThrows()
    {
        $e = new \UnexpectedValueException('foo');
        $p = new Promise(function () use ($e) { throw $e; });
        try {
            $p->wait();
            $this->fail();
        } catch (\UnexpectedValueException $e) {
            $this->assertEquals('rejected', $p->getState());
        }
    }

    public function testCannotCancelNonPending()
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->cancel();
        $this->assertEquals('fulfilled', $p->getState());
    }

    public function testCancelsPromiseWhenNoCancelFunction()
    {
        $p = new Promise();
        $p->cancel();
        $this->assertEquals('cancelled', $p->getState());
    }

    public function testCancelsPromiseWithCancelFunction()
    {
        $called = false;
        $p = new Promise(null, function () use (&$called) {
            $called = true;
        });
        $p->cancel();
        $this->assertEquals('cancelled', $p->getState());
        $this->assertTrue($called);
    }

    public function testRejectsPromiseWhenCancelFails()
    {
        $called = false;
        $p = new Promise(null, function () use (&$called) {
            $called = true;
            throw new \Exception('e');
        });
        $p->cancel();
        $this->assertEquals('rejected', $p->getState());
        $this->assertTrue($called);
        try {
            $p->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertEquals('e', $e->getMessage());
        }
    }

    public function testCreatesPromiseWhenFulfilledAfterThen()
    {
        $p = new Promise();
        $carry = null;
        $p2 = $p->then(function ($v) use (&$carry) { $carry = $v; });
        $this->assertNotSame($p, $p2);
        $p->resolve('foo');
        $this->assertEquals('foo', $carry);
    }

    public function testCreatesPromiseWhenFulfilledBeforeThen()
    {
        $p = new Promise();
        $p->resolve('foo');
        $carry = null;
        $p2 = $p->then(function ($v) use (&$carry) { $carry = $v; });
        $this->assertNotSame($p, $p2);
        $this->assertEquals('foo', $carry);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p2);
    }

    public function testCreatesPromiseWhenFulfilledWithNoCallback()
    {
        $p = new Promise();
        $p->resolve('foo');
        $p2 = $p->then();
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p2);
    }

    public function testCreatesPromiseWhenRejectedAfterThen()
    {
        $p = new Promise();
        $carry = null;
        $p2 = $p->then(null, function ($v) use (&$carry) { $carry = $v; });
        $this->assertNotSame($p, $p2);
        $p->reject('foo');
        $this->assertEquals('foo', $carry);
    }

    public function testCreatesPromiseWhenRejectedBeforeThen()
    {
        $p = new Promise();
        $p->reject('foo');
        $carry = null;
        $p2 = $p->then(null, function ($v) use (&$carry) { $carry = $v; });
        $this->assertNotSame($p, $p2);
        $this->assertEquals('foo', $carry);
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p2);
    }

    public function testCreatesPromiseWhenRejectedWithNoCallback()
    {
        $p = new Promise();
        $p->reject('foo');
        $p2 = $p->then();
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf('GuzzleHttp\Promise\RejectedPromise', $p2);
    }

    public function testStacksThenWaitFunctions()
    {
        $p = new Promise(function () use (&$p) { $p->resolve('a'); });
        $p2 = $p->then(function ($v) { return $v . '-1-'; })
            ->then(function ($v) { return $v . '2'; });
        $this->assertEquals('a-1-2', $p2->wait());
    }

    public function testForwardsFulfilledDownChainBetweenGaps()
    {
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(function ($v) use (&$r) { $r = $v; return $v . '2'; })
            ->then(function ($v) use (&$r2) { $r2 = $v; });
        $p->resolve('foo');
        $this->assertEquals('foo', $r);
        $this->assertEquals('foo2', $r2);
    }

    public function testForwardsRejectedPromisesDownChainBetweenGaps()
    {
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function ($v) use (&$r) { $r = $v; return $v . '2'; })
            ->then(function ($v) use (&$r2) { $r2 = $v; });
        $p->reject('foo');
        $this->assertEquals('foo', $r);
        $this->assertEquals('foo2', $r2);
    }

    public function testForwardsThrownPromisesDownChainBetweenGaps()
    {
        $e = new \Exception();
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function ($v) use (&$r, $e) {
                $r = $v;
                throw $e;
            })
            ->then(
                null,
                function ($v) use (&$r2) { $r2 = $v; }
            );
        $p->reject('foo');
        $this->assertEquals('foo', $r);
        $this->assertSame($e, $r2);
    }

    public function testForwardsReturnedRejectedPromisesDownChainBetweenGaps()
    {
        $p = new Promise();
        $rejected = new RejectedPromise('bar');
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function ($v) use (&$r, $rejected) {
                $r = $v;
                return $rejected;
            })
            ->then(
                null,
                function ($v) use (&$r2) { $r2 = $v; }
            );
        $p->reject('foo');
        $this->assertEquals('foo', $r);
        $this->assertEquals('bar', $r2);
    }

    public function testForwardsHandlersToNextPromise()
    {
        $p = new Promise();
        $p2 = new Promise();
        $p->then(function ($v) use ($p2) { return $p2; });
        $p->resolve('a');
        $p->resolve('b');
    }

    public function testRemovesReferenceFromChildWhenParentWaitedUpon()
    {
        $r = null;
        $p = new Promise(function () use (&$p) { $p->resolve('a'); });
        $p2 = new Promise(function () use (&$p2) { $p2->resolve('b'); });
        $pb = $p->then(
            function ($v) use ($p2, &$r) {
                $r = $v;
                return $p2;
            })
            ->then(function ($v) { return $v . '.'; });
        $this->assertEquals('a', $p->wait());
        $this->assertEquals('b', $p2->wait());
        $this->assertEquals('b.', $pb->wait());
        $this->assertEquals('a', $r);
    }

    public function testForwardsHandlersWhenFulfilledPromiseIsReturned()
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->resolve('foo');
        $p2->then(function ($v) use (&$res) { $res[] = 'A:' . $v; });
        $p->then(function () use ($p2, &$res) { $res[] = 'B'; return $p2; })
            ->then(function ($v) use (&$res) { $res[] = 'C:' . $v; });
        $p->resolve('a');
        $p->then(function ($v) use (&$res) { $res[] = 'D:' . $v; });
        $this->assertEquals(['A:foo', 'B', 'C:foo', 'D:a'], $res);
    }

    public function testForwardsHandlersWhenRejectedPromiseIsReturned()
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->reject('foo');
        $p2->then(null, function ($v) use (&$res) { $res[] = 'A:' . $v; });
        $p->then(null, function () use ($p2, &$res) { $res[] = 'B'; return $p2; })
            ->then(null, function ($v) use (&$res) { $res[] = 'C:' . $v; });
        $p->reject('a');
        $p->then(null, function ($v) use (&$res) { $res[] = 'D:' . $v; });
        $this->assertEquals(['A:foo', 'B', 'C:foo', 'D:a'], $res);
    }

    public function testDoesNotForwardRejectedPromise()
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->cancel();
        $p2->then(function ($v) use (&$res) { $res[] = "B:$v"; return $v; });
        $p->then(function ($v) use ($p2, &$res) { $res[] = "B:$v"; return $p2; })
            ->then(function ($v) use (&$res) { $res[] = 'C:' . $v; });
        $p->resolve('a');
        $p->then(function ($v) use (&$res) { $res[] = 'D:' . $v; });
        $this->assertEquals(['B:a', 'D:a'], $res);
    }

    public function testRecursivelyForwardsWhenOnlyThennable()
    {
        $res = [];
        $p = new Promise();
        $p2 = new Thennable();
        $p2->resolve('foo');
        $p2->then(function ($v) use (&$res) { $res[] = 'A:' . $v; });
        $p->then(function () use ($p2, &$res) { $res[] = 'B'; return $p2; })
            ->then(function ($v) use (&$res) { $res[] = 'C:' . $v; });
        $p->resolve('a');
        $p->then(function ($v) use (&$res) { $res[] = 'D:' . $v; });
        $this->assertEquals(['A:foo', 'B', 'C:foo', 'D:a'], $res);
    }

    public function testRecursivelyForwardsWhenNotInstanceOfPromise()
    {
        $res = [];
        $p = new Promise();
        $p2 = new NotPromiseInstance();
        $p2->then(function ($v) use (&$res) { $res[] = 'A:' . $v; });
        $p->then(function () use ($p2, &$res) { $res[] = 'B'; return $p2; })
            ->then(function ($v) use (&$res) { $res[] = 'C:' . $v; });
        $p->resolve('a');
        $p->then(function ($v) use (&$res) { $res[] = 'D:' . $v; });
        $this->assertEquals(['B', 'D:a'], $res);
        $p2->resolve('foo');
        $this->assertEquals(['B', 'D:a', 'A:foo', 'C:foo'], $res);
    }
}
