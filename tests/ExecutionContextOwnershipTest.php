<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\CrossContextException;
use GuzzleHttp\Promise\ExecutionContext;
use GuzzleHttp\Promise\ExecutionContextState;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\TestCase;

class ExecutionContextOwnershipTest extends TestCase
{
    private TestExecutionContextProvider $provider;

    private \stdClass $owner;

    private \stdClass $other;

    protected function setUp(): void
    {
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('Execution context providers require PHP 8.0 or newer.');
        }

        $this->provider = new TestExecutionContextProvider();
        $this->owner = new \stdClass();
        $this->other = new \stdClass();
        ExecutionContext::setProvider($this->provider);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setProvider(null);
        P\Utils::queue()->run();
    }

    /**
     * @return iterable<string, array{callable(Promise<mixed, mixed>): mixed}>
     */
    public static function operationProvider(): iterable
    {
        yield 'then' => [static fn (Promise $p) => $p->then(static function (): void {})];
        yield 'otherwise' => [static fn (Promise $p) => $p->otherwise(static function (): void {})];
        yield 'wait' => [static fn (Promise $p) => $p->wait()];
        yield 'cancel' => [static fn (Promise $p) => $p->cancel()];
        yield 'resolve' => [static fn (Promise $p) => $p->resolve('value')];
        yield 'reject' => [static fn (Promise $p) => $p->reject('reason')];
    }

    /**
     * @dataProvider operationProvider
     */
    public function testRejectsOperationsOnPendingPromisesFromAnotherContext(callable $operation): void
    {
        $waited = false;
        $cancelled = false;
        $promise = $this->provider->in($this->owner, static function () use (&$waited, &$cancelled): Promise {
            $promise = new Promise(static function () use (&$waited): void {
                $waited = true;
            }, static function () use (&$cancelled): void {
                $cancelled = true;
            });
            $promise->then(static function (): void {});

            return $promise;
        });

        try {
            $this->provider->in($this->other, static function () use ($operation, $promise): void {
                $operation($promise);
            });
            $this->fail('Cross-context use was not rejected.');
        } catch (CrossContextException $e) {
            $this->assertStringContainsString('owned by another execution context', $e->getMessage());
        }

        $this->assertTrue(P\Is::pending($promise));
        $this->assertFalse($waited);
        $this->assertFalse($cancelled);
        $this->assertCount(1, PropertyHelper::get($promise, 'handlers'));
    }

    public function testSettledPromisesCanBeSharedAcrossContexts(): void
    {
        $promise = $this->provider->in($this->owner, static function (): Promise {
            $promise = new Promise();
            $promise->resolve('value');

            return $promise;
        });

        $ranIn = null;
        $result = $this->provider->in($this->other, function () use ($promise, &$ranIn) {
            $next = $promise->then(function (string $value) use (&$ranIn): string {
                $ranIn = $this->provider->current;

                return $value.'!';
            });

            return $next->wait();
        });

        $this->assertSame('value!', $result);
        $this->assertSame($this->other, $ranIn);
    }

    public function testRejectsAdoptingAPendingPromiseOwnedByAnotherContext(): void
    {
        $foreign = $this->provider->in($this->other, static fn (): Promise => new Promise());
        $promise = $this->provider->in($this->owner, static fn (): Promise => new Promise());

        try {
            $this->provider->in($this->owner, static function () use ($promise, $foreign): void {
                $promise->resolve($foreign);
            });
            $this->fail('Adoption across contexts was not rejected.');
        } catch (CrossContextException $e) {
            $this->assertStringContainsString('Cannot adopt', $e->getMessage());
        }

        $this->assertTrue(P\Is::pending($promise));
    }

    public function testPromisesAdoptingPendingPromisesRemainProtected(): void
    {
        [$promise, $adopted] = $this->provider->in($this->owner, static function (): array {
            $adopted = new Promise();
            $promise = new Promise();
            $promise->resolve($adopted);

            return [$promise, $adopted];
        });

        $this->assertSame(PromiseInterface::FULFILLED, $promise->getState());

        try {
            $this->provider->in($this->other, static function () use ($promise): void {
                $promise->then(static function (): void {});
            });
            $this->fail('A promise adopting a pending promise was shared.');
        } catch (CrossContextException $e) {
            $this->addToAssertionCount(1);
        }

        $this->provider->in($this->owner, static function () use ($adopted): void {
            $adopted->resolve('value');
            P\Utils::queue()->run();
        });

        $result = $this->provider->in($this->other, static fn () => $promise->wait());
        $this->assertSame('value', $result);
    }

    public function testRejectsPendingPromisesWhoseContextHasEnded(): void
    {
        $context = new \stdClass();
        $promise = $this->provider->in($context, static fn (): Promise => new Promise());
        unset($context);
        gc_collect_cycles();

        $this->expectException(CrossContextException::class);
        $this->expectExceptionMessage('whose execution context has ended');

        $this->provider->in($this->other, static function () use ($promise): void {
            $promise->then(static function (): void {});
        });
    }

    public function testPromisesDoNotKeepTheirOwnerAlive(): void
    {
        $context = new \stdClass();
        $reference = \WeakReference::create($context);
        $promise = $this->provider->in($context, static fn (): Promise => new Promise());
        unset($context);
        gc_collect_cycles();

        $this->assertNull($reference->get());
        $this->assertTrue(P\Is::pending($promise));
    }

    public function testPromisesCreatedOutsideManagedContextsAreUnrestricted(): void
    {
        $promise = new Promise();

        $this->provider->in($this->other, static function () use ($promise): void {
            $promise->then(static function (): void {});
            $promise->resolve('value');
        });

        $this->assertSame(PromiseInterface::FULFILLED, $promise->getState());
    }

    public function testCallbacksRunInTheContextThatRegisteredThem(): void
    {
        $promise = new Promise();
        $ranIn = [];
        $record = function () use (&$ranIn): void {
            $ranIn[] = $this->provider->current;
        };

        $owned = $this->provider->in($this->owner, static fn () => $promise->then($record));
        $this->provider->in($this->other, static function () use ($promise, $record): void {
            $promise->then($record);
            $promise->resolve('value');
            P\Utils::queue()->run();
        });

        $this->assertSame([$this->other], $ranIn);
        $this->assertTrue(P\Is::pending($owned));

        $this->provider->in($this->owner, static function (): void {
            P\Utils::queue()->run();
        });

        $this->assertSame([$this->other, $this->owner], $ranIn);
        $this->assertTrue(P\Is::fulfilled($owned));
    }

    public function testAggregatesOfPromisesSettledElsewhereCompleteInTheirContext(): void
    {
        $first = new Promise();
        $second = new Promise();
        $all = $this->provider->in($this->owner, static fn () => P\Utils::all([$first, $second]));

        $this->provider->in($this->other, static function () use ($first, $second): void {
            $first->resolve('a');
            $second->resolve('b');
            P\Utils::queue()->run();
        });

        $this->assertSame(['a', 'b'], $this->provider->in($this->owner, static fn () => $all->wait()));
    }

    public function testFinishingAContextRunsCallbacksQueuedForIt(): void
    {
        $promise = new Promise();
        $ranIn = null;
        $this->provider->in($this->owner, function () use ($promise, &$ranIn): void {
            $promise->then(function () use (&$ranIn): void {
                $ranIn = $this->provider->current;
            });
        });

        $promise->resolve('value');
        $this->provider->end($this->owner);

        $this->assertSame($this->owner, $ranIn);
    }

    public function testQueuingWorkForAnotherContextDoesNotFinishItHere(): void
    {
        $ranIn = [];
        $this->provider->in($this->other, function () use (&$ranIn): void {
            ExecutionContextState::queueFor($this->owner)->add(function () use (&$ranIn): void {
                $ranIn[] = $this->provider->current;
            });
        });
        $this->provider->end($this->other);

        $this->assertSame([], $ranIn);

        $this->provider->in($this->owner, static function (): void {
            P\Utils::queue()->run();
        });

        $this->assertSame([$this->owner], $ranIn);
    }

    public function testCallbacksOfEndedContextsAreDropped(): void
    {
        $promise = new Promise();
        $context = new \stdClass();
        $ran = [];
        $this->provider->in($context, static function () use ($promise, &$ran): void {
            $promise->then(static function () use (&$ran): void {
                $ran[] = 'ended';
            });
        });
        $promise->then(static function () use (&$ran): void {
            $ran[] = 'unmanaged';
        });
        $this->provider->finalizers = [];
        unset($context);
        gc_collect_cycles();

        $promise->resolve('value');
        P\Utils::queue()->run();

        $this->assertSame(['unmanaged'], $ran);
    }

    public function testCallbacksOfFinishedContextsAreDropped(): void
    {
        $promise = new Promise();
        $ran = [];
        $callback = static function () use (&$ran): void {
            $ran[] = 'finished';
        };
        $reference = \WeakReference::create($callback);
        $this->provider->in($this->owner, static function () use ($promise, $callback): void {
            $promise->then($callback);
        });
        unset($callback);
        $promise->then(static function () use (&$ran): void {
            $ran[] = 'unmanaged';
        });
        $this->provider->end($this->owner);

        $promise->resolve('value');
        P\Utils::queue()->run();

        $this->assertNull($reference->get());

        $this->provider->in($this->owner, static function (): void {
            P\Utils::queue()->run();
        });

        $this->assertSame(['unmanaged'], $ran);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function thenableRefusalRecoveryProvider(): iterable
    {
        yield 'resolve' => ['resolve'];
        yield 'wait' => ['wait'];
        yield 'cancel' => ['cancel'];
    }

    /**
     * @dataProvider thenableRefusalRecoveryProvider
     */
    public function testForeignThenableRefusalRestoresThePromise(string $recovery): void
    {
        $foreign = $this->provider->in($this->other, static fn (): Promise => new Promise());
        $wrapper = new class($foreign) extends Thennable {
            private Promise $inner;

            public function __construct(Promise $inner)
            {
                parent::__construct();
                $this->inner = $inner;
            }

            public function then(?callable $res = null, ?callable $rej = null): PromiseInterface
            {
                return $this->inner->then($res, $rej);
            }
        };
        $cancelled = false;
        [$promise, $child] = $this->provider->in($this->owner, static function () use (&$cancelled): array {
            $promise = new Promise(static function () use (&$promise): void {
                $promise->resolve('recovered');
            }, static function () use (&$cancelled): void {
                $cancelled = true;
            });

            return [$promise, $promise->then(static fn (string $value): string => $value.'!')];
        });

        try {
            $this->provider->in($this->owner, static function () use ($promise, $wrapper): void {
                $promise->resolve($wrapper);
            });
            $this->fail('Settling with a promise that refused its callbacks succeeded.');
        } catch (CrossContextException $e) {
            $this->assertTrue(P\Is::pending($promise));
            $this->assertTrue(P\Is::pending($child));
        }

        $this->assertTrue(P\Is::pending($foreign));

        $this->provider->in($this->owner, function () use ($promise, $child, $recovery, &$cancelled): void {
            if ($recovery === 'cancel') {
                $promise->cancel();
                P\Utils::queue()->run();

                $this->assertTrue($cancelled);
                $this->assertTrue(P\Is::rejected($child));

                return;
            }

            if ($recovery === 'resolve') {
                $promise->resolve('recovered');
            }

            $this->assertSame('recovered!', $child->wait());
        });
    }

    public function testAThenableThatCallsBackBeforeThrowingLeavesThePromiseSettled(): void
    {
        $thenable = new class {
            public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
            {
                $onFulfilled('value');

                throw new \RuntimeException('Failed after calling back.');
            }
        };

        $this->provider->in($this->owner, function () use ($thenable): void {
            $promise = new Promise();
            $child = $promise->then(static fn (string $value): string => $value.'!');

            try {
                $promise->resolve($thenable);
                $this->fail('The thenable did not throw.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Failed after calling back.', $e->getMessage());
            }

            $this->assertSame(PromiseInterface::FULFILLED, $promise->getState());
            $this->assertSame('value!', $child->wait());
        });
    }

    public function testWaitingOnAPromiseThatAnotherContextIsWaitingOnIsRejected(): void
    {
        $attempt = null;
        $promise = new Promise(function () use (&$promise, &$attempt): void {
            try {
                $this->provider->in($this->other, static fn () => $promise->wait());
            } catch (CrossContextException $e) {
                $attempt = $e;
            }

            $promise->resolve('value');
        });

        $value = $this->provider->in($this->owner, static fn () => $promise->wait());

        $this->assertInstanceOf(CrossContextException::class, $attempt);
        $this->assertStringContainsString('another execution context is waiting on', $attempt->getMessage());
        $this->assertSame('value', $value);
    }

    public function testWaitingThroughAPromiseThatAnotherContextIsWaitingOnKeepsTheDependentWaitable(): void
    {
        $attempt = null;
        $child = null;
        $promise = new Promise(function () use (&$promise, &$child, &$attempt): void {
            try {
                $this->provider->in($this->other, static fn () => $child->wait());
            } catch (CrossContextException $e) {
                $attempt = $e;
            }

            $promise->resolve('value');
        });
        $child = $this->provider->in($this->other, static fn () => $promise->then(static fn (string $value): string => $value.'!'));

        $this->provider->in($this->owner, static fn () => $promise->wait());

        $this->assertInstanceOf(CrossContextException::class, $attempt);
        $this->assertSame('value!', $this->provider->in($this->other, static fn () => $child->wait()));
    }

    public function testAPromiseDoesNotKeepTheContextThatWaitedOnItAlive(): void
    {
        $context = new \stdClass();
        $reference = \WeakReference::create($context);
        $promise = new Promise(function () use (&$promise): void {
            $promise->resolve('value');
        });

        $this->provider->in($context, static fn () => $promise->wait());
        $this->provider->finalizers = [];
        unset($context);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    public function testCoroutinesAreProtectedThroughTheirPromise(): void
    {
        $pending = $this->provider->in($this->owner, static fn (): Promise => new Promise());
        $coroutine = $this->provider->in($this->owner, static fn () => P\Coroutine::of(static function () use ($pending) {
            yield $pending;
        }));

        $this->expectException(CrossContextException::class);

        $this->provider->in($this->other, static function () use ($coroutine): void {
            $coroutine->wait();
        });
    }

    public function testRejectsAdoptingAPendingCoroutineOwnedByAnotherContext(): void
    {
        $foreign = $this->provider->in($this->other, static fn () => P\Coroutine::of(static function () {
            yield new Promise();
        }));
        $promise = $this->provider->in($this->owner, static fn (): Promise => new Promise());

        try {
            $this->provider->in($this->owner, static function () use ($promise, $foreign): void {
                $promise->resolve($foreign);
            });
            $this->fail('Adopting a coroutine across contexts was not rejected.');
        } catch (CrossContextException $e) {
            $this->assertStringContainsString('Cannot adopt', $e->getMessage());
        }

        $this->assertTrue(P\Is::pending($promise));
    }

    public function testCoroutineCancellationFromAnotherContextDoesNotReachItsCurrentPromise(): void
    {
        $current = new class implements PromiseInterface {
            public int $cancelled = 0;

            public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
            {
                return $this;
            }

            public function otherwise(callable $onRejected): PromiseInterface
            {
                return $this;
            }

            public function getState(): string
            {
                return self::PENDING;
            }

            public function resolve($value = null): void
            {
            }

            public function reject($reason): void
            {
            }

            public function cancel(): void
            {
                ++$this->cancelled;
            }

            public function wait(bool $unwrap = true)
            {
                return null;
            }
        };
        $coroutine = $this->provider->in($this->owner, static fn () => P\Coroutine::of(static function () use ($current) {
            yield $current;
        }));

        try {
            $this->provider->in($this->other, static function () use ($coroutine): void {
                $coroutine->cancel();
            });
            $this->fail('A coroutine was cancelled from another context.');
        } catch (CrossContextException $e) {
            $this->assertSame(0, $current->cancelled);
        }

        $this->provider->in($this->owner, static function () use ($coroutine): void {
            $coroutine->cancel();
        });

        $this->assertSame(1, $current->cancelled);
        $this->assertSame(PromiseInterface::REJECTED, $coroutine->getState());
    }
}
