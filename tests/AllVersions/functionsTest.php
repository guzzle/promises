<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;

class PromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testCreatesPromiseForValue()
    {
        $p = \GuzzleHttp\Promise\promise_for('foo');
        $this->assertInstanceOf('GuzzleHttp\Promise\FulfilledPromise', $p);
    }

    public function testReturnsPromiseForPromise()
    {
        $p = new Promise();
        $this->assertSame($p, \GuzzleHttp\Promise\promise_for($p));
    }

    public function testReturnsPromiseForThennable()
    {
        $p = new Thennable();
        $wrapped = \GuzzleHttp\Promise\promise_for($p);
        $this->assertNotSame($p, $wrapped);
        $this->assertInstanceOf('GuzzleHttp\Promise\PromiseInterface', $wrapped);
        $p->resolve('foo');
        $this->assertEquals('foo', $wrapped->wait());
    }

    public function testWaitsOnAllPromisesIntoArray()
    {
        $e = new \Exception();
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () use (&$b) { $b->reject('b'); });
        $c = new Promise(function () use (&$c, $e) { $c->reject($e); });
        $results = \GuzzleHttp\Promise\wait([$a, $b, $c]);
        $this->assertEquals([
            ['state' => 'fulfilled', 'value' => 'a'],
            ['state' => 'rejected', 'reason' => 'b'],
            ['state' => 'rejected', 'reason' => $e]
        ], $results);
    }

    /**
     * @expectedException \LogicException
     */
    public function testJoinsPromisesWithNoDefaultAndFailure()
    {
        $promises = [new FulfilledPromise('a'), new Promise()];
        \GuzzleHttp\Promise\join($promises);
    }

    public function testJoinsPromisesWithNoDefault()
    {
        $promises = [new FulfilledPromise('a')];
        $this->assertEquals(['a'], \GuzzleHttp\Promise\join($promises));
    }

    public function testJoinsPromisesWithDefault()
    {
        $promises = [new FulfilledPromise('a'), new Promise()];
        $results = \GuzzleHttp\Promise\join($promises, 'b');
        $this->assertEquals(['a', 'b'], $results);
    }

    public function testAllAggregatesSortedArray()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = \GuzzleHttp\Promise\all([$a, $b, $c]);
        $b->resolve('b');
        $a->resolve('a');
        $c->resolve('c');
        $d->then(
            function ($value) use (&$result) { $result = $value; },
            function ($reason) use (&$result) { $result = $reason; }
        );
        $this->assertEquals(['a', 'b', 'c'], $result);
    }

    public function testAllThrowsWhenAnyRejected()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = \GuzzleHttp\Promise\all([$a, $b, $c]);
        $b->resolve('b');
        $a->reject('fail');
        $c->resolve('c');
        $d->then(
            function ($value) use (&$result) { $result = $value; },
            function ($reason) use (&$result) { $result = $reason; }
        );
        $this->assertEquals('fail', $result);
    }

    public function testSomeAggregatesSortedArrayWithMax()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = \GuzzleHttp\Promise\some(2, [$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->resolve('a');
        $d->then(function ($value) use (&$result) { $result = $value; });
        $this->assertEquals(['b', 'c'], $result);
    }

    public function testCanWaitUntilSomeCountIsSatisfied()
    {
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $b = new Promise(function () use (&$b) { $b->resolve('b'); });
        $c = new Promise(function () use (&$c) { $c->resolve('c'); });
        $d = \GuzzleHttp\Promise\some(2, [$a, $b, $c]);
        $this->assertEquals(['a', 'b'], $d->wait());
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Not enough promises to fulfill count
     */
    public function testThrowsIfImpossibleToWaitForSomeCount()
    {
        $a = new Promise(function () use (&$a) { $a->resolve('a'); });
        $d = \GuzzleHttp\Promise\some(2, [$a]);
        $d->wait();
    }

    public function testAnyReturnsFirstMatch()
    {
        $a = new Promise();
        $b = new Promise();
        $c = \GuzzleHttp\Promise\any([$a, $b]);
        $b->resolve('b');
        $a->resolve('a');
        $this->assertEquals('fulfilled', $c->getState());
        $c->then(function ($value) use (&$result) { $result = $value; });
        $this->assertEquals('b', $result);
    }

    public function testSettleFulfillsWithFulfilledAndRejected()
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = \GuzzleHttp\Promise\settle([$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->reject('a');
        $this->assertEquals('fulfilled', $d->getState());
        $d->then(function ($value) use (&$result) { $result = $value; });
        $this->assertEquals([
            ['state' => 'rejected', 'reason' => 'a'],
            ['state' => 'fulfilled', 'value' => 'b'],
            ['state' => 'fulfilled', 'value' => 'c']
        ], $result);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresIteratorIsReturnedForCoroutine()
    {
        \GuzzleHttp\Promise\coroutine(function () { return ':('; });
    }
}
