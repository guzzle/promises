<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Promise\Promise
 */
class PromiseTest extends TestCase
{
    public function testCannotResolveNonPendingPromise(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The promise is already fulfilled');

        $p = new Promise();
        $p->resolve('foo');
        $p->resolve('bar');
        $this->assertSame('foo', $p->wait());
    }

    public function testCanResolveWithSameValue(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->resolve('foo');
        $this->assertSame('foo', $p->wait());
    }

    public function testCannotRejectNonPendingPromise(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot change a fulfilled promise to rejected');

        $p = new Promise();
        $p->resolve('foo');
        $p->reject('bar');
        $this->assertSame('foo', $p->wait());
    }

    public function testCanRejectWithSameValue(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $p->reject('foo');
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testCannotRejectResolveWithSameValue(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot change a fulfilled promise to rejected');

        $p = new Promise();
        $p->resolve('foo');
        $p->reject('foo');
    }

    public function testInvokesWaitFunction(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->resolve('10');
        });
        $this->assertSame('10', $p->wait());
    }

    public function testRejectsAndThrowsWhenWaitFailsToResolve(): void
    {
        $this->expectException(\GuzzleHttp\Promise\RejectionException::class);
        $this->expectExceptionMessage('The promise was rejected with reason: Invoking the wait callback did not resolve the promise');

        $p = new Promise(function (): void {});
        $p->wait();
    }

    public function testThrowsWhenUnwrapIsRejectedWithNonException(): void
    {
        $this->expectException(\GuzzleHttp\Promise\RejectionException::class);
        $this->expectExceptionMessage('The promise was rejected with reason: foo');

        $p = new Promise(function () use (&$p): void {
            $p->reject('foo');
        });
        $p->wait();
    }

    public function testThrowsWhenUnwrapIsRejectedWithException(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('foo');

        $e = new \UnexpectedValueException('foo');
        $p = new Promise(function () use (&$p, $e): void {
            $p->reject($e);
        });
        $p->wait();
    }

    public function testDoesNotUnwrapExceptionsWhenDisabled(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->reject('foo');
        });
        $this->assertTrue(P\Is::pending($p));
        $p->wait(false);
        $this->assertTrue(P\Is::rejected($p));
    }

    public function testRejectsSelfWhenWaitThrows(): void
    {
        $e = new \UnexpectedValueException('foo');
        $p = new Promise(function () use ($e): void {
            throw $e;
        });
        try {
            $p->wait();
            $this->fail();
        } catch (\UnexpectedValueException $e) {
            $this->assertTrue(P\Is::rejected($p));
        }
    }

    public function testWaitsOnNestedPromises(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->resolve('_');
        });
        $p2 = new Promise(function () use (&$p2): void {
            $p2->resolve('foo');
        });
        $p3 = $p->then(function () use ($p2) {
            return $p2;
        });
        $this->assertSame('foo', $p3->wait());
    }

    public function testThrowsWhenWaitingOnPromiseWithNoWaitFunction(): void
    {
        $this->expectException(\GuzzleHttp\Promise\RejectionException::class);

        $p = new Promise();
        $p->wait();
    }

    public function testThrowsWaitExceptionAfterPromiseIsResolved(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->reject('Foo!');
            throw new \Exception('Bar?');
        });

        try {
            $p->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame('Bar?', $e->getMessage());
        }
    }

    public function testGetsActualWaitValueFromThen(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->reject('Foo!');
        });
        $p2 = $p->then(null, function ($reason) {
            return new RejectedPromise([$reason]);
        });

        try {
            $p2->wait();
            $this->fail('Should have thrown');
        } catch (RejectionException $e) {
            $this->assertSame(['Foo!'], $e->getReason());
        }
    }

    public function testWaitBehaviorIsBasedOnLastPromiseInChain(): void
    {
        $p3 = new Promise(function () use (&$p3): void {
            $p3->resolve('Whoop');
        });
        $p2 = new Promise(function () use (&$p2, $p3): void {
            $p2->reject($p3);
        });
        $p = new Promise(function () use (&$p, $p2): void {
            $p->reject($p2);
        });
        $this->assertSame('Whoop', $p->wait());
    }

    public function testWaitsOnAPromiseChainEvenWhenNotUnwrapped(): void
    {
        $p2 = new Promise(function () use (&$p2): void {
            $p2->reject('Fail');
        });
        $p = new Promise(function () use ($p2, &$p): void {
            $p->resolve($p2);
        });
        $p->wait(false);
        $this->assertTrue(P\Is::rejected($p2));
    }

    public function testCannotCancelNonPending(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $p->cancel();
        $this->assertTrue(P\Is::fulfilled($p));
    }

    public function testCancelsPromiseWhenNoCancelFunction(): void
    {
        $this->expectException(\GuzzleHttp\Promise\CancellationException::class);

        $p = new Promise();
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
        $p->wait();
    }

    public function testCancelsPromiseWithCancelFunction(): void
    {
        $called = false;
        $p = new Promise(null, function () use (&$called): void {
            $called = true;
        });
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
        $this->assertTrue($called);
    }

    public function testCancelsUppermostPendingPromise(): void
    {
        $called = false;
        $p1 = new Promise(null, function () use (&$called): void {
            $called = true;
        });
        $p2 = $p1->then(function (): void {});
        $p3 = $p2->then(function (): void {});
        $p4 = $p3->then(function (): void {});
        $p3->cancel();
        $this->assertTrue(P\Is::rejected($p1));
        $this->assertTrue(P\Is::rejected($p2));
        $this->assertTrue(P\Is::rejected($p3));
        $this->assertTrue(P\Is::pending($p4));
        $this->assertTrue($called);

        try {
            $p3->wait();
            $this->fail();
        } catch (CancellationException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        try {
            $p4->wait();
            $this->fail();
        } catch (CancellationException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        $this->assertTrue(P\Is::rejected($p4));
    }

    public function testCancelsChildPromises(): void
    {
        $called1 = $called2 = $called3 = false;
        $p1 = new Promise(null, function () use (&$called1): void {
            $called1 = true;
        });
        $p2 = new Promise(null, function () use (&$called2): void {
            $called2 = true;
        });
        $p3 = new Promise(null, function () use (&$called3): void {
            $called3 = true;
        });
        $p4 = $p2->then(function () use ($p3) {
            return $p3;
        });
        $p5 = $p4->then(function (): void {
            $this->fail();
        });
        $p4->cancel();
        $this->assertTrue(P\Is::pending($p1));
        $this->assertTrue(P\Is::rejected($p2));
        $this->assertTrue(P\Is::pending($p3));
        $this->assertTrue(P\Is::rejected($p4));
        $this->assertTrue(P\Is::pending($p5));
        $this->assertFalse($called1);
        $this->assertTrue($called2);
        $this->assertFalse($called3);
    }

    public function testRejectsPromiseWhenCancelFails(): void
    {
        $called = false;
        $p = new Promise(null, function () use (&$called): void {
            $called = true;
            throw new \Exception('e');
        });
        $p->cancel();
        $this->assertTrue(P\Is::rejected($p));
        $this->assertTrue($called);
        try {
            $p->wait();
            $this->fail();
        } catch (\Exception $e) {
            $this->assertSame('e', $e->getMessage());
        }
    }

    public function testCreatesPromiseWhenFulfilledAfterThen(): void
    {
        $p = new Promise();
        $carry = null;
        $p2 = $p->then(function ($v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $p->resolve('foo');
        P\Utils::queue()->run();

        $this->assertSame('foo', $carry);
    }

    public function testCreatesPromiseWhenFulfilledBeforeThen(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $carry = null;
        $p2 = $p->then(function ($v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $this->assertNull($carry);
        P\Utils::queue()->run();
        $this->assertSame('foo', $carry);
    }

    public function testCreatesPromiseWhenFulfilledWithNoCallback(): void
    {
        $p = new Promise();
        $p->resolve('foo');
        $p2 = $p->then();
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf(FulfilledPromise::class, $p2);
    }

    public function testCreatesPromiseWhenRejectedAfterThen(): void
    {
        $p = new Promise();
        $carry = null;
        $p2 = $p->then(null, function ($v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $carry);
    }

    public function testCreatesPromiseWhenRejectedBeforeThen(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $carry = null;
        $p2 = $p->then(null, function ($v) use (&$carry): void {
            $carry = $v;
        });
        $this->assertNotSame($p, $p2);
        $this->assertNull($carry);
        P\Utils::queue()->run();
        $this->assertSame('foo', $carry);
    }

    public function testCreatesPromiseWhenRejectedWithNoCallback(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $p2 = $p->then();
        $this->assertNotSame($p, $p2);
        $this->assertInstanceOf(RejectedPromise::class, $p2);
    }

    public function testInvokesWaitFnsForThens(): void
    {
        $p = new Promise(function () use (&$p): void {
            $p->resolve('a');
        });
        $p2 = $p
            ->then(function ($v) {
                return $v.'-1-';
            })
            ->then(function ($v) {
                return $v.'2';
            });
        $this->assertSame('a-1-2', $p2->wait());
    }

    public function testStacksThenWaitFunctions(): void
    {
        $p1 = new Promise(function () use (&$p1): void {
            $p1->resolve('a');
        });
        $p2 = new Promise(function () use (&$p2): void {
            $p2->resolve('b');
        });
        $p3 = new Promise(function () use (&$p3): void {
            $p3->resolve('c');
        });
        $p4 = $p1
            ->then(function () use ($p2) {
                return $p2;
            })
            ->then(function () use ($p3) {
                return $p3;
            });
        $this->assertSame('c', $p4->wait());
    }

    public function testForwardsFulfilledDownChainBetweenGaps(): void
    {
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(function ($v) use (&$r) {
                $r = $v;

                return $v.'2';
            })
            ->then(function ($v) use (&$r2): void {
                $r2 = $v;
            });
        $p->resolve('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame('foo2', $r2);
    }

    public function testForwardsRejectedPromisesDownChainBetweenGaps(): void
    {
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function ($v) use (&$r) {
                $r = $v;

                return $v.'2';
            })
            ->then(function ($v) use (&$r2): void {
                $r2 = $v;
            });
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame('foo2', $r2);
    }

    public function testForwardsThrownPromisesDownChainBetweenGaps(): void
    {
        $e = new \Exception();
        $p = new Promise();
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function ($v) use (&$r, $e): void {
                $r = $v;
                throw $e;
            })
            ->then(
                null,
                function ($v) use (&$r2): void {
                    $r2 = $v;
                }
            );
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame($e, $r2);
    }

    public function testForwardsReturnedRejectedPromisesDownChainBetweenGaps(): void
    {
        $p = new Promise();
        $rejected = new RejectedPromise('bar');
        $r = $r2 = null;
        $p->then(null, null)
            ->then(null, function ($v) use (&$r, $rejected) {
                $r = $v;

                return $rejected;
            })
            ->then(
                null,
                function ($v) use (&$r2): void {
                    $r2 = $v;
                }
            );
        $p->reject('foo');
        P\Utils::queue()->run();
        $this->assertSame('foo', $r);
        $this->assertSame('bar', $r2);
        try {
            $p->wait();
        } catch (RejectionException $e) {
            $this->assertSame('foo', $e->getReason());
        }
    }

    public function testForwardsHandlersToNextPromise(): void
    {
        $p = new Promise();
        $p2 = new Promise();
        $resolved = null;
        $p
            ->then(function ($v) use ($p2) {
                return $p2;
            })
            ->then(function ($value) use (&$resolved): void {
                $resolved = $value;
            });
        $p->resolve('a');
        $p2->resolve('b');
        P\Utils::queue()->run();
        $this->assertSame('b', $resolved);
    }

    public function testRemovesReferenceFromChildWhenParentWaitedUpon(): void
    {
        $r = null;
        $p = new Promise(function () use (&$p): void {
            $p->resolve('a');
        });
        $p2 = new Promise(function () use (&$p2): void {
            $p2->resolve('b');
        });
        $pb = $p->then(
            function ($v) use ($p2, &$r) {
                $r = $v;

                return $p2;
            }
        )
            ->then(function ($v) {
                return $v.'.';
            });
        $this->assertSame('a', $p->wait());
        $this->assertSame('b', $p2->wait());
        $this->assertSame('b.', $pb->wait());
        $this->assertSame('a', $r);
    }

    public function testForwardsHandlersWhenFulfilledPromiseIsReturned(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->resolve('foo');
        $p2->then(function ($v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        // $res is A:foo
        $p
            ->then(function () use ($p2, &$res) {
                $res[] = 'B';

                return $p2;
            })
            ->then(function ($v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function ($v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['A:foo', 'B', 'D:a', 'C:foo'], $res);
    }

    public function testForwardsHandlersWhenRejectedPromiseIsReturned(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->reject('foo');
        $p2->then(null, function ($v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        $p->then(null, function () use ($p2, &$res) {
            $res[] = 'B';

            return $p2;
        })
            ->then(null, function ($v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->reject('a');
        $p->then(null, function ($v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['A:foo', 'B', 'D:a', 'C:foo'], $res);
    }

    public function testDoesNotForwardRejectedPromise(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Promise();
        $p2->cancel();
        $p2->then(function ($v) use (&$res) {
            $res[] = "B:$v";

            return $v;
        });
        $p->then(function ($v) use ($p2, &$res) {
            $res[] = "B:$v";

            return $p2;
        })
            ->then(function ($v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function ($v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['B:a', 'D:a'], $res);
    }

    public function testRecursivelyForwardsWhenOnlyThennable(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new Thennable();
        $p2->resolve('foo');
        $p2->then(function ($v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        $p->then(function () use ($p2, &$res) {
            $res[] = 'B';

            return $p2;
        })
            ->then(function ($v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function ($v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['A:foo', 'B', 'D:a', 'C:foo'], $res);
    }

    public function testRecursivelyForwardsWhenNotInstanceOfPromise(): void
    {
        $res = [];
        $p = new Promise();
        $p2 = new NotPromiseInstance();
        $p2->then(function ($v) use (&$res): void {
            $res[] = 'A:'.$v;
        });
        $p->then(function () use ($p2, &$res) {
            $res[] = 'B';

            return $p2;
        })
            ->then(function ($v) use (&$res): void {
                $res[] = 'C:'.$v;
            });
        $p->resolve('a');
        $p->then(function ($v) use (&$res): void {
            $res[] = 'D:'.$v;
        });
        P\Utils::queue()->run();
        $this->assertSame(['B', 'D:a'], $res);
        $p2->resolve('foo');
        P\Utils::queue()->run();
        $this->assertSame(['B', 'D:a', 'A:foo', 'C:foo'], $res);
    }

    public function testCannotResolveWithSelf(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot fulfill or reject a promise with itself');

        $p = new Promise();
        $p->resolve($p);
    }

    public function testCannotRejectWithSelf(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot fulfill or reject a promise with itself');

        $p = new Promise();
        $p->reject($p);
    }

    public function testDoesNotBlowStackWhenWaitingOnNestedThens(): void
    {
        $inner = new Promise(function () use (&$inner): void {
            $inner->resolve(0);
        });
        $prev = $inner;
        for ($i = 1; $i < 100; ++$i) {
            $prev = $prev->then(function ($i) {
                return $i + 1;
            });
        }

        $parent = new Promise(function () use (&$parent, $prev): void {
            $parent->resolve($prev);
        });

        $this->assertSame(99, $parent->wait());
    }

    public function testOtherwiseIsSugarForRejections(): void
    {
        $p = new Promise();
        $p->reject('foo');
        $p->otherwise(function ($v) use (&$c): void {
            $c = $v;
        });
        P\Utils::queue()->run();
        $this->assertSame($c, 'foo');
    }

    public function testRepeatedWaitFulfilled(): void
    {
        $promise = new Promise(function () use (&$promise): void {
            $promise->resolve('foo');
        });

        $this->assertSame('foo', $promise->wait());
        $this->assertSame('foo', $promise->wait());
    }

    public function testRepeatedWaitRejected(): void
    {
        $promise = new Promise(function () use (&$promise): void {
            $promise->reject(new \RuntimeException('foo'));
        });

        $exceptionCount = 0;
        try {
            $promise->wait();
        } catch (\Exception $e) {
            $this->assertSame('foo', $e->getMessage());
            ++$exceptionCount;
        }

        try {
            $promise->wait();
        } catch (\Exception $e) {
            $this->assertSame('foo', $e->getMessage());
            ++$exceptionCount;
        }

        $this->assertSame(2, $exceptionCount);
    }
}
