<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\CrossContextException;
use GuzzleHttp\Promise\ExecutionContext;
use GuzzleHttp\Promise\FiberExecutionContextProvider;
use GuzzleHttp\Promise\Promise;
use PHPUnit\Framework\TestCase;

class FiberExecutionContextProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->markTestSkipped('Fibers require PHP 8.1 or newer.');
        }
    }

    protected function tearDown(): void
    {
        ExecutionContext::setProvider(null);
        P\Utils::queue()->run();
    }

    public function testIdentifiesTheRunningFiber(): void
    {
        $provider = new FiberExecutionContextProvider();
        $fiber = new \Fiber(static fn () => $provider->current());

        $this->assertNull($provider->current());
        $this->assertSame($fiber, $fiber->start() ?? $fiber->getReturn());
    }

    public function testCallbacksRunInTheirOwnFiberWhenAnotherFiberDrainsTheQueue(): void
    {
        ExecutionContext::setProvider(new FiberExecutionContextProvider());
        $ranIn = [];

        $a = new \Fiber(static function () use (&$ranIn): void {
            foreach ([1, 2] as $i) {
                $promise = new Promise();
                $promise->then(static function () use ($i, &$ranIn): void {
                    if ($i === 1) {
                        \Fiber::suspend();
                    }
                    $ranIn[$i] = \Fiber::getCurrent();
                });
                $promise->resolve(true);
            }
            P\Utils::queue()->run();
        });
        $b = new \Fiber(static function (): void {
            P\Utils::queue()->run();
        });

        $a->start();
        $b->start();
        $a->resume();

        $this->assertSame([1 => $a, 2 => $a], $ranIn);
    }

    public function testWaitingOnAnotherFibersPendingPromiseIsRejected(): void
    {
        ExecutionContext::setProvider(new FiberExecutionContextProvider());
        $promise = null;

        $a = new \Fiber(static function () use (&$promise): void {
            $promise = new Promise(static function () use (&$promise): void {
                $promise->resolve('value');
            });
            \Fiber::suspend();
        });
        $b = new \Fiber(static function () use (&$promise): void {
            $promise->wait();
        });

        $a->start();

        $this->expectException(CrossContextException::class);

        $b->start();
    }

    public function testSchedulersFinishFibersToDrainTheirWork(): void
    {
        ExecutionContext::setProvider(new FiberExecutionContextProvider());
        $ran = false;

        $fiber = new \Fiber(static function () use (&$ran): void {
            P\Utils::queue()->add(static function () use (&$ran): void {
                $ran = true;
            });
            \Fiber::suspend();
            ExecutionContext::finish(\Fiber::getCurrent());
        });

        $fiber->start();
        $this->assertFalse($ran);

        $fiber->resume();
        $this->assertTrue($ran);
    }

    public function testSuspendedWaitDoesNotKeepAnAbandonedFiberAlive(): void
    {
        ExecutionContext::setProvider(new FiberExecutionContextProvider());
        $promise = new Promise(static function (): void {
            \Fiber::suspend();
        });
        $fiber = new \Fiber(static function () use ($promise): void {
            $promise->wait();
        });
        $reference = \WeakReference::create($fiber);

        $fiber->start();
        unset($fiber);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }
}
