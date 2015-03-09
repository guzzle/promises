<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;

/**
 * @covers GuzzleHttp\Promise\Promise
 */
class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Cannot resolve a fulfilled promise
     */
    public function testCannotResolveNonPendingPromise()
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->resolve('bar');
        $this->assertEquals('foo', $p->wait());
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Cannot reject a fulfilled promise
     */
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
     * @expectedException \GuzzleHttp\Promise\RejectionException
     * @expectedExceptionMessage The promise was rejected with value: foo
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

    public function testWaitsOnNestedPromises()
    {
        $p = new Promise(function () use (&$p) { $p->resolve('_'); });
        $p2 = new Promise(function () use (&$p2) { $p2->resolve('foo'); });
        $p3 = $p->then(function () use ($p2) { return $p2; });
        $this->assertSame('foo', $p3->wait());
    }

    /**
     * @expectedException \LogicException
     */
    public function testThrowsWhenWaitingOnPromiseWithNoWaitFunction()
    {
        $p = new Promise();
        $p->wait();
    }

    public function testCanProvideDefaultWaitValue()
    {
        $p = new Promise();
        $this->assertEquals('foo', $p->wait(true, 'foo'));
    }

    public function testCanProvideDefaultWaitValueAsNull()
    {
        $p = new Promise();
        $this->assertNull($p->wait(true, null));
    }

    public function testCannotCancelNonPending()
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->cancel();
        $this->assertEquals('fulfilled', $p->getState());
    }

    /**
     * @expectedException \GuzzleHttp\Promise\CancellationException
     */
    public function testCancelsPromiseWhenNoCancelFunction()
    {
        $p = new Promise();
        $p->cancel();
        $this->assertEquals('rejected', $p->getState());
        $p->wait();
    }

    public function testCancelsPromiseWithCancelFunction()
    {
        $called = false;
        $p = new Promise(null, function () use (&$called) { $called = true; });
        $p->cancel();
        $this->assertEquals('rejected', $p->getState());
        $this->assertTrue($called);
    }

    public function testCancelsUppermostPendingPromise()
    {
        $called = false;
        $p1 = new Promise(null, function () use (&$called) { $called = true; });
        $p2 = $p1->then(function () {});
        $p3 = $p2->then(function () {});
        $p4 = $p3->then(function () {});
        $p3->cancel();
        $this->assertEquals('rejected', $p1->getState());
        $this->assertEquals('rejected', $p2->getState());
        $this->assertEquals('rejected', $p2->getState());
        $this->assertEquals('rejected', $p4->getState());
        $this->assertTrue($called);
        try {
            $p3->wait();
            $this->fail();
        } catch (CancellationException $e) {
            $this->assertContains('cancelled', $e->getMessage());
        }
        try {
            $p4->wait();
            $this->fail();
        } catch (CancellationException $e) {
            $this->assertContains('cancelled', $e->getMessage());
        }
    }

    public function testCancelsChildPromises()
    {
        $called1 = $called2 = $called3 = false;
        $p1 = new Promise(null, function () use (&$called1) { $called1 = true; });
        $p2 = new Promise(null, function () use (&$called2) { $called2 = true; });
        $p3 = new Promise(null, function () use (&$called3) { $called3 = true; });
        $p4 = $p2->then(function () use ($p3) { return $p3; });
        $p5 = $p4->then(function () { $this->fail(); });
        $p4->cancel();
        $this->assertEquals('pending', $p1->getState());
        $this->assertEquals('rejected', $p2->getState());
        $this->assertEquals('rejected', $p4->getState());
        $this->assertEquals('rejected', $p5->getState());
        $this->assertFalse($called1);
        $this->assertTrue($called2);
        $this->assertFalse($called3);
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

    public function testInvokesWaitFnsForThens()
    {
        $p = new Promise(function () use (&$p) { $p->resolve('a'); });
        $p2 = $p
            ->then(function ($v) { return $v . '-1-'; })
            ->then(function ($v) { return $v . '2'; });
        $this->assertEquals('a-1-2', $p2->wait());
    }

    public function testStacksThenWaitFunctions()
    {
        $p1 = new Promise(function () use (&$p1) { $p1->resolve('a'); });
        $p2 = new Promise(function () use (&$p2) { $p2->resolve('b'); });
        $p3 = new Promise(function () use (&$p3) { $p3->resolve('c'); });
        $p4 = $p1
            ->then(function () use ($p2) { return $p2; })
            ->then(function () use ($p3) { return $p3; });
        $this->assertEquals('c', $p4->wait());
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
        $resolved = null;
        $p->then(function ($v) use ($p2) { return $p2; })
            ->then(function ($value) use (&$resolved) { $resolved = $value; });
        $p->resolve('a');
        $p2->resolve('b');
        $this->assertEquals('b', $resolved);
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

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Cannot resolve a promise with itself
     */
    public function testCannotResolveWithSelf()
    {
        $p = new Promise();
        $p->resolve($p);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Cannot reject a promise with itself
     */
    public function testCannotRejectWithSelf()
    {
        $p = new Promise();
        $p->reject($p);
    }
}
