<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise;

class CoroutineTest extends \PHPUnit_Framework_TestCase
{
    public function testYieldsFromCoroutine()
    {
        $promise = Promise\coroutine(function () {
            $value = (yield new Promise\FulfilledPromise('a'));
            yield  $value . 'b';
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        P\trampoline()->run();
        $this->assertEquals('ab', $result);
    }

    public function testCanCatchExceptionsInCoroutine()
    {
        $promise = Promise\coroutine(function () {
            try {
                yield new Promise\RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (Promise\RejectionException $e) {
                $value = (yield new Promise\FulfilledPromise($e->getReason()));
                yield  $value . 'b';
            }
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        P\trampoline()->run();
        $this->assertEquals(Promise\PromiseInterface::FULFILLED, $promise->getState());
        $this->assertEquals('ab', $result);
    }

    public function testRejectsParentExceptionWhenException()
    {
        $promise = Promise\coroutine(function () {
            yield new Promise\FulfilledPromise(0);
            throw new \Exception('a');
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\trampoline()->run();
        $this->assertInstanceOf('Exception', $result);
        $this->assertEquals('a', $result->getMessage());
    }

    public function testCanRejectFromRejectionCallback()
    {
        $promise = Promise\coroutine(function () {
            yield new Promise\FulfilledPromise(0);
            yield new Promise\RejectedPromise('no!');
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        P\trampoline()->run();
        $this->assertInstanceOf('GuzzleHttp\Promise\RejectionException', $result);
        $this->assertEquals('no!', $result->getReason());
    }

    public function testCanAsyncReject()
    {
        $rej = new Promise\Promise();
        $promise = Promise\coroutine(function () use ($rej) {
            yield new Promise\FulfilledPromise(0);
            yield $rej;
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        $rej->reject('no!');
        P\trampoline()->run();
        $this->assertInstanceOf('GuzzleHttp\Promise\RejectionException', $result);
        $this->assertEquals('no!', $result->getReason());
    }

    public function testCanCatchAndThrowOtherException()
    {
        $promise = Promise\coroutine(function () {
            try {
                yield new Promise\RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (Promise\RejectionException $e) {
                throw new \Exception('foo');
            }
        });
        $promise->otherwise(function ($value) use (&$result) { $result = $value; });
        P\trampoline()->run();
        $this->assertEquals(Promise\PromiseInterface::REJECTED, $promise->getState());
        $this->assertContains('foo', $result->getMessage());
    }

    public function testCanCatchAndYieldOtherException()
    {
        $promise = Promise\coroutine(function () {
            try {
                yield new Promise\RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (Promise\RejectionException $e) {
                yield new Promise\RejectedPromise('foo');
            }
        });
        $promise->otherwise(function ($value) use (&$result) { $result = $value; });
        P\trampoline()->run();
        $this->assertEquals(Promise\PromiseInterface::REJECTED, $promise->getState());
        $this->assertContains('foo', $result->getMessage());
    }

    public function testLotsOfSynchronousDoesNotBlowStack()
    {
        $promise = Promise\coroutine(function () {
            $value = 0;
            for ($i = 0; $i < 1000; $i++) {
                $value = (yield new Promise\FulfilledPromise($i));
            }
            yield $value;
        });
        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\trampoline()->run();
        $this->assertEquals(999, $r);
    }

    public function testAsyncPromisesWithCorrectlyYieldedValues()
    {
        $promises = [
            new Promise\Promise(),
            new Promise\Promise(),
            new Promise\Promise()
        ];

        $promise = Promise\coroutine(function () use ($promises) {
            $value = null;
            $this->assertEquals('skip', (yield new Promise\FulfilledPromise('skip')));
            foreach ($promises as $idx => $p) {
                $value = (yield $p);
                $this->assertEquals($value, $idx);
                $this->assertEquals('skip', (yield new Promise\FulfilledPromise('skip')));
            }
            $this->assertEquals('skip', (yield new Promise\FulfilledPromise('skip')));
            yield $value;
        });

        $promises[0]->resolve(0);
        $promises[1]->resolve(1);
        $promises[2]->resolve(2);

        $promise->then(function ($v) use (&$r) { $r = $v; });
        P\trampoline()->run();
        $this->assertEquals(2, $r);
    }

    public function testYieldFinalWaitablePromise()
    {
        $p1 = new Promise\Promise(function () use (&$p1) {
            $p1->resolve('skip me');
        });
        $p2 = new Promise\Promise(function () use (&$p2) {
            $p2->resolve('hello!');
        });
        $co = Promise\coroutine(function() use ($p1, $p2) {
            yield $p1;
            yield $p2;
        });
        P\trampoline()->run();
        $this->assertEquals('hello!', $co->wait());
    }

    public function testCanYieldFinalPendingPromise()
    {
        $p1 = new Promise\Promise();
        $p2 = new Promise\Promise();
        $co = Promise\coroutine(function() use ($p1, $p2) {
            yield $p1;
            yield $p2;
        });
        $p1->resolve('a');
        $p2->resolve('b');
        $co->then(function ($value) use (&$result) { $result = $value; });
        P\trampoline()->run();
        $this->assertEquals('b', $result);
    }

    public function testCanNestYieldsAndFailures()
    {
        $p1 = new Promise\Promise();
        $p2 = new Promise\Promise();
        $p3 = new Promise\Promise();
        $p4 = new Promise\Promise();
        $p5 = new Promise\Promise();
        $co = Promise\coroutine(function() use ($p1, $p2, $p3, $p4, $p5) {
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
        P\trampoline()->run();
        $this->assertEquals('e', $result);
    }

    public function testCanYieldErrorsAndSuccessesWithoutRecursion()
    {
        $promises = [];
        for ($i = 0; $i < 20; $i++) {
            $promises[] = new Promise\Promise();
        }

        $co = Promise\coroutine(function() use ($promises) {
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
        P\trampoline()->run();
        $this->assertEquals('19', $result);
    }

    public function testCanWaitOnPromiseAfterFulfilled()
    {
        $f = function () {
            static $i = 0;
            $i++;
            return $p = new Promise\Promise(function () use (&$p, $i) {
                $p->resolve($i . '-bar');
            });
        };

        $promises = [];
        for ($i = 0; $i < 20; $i++) {
            $promises[] = $f();
        }

        $p = Promise\coroutine(function () use ($promises) {
            yield new Promise\FulfilledPromise('foo!');
            foreach ($promises as $promise) {
                yield $promise;
            }
        });

        $this->assertEquals('20-bar', $p->wait());
    }

    public function testCanWaitOnErroredPromises()
    {
        $p1 = new Promise\Promise(function () use (&$p1) { $p1->reject('a'); });
        $p2 = new Promise\Promise(function () use (&$p2) { $p2->resolve('b'); });
        $p3 = new Promise\Promise(function () use (&$p3) { $p3->resolve('c'); });
        $p4 = new Promise\Promise(function () use (&$p4) { $p4->reject('d'); });
        $p5 = new Promise\Promise(function () use (&$p5) { $p5->resolve('e'); });
        $p6 = new Promise\Promise(function () use (&$p6) { $p6->reject('f'); });

        $co = Promise\coroutine(function() use ($p1, $p2, $p3, $p4, $p5, $p6) {
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

        $res = Promise\inspect($co);
        $this->assertEquals('f', $res['reason']);
    }
}
