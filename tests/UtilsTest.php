<?php

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\AggregateException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use GuzzleHttp\Promise\TaskQueue;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public function testWaitsOnAllPromisesIntoArray()
    {
        $e = new \Exception();
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () use (&$b) { $b->reject('b'); });
        $c = new Promise(function () use (&$c, $e) { $c->reject($e); });
        $results = P\Utils::inspectAll([$a, $b, $c]);
        $this->assertSame([
            ['state' => 'fulfilled', 'value' => 'a'],
            ['state' => 'rejected', 'reason' => 'b'],
            ['state' => 'rejected', 'reason' => $e]
        ], $results);
    }

    public function testUnwrapsPromisesWithNoDefaultAndFailure()
    {
        $this->expectException(\GuzzleHttp\Promise\RejectionException::class);

        $promises = [new FulfilledPromise('a'), new Promise()];
        P\Utils::unwrap($promises);
    }

    public function testUnwrapsPromisesWithNoDefault()
    {
        $promises = [new FulfilledPromise('a')];
        $this->assertSame(['a'], P\Utils::unwrap($promises));
    }

    public function testUnwrapsPromisesWithKeys()
    {
        $promises = [
            'foo' => new FulfilledPromise('a'),
            'bar' => new FulfilledPromise('b'),
        ];
        $this->assertSame([
            'foo' => 'a',
            'bar' => 'b'
        ], P\Utils::unwrap($promises));
    }

    public function testAllAggregatesSortedArray()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Utils::all([$a, $b, $c]);
        $b->resolve('b');
        $a->resolve('a');
        $c->resolve('c');
        $d->then(
            function ($value) use (&$result) { $result = $value; },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\Utils::queue()->run();
        $this->assertSame(['a', 'b', 'c'], $result);
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
        $result = P\Utils::all($promises, true)->wait();
        $this->assertCount(3, $promises);
        $this->assertCount(3, $result);
        $this->assertSame($result['c'], 'c');
        $this->assertSame(1, $counter);
    }

    public function testAllThrowsWhenAnyRejected()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Utils::all([$a, $b, $c]);
        $b->resolve('b');
        $a->reject('fail');
        $c->resolve('c');
        $d->then(
            function ($value) use (&$result) { $result = $value; },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\Utils::queue()->run();
        $this->assertSame('fail', $result);
    }

    public function testSomeAggregatesSortedArrayWithMax()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Utils::some(2, [$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->resolve('a');
        $d->then(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame(['b', 'c'], $result);
    }

    public function testSomeRejectsWhenTooManyRejections()
    {
        $a = new Promise();
        $b = new Promise();
        $d = P\Utils::some(2, [$a, $b]);
        $a->reject('bad');
        $b->resolve('good');
        P\Utils::queue()->run();
        $this->assertTrue(P\Is::rejected($d));
        $d->then(null, function ($reason) use (&$called) {
            $called = $reason;
        });
        P\Utils::queue()->run();
        $this->assertInstanceOf(AggregateException::class, $called);
        $this->assertContains('bad', $called->getReason());
    }

    public function testCanWaitUntilSomeCountIsSatisfied()
    {
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () use (&$b) { $b->resolve('b'); });
        $c = new Promise(function () use (&$c) { $c->resolve('c'); });
        $d = P\Utils::some(2, [$a, $b, $c]);
        $this->assertSame(['a', 'b'], $d->wait());
    }

    public function testThrowsIfImpossibleToWaitForSomeCount()
    {
        $this->expectException(\GuzzleHttp\Promise\AggregateException::class);
        $this->expectExceptionMessage('Not enough promises to fulfill count');

        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $d = P\Utils::some(2, [$a]);
        $d->wait();
    }

    public function testThrowsIfResolvedWithoutCountTotalResults()
    {
        $this->expectException(\GuzzleHttp\Promise\AggregateException::class);
        $this->expectExceptionMessage('Not enough promises to fulfill count');

        $a = new Promise();
        $b = new Promise();
        $d = P\Utils::some(3, [$a, $b]);
        $a->resolve('a');
        $b->resolve('b');
        $d->wait();
    }

    public function testAnyReturnsFirstMatch()
    {
        $a = new Promise();
        $b = new Promise();
        $c = P\Utils::any([$a, $b]);
        $b->resolve('b');
        $a->resolve('a');
        $c->then(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame('b', $result);
    }

    public function testSettleFulfillsWithFulfilledAndRejected()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Utils::settle([$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->reject('a');
        P\Utils::queue()->run();
        $this->assertTrue(P\Is::fulfilled($d));
        $d->then(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame([
            ['state' => 'rejected', 'reason' => 'a'],
            ['state' => 'fulfilled', 'value' => 'b'],
            ['state' => 'fulfilled', 'value' => 'c']
        ], $result);
    }

    public function testCanInspectFulfilledPromise()
    {
        $p = new FulfilledPromise('foo');
        $this->assertSame([
            'state' => 'fulfilled',
            'value' => 'foo'
        ], P\Utils::inspect($p));
    }

    public function testCanInspectRejectedPromise()
    {
        $p = new RejectedPromise('foo');
        $this->assertSame([
            'state'  => 'rejected',
            'reason' => 'foo'
        ], P\Utils::inspect($p));
    }

    public function testCanInspectRejectedPromiseWithNormalException()
    {
        $e = new \Exception('foo');
        $p = new RejectedPromise($e);
        $this->assertSame([
            'state'  => 'rejected',
            'reason' => $e
        ], P\Utils::inspect($p));
    }

    public function testReturnsTrampoline()
    {
        $this->assertInstanceOf(TaskQueue::class, P\Utils::queue());
        $this->assertSame(P\Utils::queue(), P\Utils::queue());
    }

    public function testCanScheduleThunk()
    {
        $tramp = P\Utils::queue();
        $promise = P\Utils::task(function () { return 'Hi!'; });
        $c = null;
        $promise->then(function ($v) use (&$c) { $c = $v; });
        $this->assertNull($c);
        $tramp->run();
        $this->assertSame('Hi!', $c);
    }

    public function testCanScheduleThunkWithRejection()
    {
        $tramp = P\Utils::queue();
        $promise = P\Utils::task(function () { throw new \Exception('Hi!'); });
        $c = null;
        $promise->otherwise(function ($v) use (&$c) { $c = $v; });
        $this->assertNull($c);
        $tramp->run();
        $this->assertSame('Hi!', $c->getMessage());
    }

    public function testCanScheduleThunkWithWait()
    {
        $tramp = P\Utils::queue();
        $promise = P\Utils::task(function () { return 'a'; });
        $this->assertSame('a', $promise->wait());
        $tramp->run();
    }

    public function testYieldsFromCoroutine()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = P\Coroutine::of(function () {
            $value = (yield new FulfilledPromise('a'));
            yield  $value . 'b';
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame('ab', $result);
    }

    public function testCanCatchExceptionsInCoroutine()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = P\Coroutine::of(function () {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                $value = (yield new FulfilledPromise($e->getReason()));
                yield  $value . 'b';
            }
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertTrue(P\Is::fulfilled($promise));
        $this->assertSame('ab', $result);
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
        P\Utils::queue()->run();
        $this->assertInstanceOf(\Exception::class, $result);
        $this->assertSame('a', $result->getMessage());
    }

    public function rejectsParentExceptionProvider()
    {
        return [
            [P\Coroutine::of(function () {
                yield new FulfilledPromise(0);
                throw new \Exception('a');
            })],
            [P\Coroutine::of(function () {
                throw new \Exception('a');
                yield new FulfilledPromise(0);
            })],
        ];
    }

    public function testCanRejectFromRejectionCallback()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = P\Coroutine::of(function () {
            yield new FulfilledPromise(0);
            yield new RejectedPromise('no!');
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\Utils::queue()->run();
        $this->assertInstanceOf(RejectionException::class, $result);
        $this->assertSame('no!', $result->getReason());
    }

    public function testCanAsyncReject()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $rej = new Promise();
        $promise = P\Coroutine::of(function () use ($rej) {
            yield new FulfilledPromise(0);
            yield $rej;
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        $rej->reject('no!');
        P\Utils::queue()->run();
        $this->assertInstanceOf(RejectionException::class, $result);
        $this->assertSame('no!', $result->getReason());
    }

    public function testCanCatchAndThrowOtherException()
    {
        $promise = P\Coroutine::of(function () {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                throw new \Exception('foo');
            }
        });
        $promise->otherwise(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertTrue(P\Is::rejected($promise));
        $this->assertStringContainsString('foo', $result->getMessage());
    }

    public function testCanCatchAndYieldOtherException()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = P\Coroutine::of(function () {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                yield new RejectedPromise('foo');
            }
        });
        $promise->otherwise(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertTrue(P\Is::rejected($promise));
        $this->assertStringContainsString('foo', $result->getMessage());
    }

    public function createLotsOfSynchronousPromise()
    {
        return P\Coroutine::of(function () {
            $value = 0;
            for ($i = 0; $i < 1000; $i++) {
                $value = (yield new FulfilledPromise($i));
            }
            yield $value;
        });
    }

    public function testLotsOfSynchronousDoesNotBlowStack()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = $this->createLotsOfSynchronousPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\Utils::queue()->run();
        $this->assertSame(999, $r);
    }

    public function testLotsOfSynchronousWaitDoesNotBlowStack()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = $this->createLotsOfSynchronousPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        $this->assertSame(999, $promise->wait());
        $this->assertSame(999, $r);
    }

    private function createLotsOfFlappingPromise()
    {
        return P\Coroutine::of(function () {
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
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = $this->createLotsOfFlappingPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\Utils::queue()->run();
        $this->assertSame(999, $r);
    }

    public function testLotsOfTryCatchingWaitingDoesNotBlowStack()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promise = $this->createLotsOfFlappingPromise();
        $promise->then(function ($v) use (&$r) { $r = $v; });
        $this->assertSame(999, $promise->wait());
        $this->assertSame(999, $r);
    }

    public function testAsyncPromisesWithCorrectlyYieldedValues()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promises = [
            new Promise(),
            new Promise(),
            new Promise(),
        ];

        eval('
        $promise = \GuzzleHttp\Promise\Coroutine::of(function () use ($promises) {
            $value = null;
            $this->assertSame(\'skip\', (yield new \GuzzleHttp\Promise\FulfilledPromise(\'skip\')));
            foreach ($promises as $idx => $p) {
                $value = (yield $p);
                $this->assertSame($idx, $value);
                $this->assertSame(\'skip\', (yield new \GuzzleHttp\Promise\FulfilledPromise(\'skip\')));
            }
            $this->assertSame(\'skip\', (yield new \GuzzleHttp\Promise\FulfilledPromise(\'skip\')));
            yield $value;
        });
');

        $promises[0]->resolve(0);
        $promises[1]->resolve(1);
        $promises[2]->resolve(2);

        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\Utils::queue()->run();
        $this->assertSame(2, $r);
    }

    public function testYieldFinalWaitablePromise()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $p1 = new Promise(function () use (&$p1) {
            $p1->resolve('skip me');
        });
        $p2 = new Promise(function () use (&$p2) {
            $p2->resolve('hello!');
        });
        $co = P\Coroutine::of(function () use ($p1, $p2) {
            yield $p1;
            yield $p2;
        });
        P\Utils::queue()->run();
        $this->assertSame('hello!', $co->wait());
    }

    public function testCanYieldFinalPendingPromise()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $p1 = new Promise();
        $p2 = new Promise();
        $co = P\Coroutine::of(function () use ($p1, $p2) {
            yield $p1;
            yield $p2;
        });
        $p1->resolve('a');
        $p2->resolve('b');
        $co->then(function ($value) use (&$result) { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame('b', $result);
    }

    public function testCanNestYieldsAndFailures()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $p1 = new Promise();
        $p2 = new Promise();
        $p3 = new Promise();
        $p4 = new Promise();
        $p5 = new Promise();
        $co = P\Coroutine::of(function () use ($p1, $p2, $p3, $p4, $p5) {
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
        P\Utils::queue()->run();
        $this->assertSame('e', $result);
    }

    public function testCanYieldErrorsAndSuccessesWithoutRecursion()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $promises = [];
        for ($i = 0; $i < 20; $i++) {
            $promises[] = new Promise();
        }

        $co = P\Coroutine::of(function () use ($promises) {
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
        P\Utils::queue()->run();
        $this->assertSame(19, $result);
    }

    public function testCanWaitOnPromiseAfterFulfilled()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

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

        $p = P\Coroutine::of(function () use ($promises) {
            yield new FulfilledPromise('foo!');
            foreach ($promises as $promise) {
                yield $promise;
            }
        });

        $this->assertSame('20-bar', $p->wait());
    }

    public function testCanWaitOnErroredPromises()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $p1 = new Promise(function () use (&$p1) { $p1->reject('a'); });
        $p2 = new Promise(function () use (&$p2) { $p2->resolve('b'); });
        $p3 = new Promise(function () use (&$p3) { $p3->resolve('c'); });
        $p4 = new Promise(function () use (&$p4) { $p4->reject('d'); });
        $p5 = new Promise(function () use (&$p5) { $p5->resolve('e'); });
        $p6 = new Promise(function () use (&$p6) { $p6->reject('f'); });

        $co = P\Coroutine::of(function () use ($p1, $p2, $p3, $p4, $p5, $p6) {
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

        $res = P\Utils::inspect($co);
        $this->assertSame('f', $res['reason']);
    }

    public function testCoroutineOtherwiseIntegrationTest()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestIncomplete('Broken on HHVM.');
        }

        $a = new Promise();
        $b = new Promise();
        $promise = P\Coroutine::of(function () use ($a, $b) {
            // Execute the pool of commands concurrently, and process errors.
            yield $a;
            yield $b;
        })->otherwise(function (\Exception $e) {
            // Throw errors from the operations as a specific Multipart error.
            throw new \OutOfBoundsException('a', 0, $e);
        });
        $a->resolve('a');
        $b->reject('b');
        $reason = P\Utils::inspect($promise)['reason'];
        $this->assertInstanceOf(\OutOfBoundsException::class, $reason);
        $this->assertInstanceOf(RejectionException::class, $reason->getPrevious());
    }

    public function testCanManuallySettleTaskQueueGeneratedPromises()
    {
        $p1 = P\Utils::task(function () { return 'a'; });
        $p2 = P\Utils::task(function () { return 'b'; });
        $p3 = P\Utils::task(function () { return 'c'; });

        $p1->cancel();
        $p2->resolve('b2');

        $results = P\Utils::inspectAll([$p1, $p2, $p3]);

        $this->assertSame([
            ['state' => 'rejected', 'reason' => 'Promise has been cancelled'],
            ['state' => 'fulfilled', 'value' => 'b2'],
            ['state' => 'fulfilled', 'value' => 'c']
        ], $results);
    }
}
