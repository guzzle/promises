<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

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
    public function testReturnsTrampoline(): void
    {
        $this->assertInstanceOf(TaskQueue::class, P\Utils::queue());
        $this->assertSame(P\Utils::queue(), P\Utils::queue());
    }

    public function testCanScheduleThunk(): void
    {
        $tramp = P\Utils::queue();
        $promise = P\Utils::task(function (): string { return 'Hi!'; });
        $c = null;
        $promise->then(function (string $v) use (&$c): void { $c = $v; });
        $this->assertNull($c);
        $tramp->run();
        $this->assertSame('Hi!', $c);
    }

    public function testCanScheduleThunkWithRejection(): void
    {
        $tramp = P\Utils::queue();
        $promise = P\Utils::task(function (): void { throw new \Exception('Hi!'); });
        $c = null;
        $promise->otherwise(function (\Exception $v) use (&$c): void { $c = $v; });
        $this->assertNull($c);
        $tramp->run();
        $this->assertSame('Hi!', $c->getMessage());
    }

    public function testCanScheduleThunkWithWait(): void
    {
        $tramp = P\Utils::queue();
        $promise = P\Utils::task(function (): string { return 'a'; });
        $this->assertSame('a', $promise->wait());
        $tramp->run();
    }

    public function testYieldsFromCoroutine(): void
    {
        $promise = P\Coroutine::of(function (): \Generator {
            $value = (yield new FulfilledPromise('a'));
            yield $value.'b';
        });
        $promise->then(function (string $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame('ab', $result);
    }

    public function testCanCatchExceptionsInCoroutine(): void
    {
        $promise = P\Coroutine::of(function (): \Generator {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                $value = (yield new FulfilledPromise($e->getReason()));
                yield $value.'b';
            }
        });
        $promise->then(function (string $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertTrue(P\State::isFulfilled($promise));
        $this->assertSame('ab', $result);
    }

    /**
     * @dataProvider rejectsParentExceptionProvider
     */
    public function testRejectsParentExceptionWhenException(PromiseInterface $promise): void
    {
        $promise->then(
            function (): void { $this->fail(); },
            function (\Exception $reason) use (&$result): void { $result = $reason; }
        );
        P\Utils::queue()->run();
        $this->assertInstanceOf(\Exception::class, $result);
        $this->assertSame('a', $result->getMessage());
    }

    public static function rejectsParentExceptionProvider(): array
    {
        return [
            [P\Coroutine::of(function (): \Generator {
                yield new FulfilledPromise(0);
                throw new \Exception('a');
            })],
            [P\Coroutine::of(function (): \Generator {
                throw new \Exception('a');
                yield new FulfilledPromise(0);
            })],
        ];
    }

    public function testCanRejectFromRejectionCallback(): void
    {
        $promise = P\Coroutine::of(function (): \Generator {
            yield new FulfilledPromise(0);
            yield new RejectedPromise('no!');
        });
        $promise->then(
            function (): void { $this->fail(); },
            function (RejectionException $reason) use (&$result): void { $result = $reason; }
        );
        P\Utils::queue()->run();
        $this->assertInstanceOf(RejectionException::class, $result);
        $this->assertSame('no!', $result->getReason());
    }

    public function testCanAsyncReject(): void
    {
        $rej = new Promise();
        $promise = P\Coroutine::of(function () use ($rej): \Generator {
            yield new FulfilledPromise(0);
            yield $rej;
        });
        $promise->then(
            function (): void { $this->fail(); },
            function (RejectionException $reason) use (&$result): void { $result = $reason; }
        );
        $rej->reject('no!');
        P\Utils::queue()->run();
        $this->assertInstanceOf(RejectionException::class, $result);
        $this->assertSame('no!', $result->getReason());
    }

    public function testCanCatchAndThrowOtherException(): void
    {
        $promise = P\Coroutine::of(function (): \Generator {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                throw new \Exception('foo');
            }
        });
        $promise->otherwise(function (\Exception $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertTrue(P\State::isRejected($promise));
        $this->assertStringContainsString('foo', $result->getMessage());
    }

    public function testCanCatchAndYieldOtherException(): void
    {
        $promise = P\Coroutine::of(function (): \Generator {
            try {
                yield new RejectedPromise('a');
                $this->fail('Should have thrown into the coroutine!');
            } catch (RejectionException $e) {
                yield new RejectedPromise('foo');
            }
        });
        $promise->otherwise(function (RejectionException $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertTrue(P\State::isRejected($promise));
        $this->assertStringContainsString('foo', $result->getMessage());
    }

    public function createLotsOfSynchronousPromise(): PromiseInterface
    {
        return P\Coroutine::of(function (): \Generator {
            $value = 0;
            for ($i = 0; $i < 1000; ++$i) {
                $value = (yield new FulfilledPromise($i));
            }
            yield $value;
        });
    }

    public function testLotsOfSynchronousDoesNotBlowStack(): void
    {
        $promise = $this->createLotsOfSynchronousPromise();
        $promise->then(function (int $v) use (&$r): void { $r = $v; });
        P\Utils::queue()->run();
        $this->assertSame(999, $r);
    }

    public function testLotsOfSynchronousWaitDoesNotBlowStack(): void
    {
        $promise = $this->createLotsOfSynchronousPromise();
        $promise->then(function (int $v) use (&$r): void { $r = $v; });
        $this->assertSame(999, $promise->wait());
        $this->assertSame(999, $r);
    }

    private function createLotsOfFlappingPromise(): PromiseInterface
    {
        return P\Coroutine::of(function (): \Generator {
            $value = 0;
            for ($i = 0; $i < 1000; ++$i) {
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

    public function testLotsOfTryCatchingDoesNotBlowStack(): void
    {
        $promise = $this->createLotsOfFlappingPromise();
        $promise->then(function (int $v) use (&$r): void { $r = $v; });
        P\Utils::queue()->run();
        $this->assertSame(999, $r);
    }

    public function testLotsOfTryCatchingWaitingDoesNotBlowStack(): void
    {
        $promise = $this->createLotsOfFlappingPromise();
        $promise->then(function (int $v) use (&$r): void { $r = $v; });
        $this->assertSame(999, $promise->wait());
        $this->assertSame(999, $r);
    }

    public function testAsyncPromisesWithCorrectlyYieldedValues(): void
    {
        $promises = [
            new Promise(),
            new Promise(),
            new Promise(),
        ];

        eval('
        $promise = \GuzzleHttp\Promise\Coroutine::of(function () use ($promises): \Generator {
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

        $promise->then(function (int $v) use (&$r): void { $r = $v; });
        P\Utils::queue()->run();
        $this->assertSame(2, $r);
    }

    public function testYieldFinalWaitablePromise(): void
    {
        $p1 = new Promise(function () use (&$p1): void {
            $p1->resolve('skip me');
        });
        $p2 = new Promise(function () use (&$p2): void {
            $p2->resolve('hello!');
        });
        $co = P\Coroutine::of(function () use ($p1, $p2): \Generator {
            yield $p1;
            yield $p2;
        });
        P\Utils::queue()->run();
        $this->assertSame('hello!', $co->wait());
    }

    public function testCanYieldFinalPendingPromise(): void
    {
        $p1 = new Promise();
        $p2 = new Promise();
        $co = P\Coroutine::of(function () use ($p1, $p2): \Generator {
            yield $p1;
            yield $p2;
        });
        $p1->resolve('a');
        $p2->resolve('b');
        $co->then(function (string $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame('b', $result);
    }

    public function testCanNestYieldsAndFailures(): void
    {
        $p1 = new Promise();
        $p2 = new Promise();
        $p3 = new Promise();
        $p4 = new Promise();
        $p5 = new Promise();
        $co = P\Coroutine::of(function () use ($p1, $p2, $p3, $p4, $p5): \Generator {
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
        $co->then(function (string $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame('e', $result);
    }

    public function testCanYieldErrorsAndSuccessesWithoutRecursion(): void
    {
        $promises = [];
        for ($i = 0; $i < 20; ++$i) {
            $promises[] = new Promise();
        }

        $co = P\Coroutine::of(function () use ($promises): \Generator {
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

        $co->then(function (int $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame(19, $result);
    }

    public function testCanWaitOnPromiseAfterFulfilled(): void
    {
        $f = function (): Promise {
            static $i = 0;
            ++$i;

            return $p = new Promise(function () use (&$p, $i): void {
                $p->resolve($i.'-bar');
            });
        };

        $promises = [];
        for ($i = 0; $i < 20; ++$i) {
            $promises[] = $f();
        }

        $p = P\Coroutine::of(function () use ($promises): \Generator {
            yield new FulfilledPromise('foo!');
            foreach ($promises as $promise) {
                yield $promise;
            }
        });

        $this->assertSame('20-bar', $p->wait());
    }

    public function testCanWaitOnErroredPromises(): void
    {
        $p1 = new Promise(function () use (&$p1): void { $p1->reject('a'); });
        $p2 = new Promise(function () use (&$p2): void { $p2->resolve('b'); });
        $p3 = new Promise(function () use (&$p3): void { $p3->resolve('c'); });
        $p4 = new Promise(function () use (&$p4): void { $p4->reject('d'); });
        $p5 = new Promise(function () use (&$p5): void { $p5->resolve('e'); });
        $p6 = new Promise(function () use (&$p6): void { $p6->reject('f'); });

        $co = P\Coroutine::of(function () use ($p1, $p2, $p3, $p4, $p5, $p6): \Generator {
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

        $res = P\Await::inspect($co);
        $this->assertInstanceOf(RejectionException::class, $res['reason']);
        $this->assertSame('f', $res['reason']->getReason());
    }

    public function testCoroutineOtherwiseIntegrationTest(): void
    {
        $a = new Promise();
        $b = new Promise();
        $promise = P\Coroutine::of(function () use ($a, $b): \Generator {
            // Execute the pool of commands concurrently, and process errors.
            yield $a;
            yield $b;
        })->otherwise(function (\Exception $e): void {
            // Throw errors from the operations as a specific Multipart error.
            throw new \OutOfBoundsException('a', 0, $e);
        });
        $a->resolve('a');
        $b->reject('b');
        $reason = P\Await::inspect($promise)['reason'];
        $this->assertInstanceOf(\OutOfBoundsException::class, $reason);
        $this->assertInstanceOf(RejectionException::class, $reason->getPrevious());
    }

    public function testCanManuallySettleTaskQueueGeneratedPromises(): void
    {
        $p1 = P\Utils::task(function (): string { return 'a'; });
        $p2 = P\Utils::task(function (): string { return 'b'; });
        $p3 = P\Utils::task(function (): string { return 'c'; });

        $p1->cancel();
        $p2->resolve('b2');

        $results = P\Await::inspectAll([$p1, $p2, $p3]);

        $this->assertSame(PromiseInterface::REJECTED, $results[0]['state']);
        $this->assertInstanceOf(P\CancellationException::class, $results[0]['reason']);
        $this->assertSame('Promise has been cancelled', $results[0]['reason']->getReason());
        $this->assertSame(['state' => 'fulfilled', 'value' => 'b2'], $results[1]);
        $this->assertSame(['state' => 'fulfilled', 'value' => 'c'], $results[2]);
    }
}
