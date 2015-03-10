<?php
namespace GuzzleHttp\Tests;

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
        $this->assertEquals('ab', $result);
    }

    public function testCanCatchExceptionsInCoroutine()
    {
        $promise = Promise\coroutine(function () {
            try {
                $value = (yield new Promise\RejectedPromise('a'));
                $this->fail('Should have thrown into the coroutine!');
            } catch (Promise\RejectionException $e) {
                $value = (yield new Promise\FulfilledPromise($e->getReason()));
            }
            yield  $value . 'b';
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
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
        $this->assertInstanceOf('GuzzleHttp\Promise\RejectionException', $result);
        $this->assertEquals('no!', $result->getReason());
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
        $this->assertEquals(2, $r);
    }

    public function testCanWaitOnCoroutine()
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
        $this->assertEquals('hello!', $co->wait());
    }
}
