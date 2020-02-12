<?php
namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\AggregateException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Promise\TaskQueue;
use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    public function testCreatesPromiseForValue()
    {
        $p = P\promise_for('foo');
        $this->assertInstanceOf(FulfilledPromise::class, $p);
    }

    public function testReturnsPromiseForPromise()
    {
        $p = new Promise();
        $this->assertSame($p, P\promise_for($p));
    }

    public function testReturnsPromiseForThennable()
    {
        $p = new Thennable();
        $wrapped = P\promise_for($p);
        $this->assertNotSame($p, $wrapped);
        $this->assertInstanceOf(PromiseInterface::class, $wrapped);
        $p->resolve('foo');
        P\queue()->run();
        $this->assertEquals('foo', $wrapped->wait());
    }

    public function testReturnsRejection()
    {
        $p = P\rejection_for('fail');
        $this->assertInstanceOf(RejectedPromise::class, $p);
        $this->assertEquals('fail', $this->readAttribute($p, 'reason'));
    }

    public function testReturnsPromisesAsIsInRejectionFor()
    {
        $a = new Promise();
        $b = P\rejection_for($a);
        $this->assertSame($a, $b);
    }

    public function testWaitsOnAllPromisesIntoArray()
    {
        $e = new \Exception();
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () use (&$b) { $b->reject('b'); });
        $c = new Promise(function () use (&$c, $e) { $c->reject($e); });
        $results = P\inspect_all([$a, $b, $c]);
        $this->assertEquals([
            ['state' => 'fulfilled', 'value' => 'a'],
            ['state' => 'rejected', 'reason' => 'b'],
            ['state' => 'rejected', 'reason' => $e]
        ], $results);
    }

    /**
     * @expectedException \GuzzleHttp\Promise\RejectionException
     */
    public function testUnwrapsPromisesWithNoDefaultAndFailure()
    {
        $promises = [new FulfilledPromise('a'), new Promise()];
        P\unwrap($promises);
    }

    public function testUnwrapsPromisesWithNoDefault()
    {
        $promises = [new FulfilledPromise('a')];
        $this->assertEquals(['a'], P\unwrap($promises));
    }

    public function testUnwrapsPromisesWithKeys()
    {
        $promises = [
            'foo' => new FulfilledPromise('a'),
            'bar' => new FulfilledPromise('b'),
        ];
        $this->assertEquals([
            'foo' => 'a',
            'bar' => 'b'
        ], P\unwrap($promises));
    }

    public function testAllAggregatesSortedArray()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\all([$a, $b, $c]);
        $b->resolve('b');
        $a->resolve('a');
        $c->resolve('c');
        $d->then(
            function ($value) use (&$result) { $result = $value; },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\queue()->run();
        $this->assertEquals(['a', 'b', 'c'], $result);
    }

    public function testPromisesDynamicallyAddedToStack()
    {
        $promises = new \ArrayIterator();
        $counter = 0;
        $promises['a'] = new FulfilledPromise('a');
        $promises['b'] = $promise = new Promise(function () use (&$promise, &$promises, &$counter) {
            $counter++; // Make sure the wait function is called only once
            $promise->resolve('b');
            $promises['c'] = $subPromise = new Promise(function () use (&$subPromise) {
                $subPromise->resolve('c');
            });
        });
        $result = P\all($promises, true)->wait();
        $this->assertCount(3, $promises);
        $this->assertCount(3, $result);
        $this->assertEquals($result['c'], 'c');
        $this->assertSame(1, $counter);
    }

    public function testAllThrowsWhenAnyRejected()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\all([$a, $b, $c]);
        $b->resolve('b');
        $a->reject('fail');
        $c->resolve('c');
        $d->then(
            function ($value) use (&$result) { $result = $value; },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\queue()->run();
        $this->assertEquals('fail', $result);
    }

    public function testSomeAggregatesSortedArrayWithMax()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\some(2, [$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->resolve('a');
        $d->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals(['b', 'c'], $result);
    }

    public function testSomeRejectsWhenTooManyRejections()
    {
        $a = new Promise();
        $b = new Promise();
        $d = P\some(2, [$a, $b]);
        $a->reject('bad');
        $b->resolve('good');
        P\queue()->run();
        $this->assertEquals($a::REJECTED, $d->getState());
        $d->then(null, function ($reason) use (&$called) {
            $called = $reason;
        });
        P\queue()->run();
        $this->assertInstanceOf(AggregateException::class, $called);
        $this->assertContains('bad', $called->getReason());
    }

    public function testCanWaitUntilSomeCountIsSatisfied()
    {
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () use (&$b) { $b->resolve('b'); });
        $c = new Promise(function () use (&$c) { $c->resolve('c'); });
        $d = P\some(2, [$a, $b, $c]);
        $this->assertEquals(['a', 'b'], $d->wait());
    }

    /**
     * @expectedException \GuzzleHttp\Promise\AggregateException
     * @expectedExceptionMessage Not enough promises to fulfill count
     */
    public function testThrowsIfImpossibleToWaitForSomeCount()
    {
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $d = P\some(2, [$a]);
        $d->wait();
    }

    /**
     * @expectedException \GuzzleHttp\Promise\AggregateException
     * @expectedExceptionMessage Not enough promises to fulfill count
     */
    public function testThrowsIfResolvedWithoutCountTotalResults()
    {
        $a = new Promise();
        $b = new Promise();
        $d = P\some(3, [$a, $b]);
        $a->resolve('a');
        $b->resolve('b');
        $d->wait();
    }

    public function testAnyReturnsFirstMatch()
    {
        $a = new Promise();
        $b = new Promise();
        $c = P\any([$a, $b]);
        $b->resolve('b');
        $a->resolve('a');
        //P\queue()->run();
        //$this->assertEquals('fulfilled', $c->getState());
        $c->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals('b', $result);
    }

    public function testSettleFulfillsWithFulfilledAndRejected()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\settle([$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->reject('a');
        P\queue()->run();
        $this->assertEquals('fulfilled', $d->getState());
        $d->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals([
            ['state' => 'rejected', 'reason' => 'a'],
            ['state' => 'fulfilled', 'value' => 'b'],
            ['state' => 'fulfilled', 'value' => 'c']
        ], $result);
    }

    public function testCanInspectFulfilledPromise()
    {
        $p = new FulfilledPromise('foo');
        $this->assertEquals([
            'state' => 'fulfilled',
            'value' => 'foo'
        ], P\inspect($p));
    }

    public function testCanInspectRejectedPromise()
    {
        $p = new RejectedPromise('foo');
        $this->assertEquals([
            'state'  => 'rejected',
            'reason' => 'foo'
        ], P\inspect($p));
    }

    public function testCanInspectRejectedPromiseWithNormalException()
    {
        $e = new \Exception('foo');
        $p = new RejectedPromise($e);
        $this->assertEquals([
            'state'  => 'rejected',
            'reason' => $e
        ], P\inspect($p));
    }

    public function testCallsEachLimit()
    {
        $p = new Promise();
        $aggregate = P\each_limit($p, 2);
        $p->resolve('a');
        P\queue()->run();
        $this->assertEquals($p::FULFILLED, $aggregate->getState());
    }

    public function testEachLimitAllRejectsOnFailure()
    {
        $p = [new FulfilledPromise('a'), new RejectedPromise('b')];
        $aggregate = P\each_limit_all($p, 2);
        P\queue()->run();
        $this->assertEquals(PromiseInterface::REJECTED, $aggregate->getState());
        $result = P\inspect($aggregate);
        $this->assertEquals('b', $result['reason']);
    }

    public function testIterForReturnsIterator()
    {
        $iter = new \ArrayIterator();
        $this->assertSame($iter, P\iter_for($iter));
    }

    public function testKnowsIfFulfilled()
    {
        $p = new FulfilledPromise(null);
        $this->assertTrue(P\is_fulfilled($p));
        $this->assertFalse(P\is_rejected($p));
    }

    public function testKnowsIfRejected()
    {
        $p = new RejectedPromise(null);
        $this->assertTrue(P\is_rejected($p));
        $this->assertFalse(P\is_fulfilled($p));
    }

    public function testKnowsIfSettled()
    {
        $p = new RejectedPromise(null);
        $this->assertTrue(P\is_settled($p));
        $p = new Promise();
        $this->assertFalse(P\is_settled($p));
    }

    public function testReturnsTrampoline()
    {
        $this->assertInstanceOf(TaskQueue::class, P\queue());
        $this->assertSame(P\queue(), P\queue());
    }

    public function testCanScheduleThunk()
    {
        $tramp = P\queue();
        $promise = P\task(function () { return 'Hi!'; });
        $c = null;
        $promise->then(function ($v) use (&$c) { $c = $v; });
        $this->assertNull($c);
        $tramp->run();
        $this->assertEquals('Hi!', $c);
    }

    public function testCanScheduleThunkWithRejection()
    {
        $tramp = P\queue();
        $promise = P\task(function () { throw new \Exception('Hi!'); });
        $c = null;
        $promise->otherwise(function ($v) use (&$c) { $c = $v; });
        $this->assertNull($c);
        $tramp->run();
        $this->assertEquals('Hi!', $c->getMessage());
    }

    public function testCanScheduleThunkWithWait()
    {
        $tramp = P\queue();
        $promise = P\task(function () { return 'a'; });
        $this->assertEquals('a', $promise->wait());
        $tramp->run();
    }

    public function testYieldsFromCoroutine()
    {
        $promise = P\coroutine(function () {
            $value = (yield new FulfilledPromise('a'));
            yield  $value . 'b';
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals('ab', $result);
    }

    public function testCanCatchExceptionsInCoroutine()
    {
        $promise = P\coroutine(function () {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                $value = (yield new FulfilledPromise($e->getReason()));
                yield  $value . 'b';
            }
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals(PromiseInterface::FULFILLED, $promise->getState());
        $this->assertEquals('ab', $result);
    }

    /**
     * @dataProvider rejectsParentExceptionProvider
     */
    public function testRejectsParentExceptionWhenException(PromiseInterface $promise)
    {
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\queue()->run();
        $this->assertInstanceOf(\Exception::class, $result);
        $this->assertEquals('a', $result->getMessage());
    }

    public function rejectsParentExceptionProvider()
    {
        return [
            [ P\coroutine(function () {
                yield new FulfilledPromise(0);
                throw new \Exception('a');
            }) ],
            [ P\coroutine(function () {
                throw new \Exception('a');
                yield new FulfilledPromise(0);
            }) ],
        ];
    }

    public function testCanRejectFromRejectionCallback()
    {
        $promise = P\coroutine(function () {
            yield new FulfilledPromise(0);
            yield new RejectedPromise('no!');
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\queue()->run();
        $this->assertInstanceOf(RejectionException::class, $result);
        $this->assertEquals('no!', $result->getReason());
    }

    public function testCanAsyncReject()
    {
        $rej = new Promise();
        $promise = P\coroutine(function () use ($rej) {
            yield new FulfilledPromise(0);
            yield $rej;
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        $rej->reject('no!');
        P\queue()->run();
        $this->assertInstanceOf(RejectionException::class, $result);
        $this->assertEquals('no!', $result->getReason());
    }

    public function testCanCatchAndThrowOtherException()
    {
        $promise = P\coroutine(function () {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                throw new \Exception('foo');
            }
        });
        $promise->otherwise(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
        $this->assertContains('foo', $result->getMessage());
    }

    public function testCanCatchAndYieldOtherException()
    {
        $promise = P\coroutine(function () {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                yield new RejectedPromise('foo');
            }
        });
        $promise->otherwise(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals(PromiseInterface::REJECTED, $promise->getState());
        $this->assertContains('foo', $result->getMessage());
    }

    public function createLotsOfSynchronousPromise()
    {
        return P\coroutine(function () {
            $value = 0;
            for ($i = 0; $i < 1000; $i++) {
                $value = (yield new FulfilledPromise($i));
            }
            yield $value;
        });
    }

    public function testLotsOfSynchronousDoesNotBlowStack()
    {
        $promise = $this->createLotsOfSynchronousPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\queue()->run();
        $this->assertEquals(999, $r);
    }

    public function testLotsOfSynchronousWaitDoesNotBlowStack()
    {
        $promise = $this->createLotsOfSynchronousPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        $this->assertEquals(999, $promise->wait());
        $this->assertEquals(999, $r);
    }

    private function createLotsOfFlappingPromise()
    {
        return P\coroutine(function () {
            $value = 0;
            for ($i = 0; $i < 1000; $i++) {
                try {
                    if ($i % 2) {
                        $value = (yield new FulfilledPromise($i));
                    } else {
                        $value = (yield new RejectedPromise($i));
                    }
                } catch (\Exception $e) {
                    $value = (yield new FulfilledPromise($i));
                }
            }
            yield $value;
        });
    }

    public function testLotsOfTryCatchingDoesNotBlowStack()
    {
        $promise = $this->createLotsOfFlappingPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\queue()->run();
        $this->assertEquals(999, $r);
    }

    public function testLotsOfTryCatchingWaitingDoesNotBlowStack()
    {
        $promise = $this->createLotsOfFlappingPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        $this->assertEquals(999, $promise->wait());
        $this->assertEquals(999, $r);
    }

    public function testAsyncPromisesWithCorrectlyYieldedValues()
    {
        $promises = [
            new Promise(),
            new Promise(),
            new Promise()
        ];

        $promise = P\coroutine(function () use ($promises) {
            $this->assertEquals('skip', (yield new FulfilledPromise('skip')));
            foreach ($promises as $idx => $p) {
                $this->assertEquals($idx, (yield $p));
                $this->assertEquals('skip', (yield new FulfilledPromise('skip')));
            }
            $this->assertEquals('skip', (yield new FulfilledPromise('skip')));
            yield 2;
        });

        $promises[0]->resolve(0);
        $promises[1]->resolve(1);
        $promises[2]->resolve(2);

        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\queue()->run();
        $this->assertEquals(2, $r);
    }

    public function testYieldFinalWaitablePromise()
    {
        $p1 = new Promise(function () use (&$p1) {
            $p1->resolve('skip me');
        });
        $p2 = new Promise(function () use (&$p2) {
            $p2->resolve('hello!');
        });
        $co = P\coroutine(function() use ($p1, $p2) {
            yield $p1;
            yield $p2;
        });
        P\queue()->run();
        $this->assertEquals('hello!', $co->wait());
    }

    public function testCanYieldFinalPendingPromise()
    {
        $p1 = new Promise();
        $p2 = new Promise();
        $co = P\coroutine(function() use ($p1, $p2) {
            yield $p1;
            yield $p2;
        });
        $p1->resolve('a');
        $p2->resolve('b');
        $co->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals('b', $result);
    }

    public function testCanNestYieldsAndFailures()
    {
        $p1 = new Promise();
        $p2 = new Promise();
        $p3 = new Promise();
        $p4 = new Promise();
        $p5 = new Promise();
        $co = P\coroutine(function() use ($p1, $p2, $p3, $p4, $p5) {
            try {
                yield $p1;
            } catch (\Exception $e) {
                yield $p2;
                try {
                    yield $p3;
                    yield $p4;
                } catch (\Exception $e) {
                    yield $p5;
                }
            }
        });
        $p1->reject('a');
        $p2->resolve('b');
        $p3->resolve('c');
        $p4->reject('d');
        $p5->resolve('e');
        $co->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals('e', $result);
    }

    public function testCanYieldErrorsAndSuccessesWithoutRecursion()
    {
        $promises = [];
        for ($i = 0; $i < 20; $i++) {
            $promises[] = new Promise();
        }

        $co = P\coroutine(function() use ($promises) {
            for ($i = 0; $i < 20; $i += 4) {
                try {
                    yield $promises[$i];
                    yield $promises[$i + 1];
                } catch (\Exception $e) {
                    yield $promises[$i + 2];
                    yield $promises[$i + 3];
                }
            }
        });

        for ($i = 0; $i < 20; $i += 4) {
            $promises[$i]->resolve($i);
            $promises[$i + 1]->reject($i + 1);
            $promises[$i + 2]->resolve($i + 2);
            $promises[$i + 3]->resolve($i + 3);
        }

        $co->then(function ($value) use (&$result) { $result = $value; });
        P\queue()->run();
        $this->assertEquals('19', $result);
    }

    public function testCanWaitOnPromiseAfterFulfilled()
    {
        $f = function () {
            static $i = 0;
            $i++;
            return $p = new Promise(function () use (&$p, $i) {
                $p->resolve($i . '-bar');
            });
        };

        $promises = [];
        for ($i = 0; $i < 20; $i++) {
            $promises[] = $f();
        }

        $p = P\coroutine(function () use ($promises) {
            yield new FulfilledPromise('foo!');
            foreach ($promises as $promise) {
                yield $promise;
            }
        });

        $this->assertEquals('20-bar', $p->wait());
    }

    public function testCanWaitOnErroredPromises()
    {
        $p1 = new Promise(function () use (&$p1) { $p1->reject('a'); });
        $p2 = new Promise(function () use (&$p2) { $p2->resolve('b'); });
        $p3 = new Promise(function () use (&$p3) { $p3->resolve('c'); });
        $p4 = new Promise(function () use (&$p4) { $p4->reject('d'); });
        $p5 = new Promise(function () use (&$p5) { $p5->resolve('e'); });
        $p6 = new Promise(function () use (&$p6) { $p6->reject('f'); });

        $co = P\coroutine(function() use ($p1, $p2, $p3, $p4, $p5, $p6) {
            try {
                yield $p1;
            } catch (\Exception $e) {
                yield $p2;
                try {
                    yield $p3;
                    yield $p4;
                } catch (\Exception $e) {
                    yield $p5;
                    yield $p6;
                }
            }
        });

        $res = P\inspect($co);
        $this->assertEquals('f', $res['reason']);
    }

    public function testCoroutineOtherwiseIntegrationTest()
    {
        $a = new Promise();
        $b = new Promise();
        $promise = P\coroutine(function () use ($a, $b) {
            // Execute the pool of commands concurrently, and process errors.
            yield $a;
            yield $b;
        })->otherwise(function (\Exception $e) {
            // Throw errors from the operations as a specific Multipart error.
            throw new \OutOfBoundsException('a', 0, $e);
        });
        $a->resolve('a');
        $b->reject('b');
        $reason = P\inspect($promise)['reason'];
        $this->assertInstanceOf(\OutOfBoundsException::class, $reason);
        $this->assertInstanceOf(RejectionException::class, $reason->getPrevious());
    }
}
