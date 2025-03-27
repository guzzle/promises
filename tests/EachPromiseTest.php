<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Promise\EachPromise
 */
class EachPromiseTest extends TestCase
{
    public function testReturnsSameInstance(): void
    {
        $each = new EachPromise([], ['concurrency' => 100]);
        $this->assertSame($each->promise(), $each->promise());
    }

    public function testResolvesInCaseOfAnEmptyList(): void
    {
        $promises = [];
        $each = new EachPromise($promises);
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertTrue(P\Is::fulfilled($p));
    }

    public function testResolvesInCaseOfAnEmptyListAndInvokesFulfilled(): void
    {
        $promises = [];
        $each = new EachPromise($promises);
        $p = $each->promise();
        $onFulfilledCalled = false;
        $onRejectedCalled = false;
        $p->then(
            function () use (&$onFulfilledCalled): void {
                $onFulfilledCalled = true;
            },
            function () use (&$onRejectedCalled): void {
                $onRejectedCalled = true;
            }
        );
        $this->assertNull($p->wait());
        $this->assertTrue(P\Is::fulfilled($p));
        $this->assertTrue($onFulfilledCalled);
        $this->assertFalse($onRejectedCalled);
    }

    public function testInvokesAllPromises(): void
    {
        $promises = [new Promise(), new Promise(), new Promise()];
        $called = [];
        $each = new EachPromise($promises, [
            'fulfilled' => function ($value) use (&$called): void {
                $called[] = $value;
            },
        ]);
        $p = $each->promise();
        $promises[0]->resolve('a');
        $promises[1]->resolve('c');
        $promises[2]->resolve('b');
        P\Utils::queue()->run();
        $this->assertSame(['a', 'c', 'b'], $called);
        $this->assertTrue(P\Is::fulfilled($p));
    }

    public function testIsWaitable(): void
    {
        $a = $this->createSelfResolvingPromise('a');
        $b = $this->createSelfResolvingPromise('b');
        $called = [];
        $each = new EachPromise([$a, $b], [
            'fulfilled' => function ($value) use (&$called): void { $called[] = $value; },
        ]);
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertTrue(P\Is::fulfilled($p));
        $this->assertSame(['a', 'b'], $called);
    }

    public function testCanResolveBeforeConsumingAll(): void
    {
        $called = 0;
        $a = $this->createSelfResolvingPromise('a');
        $b = new Promise(function (): void { $this->fail(); });
        $each = new EachPromise([$a, $b], [
            'fulfilled' => function ($value, $idx, Promise $aggregate) use (&$called): void {
                $this->assertSame($idx, 0);
                $this->assertSame('a', $value);
                $aggregate->resolve(null);
                ++$called;
            },
            'rejected' => function (\Exception $reason): void {
                $this->fail($reason->getMessage());
            },
        ]);
        $p = $each->promise();
        $p->wait();
        $this->assertNull($p->wait());
        $this->assertSame(1, $called);
        $this->assertTrue(P\Is::fulfilled($a));
        $this->assertTrue(P\Is::pending($b));
        // Resolving $b has no effect on the aggregate promise.
        $b->resolve('foo');
        $this->assertSame(1, $called);
    }

    public function testLimitsPendingPromises(): void
    {
        $pending = [new Promise(), new Promise(), new Promise(), new Promise()];
        $promises = new \ArrayIterator($pending);
        $each = new EachPromise($promises, ['concurrency' => 2]);
        $p = $each->promise();
        $this->assertCount(2, PropertyHelper::get($each, 'pending'));
        $pending[0]->resolve('a');
        $this->assertCount(2, PropertyHelper::get($each, 'pending'));
        $this->assertTrue($promises->valid());
        $pending[1]->resolve('b');
        P\Utils::queue()->run();
        $this->assertCount(2, PropertyHelper::get($each, 'pending'));
        $this->assertTrue($promises->valid());
        $promises[2]->resolve('c');
        P\Utils::queue()->run();
        $this->assertCount(1, PropertyHelper::get($each, 'pending'));
        $this->assertTrue(P\Is::pending($p));
        $promises[3]->resolve('d');
        P\Utils::queue()->run();
        $this->assertNull(PropertyHelper::get($each, 'pending'));
        $this->assertTrue(P\Is::fulfilled($p));
        $this->assertFalse($promises->valid());
    }

    public function testDynamicallyLimitsPendingPromises(): void
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
        $this->assertCount(2, PropertyHelper::get($each, 'pending'));
        $pending[0]->resolve('a');
        $this->assertCount(2, PropertyHelper::get($each, 'pending'));
        $this->assertTrue($promises->valid());
        $pending[1]->resolve('b');
        $this->assertCount(2, PropertyHelper::get($each, 'pending'));
        P\Utils::queue()->run();
        $this->assertTrue($promises->valid());
        $promises[2]->resolve('c');
        P\Utils::queue()->run();
        $this->assertCount(1, PropertyHelper::get($each, 'pending'));
        $this->assertTrue(P\Is::pending($p));
        $promises[3]->resolve('d');
        P\Utils::queue()->run();
        $this->assertNull(PropertyHelper::get($each, 'pending'));
        $this->assertTrue(P\Is::fulfilled($p));
        $this->assertSame([0, 1, 1, 1], $calls);
        $this->assertFalse($promises->valid());
    }

    public function testClearsReferencesWhenResolved(): void
    {
        $called = false;
        $a = new Promise(function () use (&$a, &$called): void {
            $a->resolve('a');
            $called = true;
        });
        $each = new EachPromise([$a], [
            'concurrency' => function () { return 1; },
            'fulfilled' => function (): void {},
            'rejected' => function (): void {},
        ]);
        $each->promise()->wait();
        $this->assertNull(PropertyHelper::get($each, 'onFulfilled'));
        $this->assertNull(PropertyHelper::get($each, 'onRejected'));
        $this->assertNull(PropertyHelper::get($each, 'iterable'));
        $this->assertNull(PropertyHelper::get($each, 'pending'));
        $this->assertNull(PropertyHelper::get($each, 'concurrency'));
        $this->assertTrue($called);
    }

    public function testCanBeCancelled(): void
    {
        $called = false;
        $a = new FulfilledPromise('a');
        $b = new Promise(function () use (&$called): void { $called = true; });
        $each = new EachPromise([$a, $b], [
            'fulfilled' => function ($value, $idx, Promise $aggregate): void {
                $aggregate->cancel();
            },
            'rejected' => function ($reason) use (&$called): void {
                $called = true;
            },
        ]);
        $p = $each->promise();
        $p->wait(false);
        $this->assertTrue(P\Is::fulfilled($a));
        $this->assertTrue(P\Is::pending($b));
        $this->assertTrue(P\Is::rejected($p));
        $this->assertFalse($called);
    }

    public function testDoesNotBlowStackWithFulfilledPromises(): void
    {
        $pending = [];
        for ($i = 0; $i < 100; ++$i) {
            $pending[] = new FulfilledPromise($i);
        }
        $values = [];
        $each = new EachPromise($pending, [
            'fulfilled' => function ($value) use (&$values): void {
                $values[] = $value;
            },
        ]);
        $called = false;
        $each->promise()->then(function () use (&$called): void {
            $called = true;
        });
        $this->assertFalse($called);
        P\Utils::queue()->run();
        $this->assertTrue($called);
        $this->assertSame(range(0, 99), $values);
    }

    public function testDoesNotBlowStackWithRejectedPromises(): void
    {
        $pending = [];
        for ($i = 0; $i < 100; ++$i) {
            $pending[] = new RejectedPromise($i);
        }
        $values = [];
        $each = new EachPromise($pending, [
            'rejected' => function ($value) use (&$values): void {
                $values[] = $value;
            },
        ]);
        $called = false;
        $each->promise()->then(
            function () use (&$called): void { $called = true; },
            function (): void { $this->fail('Should not have rejected.'); }
        );
        $this->assertFalse($called);
        P\Utils::queue()->run();
        $this->assertTrue($called);
        $this->assertSame(range(0, 99), $values);
    }

    public function testReturnsPromiseForWhatever(): void
    {
        $called = [];
        $arr = ['a', 'b'];
        $each = new EachPromise($arr, [
            'fulfilled' => function ($v) use (&$called): void { $called[] = $v; },
        ]);
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertSame(['a', 'b'], $called);
    }

    public function testRejectsAggregateWhenNextThrows(): void
    {
        $iter = function () {
            yield 'a';
            throw new \Exception('Failure');
        };
        $each = new EachPromise($iter());
        $p = $each->promise();
        $e = null;
        $received = null;
        $p->then(null, function ($reason) use (&$e): void { $e = $reason; });
        P\Utils::queue()->run();
        $this->assertInstanceOf(\Exception::class, $e);
        $this->assertSame('Failure', $e->getMessage());
    }

    public function testDoesNotCallNextOnIteratorUntilNeededWhenWaiting(): void
    {
        $results = [];
        $values = [10];
        $remaining = 9;
        $iter = function () use (&$values) {
            while ($value = array_pop($values)) {
                yield $value;
            }
        };
        $each = new EachPromise($iter(), [
            'concurrency' => 1,
            'fulfilled' => function ($r) use (&$results, &$values, &$remaining): void {
                $results[] = $r;
                if ($remaining > 0) {
                    $values[] = $remaining--;
                }
            },
        ]);
        $each->promise()->wait();
        $this->assertSame(range(10, 1), $results);
    }

    public function testDoesNotCallNextOnIteratorUntilNeededWhenAsync(): void
    {
        $firstPromise = new Promise();
        $pending = [$firstPromise];
        $values = [$firstPromise];
        $results = [];
        $remaining = 9;
        $iter = function () use (&$values) {
            while ($value = array_pop($values)) {
                yield $value;
            }
        };
        $each = new EachPromise($iter(), [
            'concurrency' => 1,
            'fulfilled' => function ($r) use (&$results, &$values, &$remaining, &$pending): void {
                $results[] = $r;
                if ($remaining-- > 0) {
                    $pending[] = $values[] = new Promise();
                }
            },
        ]);
        $i = 0;
        $each->promise();
        while ($promise = array_pop($pending)) {
            $promise->resolve($i++);
            P\Utils::queue()->run();
        }
        $this->assertSame(range(0, 9), $results);
    }

    private function createSelfResolvingPromise($value)
    {
        $p = new Promise(function () use (&$p, $value): void {
            $p->resolve($value);
        });
        $trickCsFixer = true;

        return $p;
    }

    public function testMutexPreventsGeneratorRecursion(): void
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $results = $promises = [];
        for ($i = 0; $i < 20; ++$i) {
            $p = $this->createSelfResolvingPromise($i);
            $pending[] = $p;
            $promises[] = $p;
        }

        $iter = function () use (&$promises, &$pending) {
            foreach ($promises as $promise) {
                // Resolve a promises, which will trigger the then() function,
                // which would cause the EachPromise to try to add more
                // promises to the queue. Without a lock, this would trigger
                // a "Cannot resume an already running generator" fatal error.
                if ($p = array_pop($pending)) {
                    $p->wait();
                }
                yield $promise;
            }
        };

        $each = new EachPromise($iter(), [
            'concurrency' => 5,
            'fulfilled' => function ($r) use (&$results, &$pending): void {
                $results[] = $r;
            },
        ]);

        $each->promise()->wait();
        $this->assertCount(20, $results);
    }

    public function testIteratorWithSameKey(): void
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $iter = function () {
            yield 'foo' => $this->createSelfResolvingPromise(1);
            yield 'foo' => $this->createSelfResolvingPromise(2);
            yield 1 => $this->createSelfResolvingPromise(3);
            yield 1 => $this->createSelfResolvingPromise(4);
        };
        $called = 0;
        $each = new EachPromise($iter(), [
            'fulfilled' => function ($value, $idx, Promise $aggregate) use (&$called): void {
                ++$called;
                if ($value < 3) {
                    $this->assertSame('foo', $idx);
                } else {
                    $this->assertSame(1, $idx);
                }
            },
        ]);
        $each->promise()->wait();
        $this->assertSame(4, $called);
    }

    public function testIsWaitableWhenLimited(): void
    {
        $promises = [
            $this->createSelfResolvingPromise('a'),
            $this->createSelfResolvingPromise('c'),
            $this->createSelfResolvingPromise('b'),
            $this->createSelfResolvingPromise('d'),
        ];
        $called = [];
        $each = new EachPromise($promises, [
            'concurrency' => 2,
            'fulfilled' => function ($value) use (&$called): void {
                $called[] = $value;
            },
        ]);
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertSame(['a', 'c', 'b', 'd'], $called);
        $this->assertTrue(P\Is::fulfilled($p));
    }
}
