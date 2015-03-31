<?php
namespace GuzzleHttp\Promise;

use GuzzleHttp\Promise as P;

/**
 * @covers GuzzleHttp\Promise\EachPromise
 */
class EachPromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testReturnsSameInstance()
    {
        $each = new EachPromise([], ['concurrency' => 100]);
        $this->assertSame($each->promise(), $each->promise());
    }

    public function testInvokesAllPromises()
    {
        $promises = [new Promise(), new Promise(), new Promise()];
        $called = [];
        $each = new EachPromise($promises, [
            'fulfilled' => function ($value) use (&$called) {
                $called[] = $value;
            }
        ]);
        $p = $each->promise();
        $promises[0]->resolve('a');
        $promises[1]->resolve('c');
        $promises[2]->resolve('b');
        P\trampoline()->run();
        $this->assertEquals(['a', 'c', 'b'], $called);
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
    }

    public function testIsWaitable()
    {
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () use (&$b) { $b->resolve('b'); });
        $called = [];
        $each = new EachPromise([$a, $b], [
            'fulfilled' => function ($value) use (&$called) { $called[] = $value; }
        ]);
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
        $this->assertEquals(['a', 'b'], $called);
    }

    public function testCanResolveBeforeConsumingAll()
    {
        $called = 0;
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () { $this->fail(); });
        $each = new EachPromise([$a, $b], [
            'fulfilled' => function ($value, $idx, Promise $aggregate) use (&$called) {
                $this->assertSame($idx, 0);
                $this->assertEquals('a', $value);
                $aggregate->resolve(null);
                $called++;
            },
            'rejected' => function (\Exception $reason) {
                $this->fail($reason->getMessage());
            }
        ]);
        $p = $each->promise();
        $p->wait();
        $this->assertNull($p->wait());
        $this->assertEquals(1, $called);
        $this->assertEquals(PromiseInterface::FULFILLED, $a->getState());
        $this->assertEquals(PromiseInterface::PENDING, $b->getState());
        // Resolving $b has no effect on the aggregate promise.
        $b->resolve('foo');
        $this->assertEquals(1, $called);
    }

    public function testLimitsPendingPromises()
    {
        $pending = [new Promise(), new Promise(), new Promise(), new Promise()];
        $promises = new \ArrayIterator($pending);
        $each = new EachPromise($promises, ['concurrency' => 2]);
        $p = $each->promise();
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $pending[0]->resolve('a');
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $this->assertTrue($promises->valid());
        $pending[1]->resolve('b');
        P\trampoline()->run();
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $this->assertFalse($promises->valid());
        $promises[2]->resolve('c');
        P\trampoline()->run();
        $this->assertCount(1, $this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::PENDING, $p->getState());
        $promises[3]->resolve('d');
        P\trampoline()->run();
        $this->assertNull($this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
    }

    public function testDynamicallyLimitsPendingPromises()
    {
        $calls = [];
        $pendingFn = function ($count) use (&$calls) {
            $calls[] = $count;
            return 2;
        };
        $pending = [new Promise(), new Promise(), new Promise(), new Promise()];
        $promises = new \ArrayIterator($pending);
        $each = new EachPromise($promises, ['concurrency' => $pendingFn]);
        $p = $each->promise();
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $pending[0]->resolve('a');
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $this->assertTrue($promises->valid());
        $pending[1]->resolve('b');
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        P\trampoline()->run();
        $this->assertFalse($promises->valid());
        $promises[2]->resolve('c');
        P\trampoline()->run();
        $this->assertCount(1, $this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::PENDING, $p->getState());
        $promises[3]->resolve('d');
        P\trampoline()->run();
        $this->assertNull($this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
        $this->assertEquals([0, 1, 1, 1], $calls);
    }

    public function testClearsReferencesWhenResolved()
    {
        $called = false;
        $a = new Promise(function () use (&$a, &$called) {
            $a->resolve('a');
            $called = true;
        });
        $each = new EachPromise([$a], [
            'concurrency'       => function () { return 1; },
            'fulfilled' => function () {},
            'rejected'  => function () {}
        ]);
        $each->promise()->wait();
        $this->assertNull($this->readAttribute($each, 'onFulfilled'));
        $this->assertNull($this->readAttribute($each, 'onRejected'));
        $this->assertNull($this->readAttribute($each, 'iterable'));
        $this->assertNull($this->readAttribute($each, 'pending'));
        $this->assertNull($this->readAttribute($each, 'concurrency'));
        $this->assertTrue($called);
    }

    public function testCanBeCancelled()
    {
        $this->markTestIncomplete();
    }

    public function testDoesNotBlowStackWithFulfilledPromises()
    {
        $pending = [];
        for ($i = 0; $i < 100; $i++) {
            $pending[] = new FulfilledPromise($i);
        }
        $values = [];
        $each = new EachPromise($pending, [
            'fulfilled' => function ($value) use (&$values) {
                $values[] = $value;
            }
        ]);
        $called = false;
        $each->promise()->then(function () use (&$called) {
            $called = true;
        });
        $this->assertFalse($called);
        P\trampoline()->run();
        $this->assertTrue($called);
        $this->assertEquals(range(0, 99), $values);
    }

    public function testDoesNotBlowStackWithRejectedPromises()
    {
        $pending = [];
        for ($i = 0; $i < 100; $i++) {
            $pending[] = new RejectedPromise($i);
        }
        $values = [];
        $each = new EachPromise($pending, [
            'rejected' => function ($value) use (&$values) {
                $values[] = $value;
            }
        ]);
        $called = false;
        $each->promise()->then(
            function () use (&$called) { $called = true; },
            function () { $this->fail('Should not have rejected.'); }
        );
        $this->assertFalse($called);
        P\trampoline()->run();
        $this->assertTrue($called);
        $this->assertEquals(range(0, 99), $values);
    }

    public function testCanMapIterable()
    {
        $called = [];
        $arr = ['a', 'b'];
        $mapfn = function ($value) use (&$called) {
            $called[] = $value;
            return new FulfilledPromise($value);
        };
        $each = new EachPromise($arr, ['mapfn' => $mapfn]);
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertEquals(['a', 'b'], $called);
    }

    public function testRejectsAggregateWhenMapThrows()
    {
        $each = new EachPromise(['a'], [
            'mapfn' => function ($value) {
                throw new \Exception('Failure: ' . $value);
            }
        ]);
        $p = $each->promise();
        $e = null;
        $p->then(null, function ($reason) use (&$e) { $e = $reason; });
        P\trampoline()->run();
        $this->assertInstanceOf('Exception', $e);
        $this->assertEquals('Failure: a', $e->getMessage());
    }
}
