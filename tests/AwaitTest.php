<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use PHPUnit\Framework\TestCase;

class AwaitTest extends TestCase
{
    public function testWaitsOnAllPromisesIntoArray(): void
    {
        $e = new \Exception();
        $a = new Promise(function () use (&$a): void { $a->resolve('a'); });
        $b = new Promise(function () use (&$b): void { $b->reject('b'); });
        $c = new Promise(function () use (&$c, $e): void { $c->reject($e); });
        $results = P\Await::inspectAll([$a, $b, $c]);
        $this->assertSame([
            ['state' => 'fulfilled', 'value' => 'a'],
            ['state' => 'rejected', 'reason' => 'b'],
            ['state' => 'rejected', 'reason' => $e],
        ], $results);
    }

    public function testUnwrapsPromisesWithNoDefaultAndFailure(): void
    {
        $this->expectException(RejectionException::class);

        $promises = [new FulfilledPromise('a'), new Promise()];
        P\Await::unwrap($promises);
    }

    public function testUnwrapsPromisesWithNoDefault(): void
    {
        $promises = [new FulfilledPromise('a')];
        $this->assertSame(['a'], P\Await::unwrap($promises));
    }

    public function testUnwrapsPromisesWithKeys(): void
    {
        $promises = [
            'foo' => new FulfilledPromise('a'),
            'bar' => new FulfilledPromise('b'),
        ];
        $this->assertSame([
            'foo' => 'a',
            'bar' => 'b',
        ], P\Await::unwrap($promises));
    }

    public function testCanInspectFulfilledPromise(): void
    {
        $p = new FulfilledPromise('foo');
        $this->assertSame([
            'state' => 'fulfilled',
            'value' => 'foo',
        ], P\Await::inspect($p));
    }

    public function testCanInspectRejectedPromise(): void
    {
        $p = new RejectedPromise('foo');
        $this->assertSame([
            'state' => 'rejected',
            'reason' => 'foo',
        ], P\Await::inspect($p));
    }

    public function testCanInspectRejectedPromiseWithNormalException(): void
    {
        $e = new \Exception('foo');
        $p = new RejectedPromise($e);
        $this->assertSame([
            'state' => 'rejected',
            'reason' => $e,
        ], P\Await::inspect($p));
    }

    public function testInspectPreservesRejectionExceptionAsReason(): void
    {
        $reason = new RejectionException('inner');
        $result = P\Await::inspect(new RejectedPromise($reason));

        $this->assertSame(PromiseInterface::REJECTED, $result['state']);
        $this->assertSame($reason, $result['reason']);
    }

    public function testInspectPreservesCancellationExceptionAsReason(): void
    {
        $promise = new Promise();
        $promise->cancel();

        $result = P\Await::inspect($promise);

        $this->assertSame(PromiseInterface::REJECTED, $result['state']);
        $this->assertInstanceOf(P\CancellationException::class, $result['reason']);
        $this->assertSame('Promise has been cancelled', $result['reason']->getReason());
    }

    public function testInspectHandlesPromiseResolvedWithRejectedPromise(): void
    {
        $promise = new Promise();
        $promise->resolve(new RejectedPromise('inner'));

        $result = P\Await::inspect($promise);

        $this->assertSame(PromiseInterface::REJECTED, $result['state']);
        $this->assertSame('inner', $result['reason']);
    }
}
