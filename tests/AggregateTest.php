<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\AggregateException;
use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\RejectionException;
use PHPUnit\Framework\TestCase;

class AggregateTest extends TestCase
{
    public function testAllAggregatesSortedArray(): void
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Aggregate::all([$a, $b, $c]);
        $b->resolve('b');
        $a->resolve('a');
        $c->resolve('c');
        $d->then(
            function (array $value) use (&$result): void { $result = $value; },
            function ($reason) use (&$result): void { $result = $reason; }
        );
        P\Utils::queue()->run();
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testPromisesDynamicallyAddedToStack(): void
    {
        $promises = new \ArrayIterator();
        $counter = 0;
        $promises['a'] = new FulfilledPromise('a');
        $promises['b'] = $promise = new Promise(function () use (&$promise, &$promises, &$counter): void {
            ++$counter; // Make sure the wait function is called only once
            $promise->resolve('b');
            $promises['c'] = $subPromise = new Promise(function () use (&$subPromise): void {
                $subPromise->resolve('c');
            });
        });
        $result = P\Aggregate::all($promises, true)->wait();
        $this->assertCount(3, $promises);
        $this->assertCount(3, $result);
        $this->assertSame($result['c'], 'c');
        $this->assertSame(1, $counter);
    }

    public function testAllRecursivelyHandlesRawValues(): void
    {
        $result = P\Aggregate::all(new \ArrayIterator(['a' => 1]), true)->wait();

        $this->assertSame(['a' => 1], $result);
    }

    public function testAllRecursivelyHandlesGeneratorInput(): void
    {
        $gen = (static function (): \Generator {
            yield 'a' => new FulfilledPromise(1);
            yield 'b' => 2;
        })();

        $result = P\Aggregate::all($gen, true)->wait();

        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function testAllRecursivelyIncludesDynamicallyAddedFulfilledPromise(): void
    {
        $promises = new \ArrayIterator();

        $promises['a'] = $promise = new Promise(function () use (&$promise, $promises): void {
            $promise->resolve('a');
            $promises['b'] = new FulfilledPromise('b');
        });

        $result = P\Aggregate::all($promises, true)->wait();

        $this->assertSame(['a' => 'a', 'b' => 'b'], $result);
    }

    public function testAllRecursivelyIncludesDynamicallyAddedRawValue(): void
    {
        $promises = new \ArrayIterator();

        $promises['a'] = $promise = new Promise(function () use (&$promise, $promises): void {
            $promise->resolve('a');
            $promises['b'] = 'b';
        });

        $result = P\Aggregate::all($promises, true)->wait();

        $this->assertSame(['a' => 'a', 'b' => 'b'], $result);
    }

    public function testAllRecursivelyRejectsDynamicallyAddedRejectedPromise(): void
    {
        $this->expectException(RejectionException::class);
        $this->expectExceptionMessage('bad');

        $promises = new \ArrayIterator();

        $promises['a'] = $promise = new Promise(function () use (&$promise, $promises): void {
            $promise->resolve('a');
            $promises['b'] = new RejectedPromise('bad');
        });

        P\Aggregate::all($promises, true)->wait();
    }

    public function testAllLimitsConcurrencyForLazyIterable(): void
    {
        $created = 0;
        $promises = [];

        $iterable = static function () use (&$created, &$promises): \Generator {
            foreach (['a', 'b', 'c', 'd'] as $key) {
                ++$created;
                $promises[$key] = new Promise();

                yield $key => $promises[$key];
            }
        };

        $aggregate = P\Aggregate::all($iterable(), false, ['concurrency' => 2]);

        $this->assertSame(2, $created);

        $promises['a']->resolve('A');
        P\Utils::queue()->run();

        $this->assertSame(3, $created);

        $promises['b']->resolve('B');
        P\Utils::queue()->run();

        $this->assertSame(4, $created);

        $promises['c']->resolve('C');
        $promises['d']->resolve('D');

        $this->assertSame([
            'a' => 'A',
            'b' => 'B',
            'c' => 'C',
            'd' => 'D',
        ], $aggregate->wait());
    }

    public function testAllRejectsWithConcurrencyConfig(): void
    {
        $created = 0;
        $promises = [];
        $result = null;

        $iterable = static function () use (&$created, &$promises): \Generator {
            foreach (['a', 'b'] as $key) {
                ++$created;
                $promises[$key] = new Promise();

                yield $key => $promises[$key];
            }
        };

        $aggregate = P\Aggregate::all($iterable(), false, ['concurrency' => 1]);

        $this->assertSame(1, $created);

        $promises['a']->reject('fail');
        P\Utils::queue()->run();

        $aggregate->then(null, function (string $reason) use (&$result): void {
            $result = $reason;
        });
        P\Utils::queue()->run();

        $this->assertSame('fail', $result);
        $this->assertSame(1, $created);
    }

    public function testAllAcceptsCallableConcurrencyConfig(): void
    {
        $pendingCounts = [];
        $promises = [new Promise(), new Promise()];

        $aggregate = P\Aggregate::all($promises, false, [
            'concurrency' => static function (int $pendingCount) use (&$pendingCounts): int {
                $pendingCounts[] = $pendingCount;

                return 1;
            },
        ]);

        $promises[0]->resolve('a');
        P\Utils::queue()->run();

        $promises[1]->resolve('b');

        $this->assertSame(['a', 'b'], $aggregate->wait());
        $this->assertSame([0, 0], $pendingCounts);
    }

    public function testAllIgnoresCallbackConfigKeys(): void
    {
        $result = P\Aggregate::all([new FulfilledPromise('a')], false, [
            'fulfilled' => static function (): void {
                throw new \RuntimeException('Should not be called.');
            },
            'rejected' => static function (): void {
                throw new \RuntimeException('Should not be called.');
            },
        ])->wait();

        $this->assertSame(['a'], $result);
    }

    public function testPromisesDynamicallyAddedToStackWithConcurrencyConfig(): void
    {
        $promises = new \ArrayIterator();
        $counter = 0;
        $promises['a'] = new FulfilledPromise('a');
        $promises['b'] = $promise = new Promise(function () use (&$promise, &$promises, &$counter): void {
            ++$counter;
            $promise->resolve('b');
            $promises['c'] = $subPromise = new Promise(function () use (&$subPromise): void {
                $subPromise->resolve('c');
            });
        });

        $result = P\Aggregate::all($promises, true, ['concurrency' => 1])->wait();

        $this->assertCount(3, $promises);
        $this->assertCount(3, $result);
        $this->assertSame($result['c'], 'c');
        $this->assertSame(1, $counter);
    }

    public function testAllThrowsWhenAnyRejected(): void
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Aggregate::all([$a, $b, $c]);
        $b->resolve('b');
        $a->reject('fail');
        $c->resolve('c');
        $d->then(
            function (array $value) use (&$result): void { $result = $value; },
            function (string $reason) use (&$result): void { $result = $reason; }
        );
        P\Utils::queue()->run();
        $this->assertSame('fail', $result);
    }

    public function testSomeAggregatesSortedArrayWithMax(): void
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Aggregate::some(2, [$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->resolve('a');
        $d->then(function (array $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame(['b', 'c'], $result);
    }

    public function testSomeRejectsWhenTooManyRejections(): void
    {
        $a = new Promise();
        $b = new Promise();
        $d = P\Aggregate::some(2, [$a, $b]);
        $a->reject('bad');
        $b->resolve('good');
        P\Utils::queue()->run();
        $this->assertTrue(P\State::isRejected($d));
        $d->then(null, function (AggregateException $reason) use (&$called): void {
            $called = $reason;
        });
        P\Utils::queue()->run();
        $this->assertInstanceOf(AggregateException::class, $called);
        $this->assertContains('bad', $called->getReason());
    }

    public function testInspectPreservesAggregateExceptionFromSome(): void
    {
        $a = new Promise(function () use (&$a): void { $a->reject('bad'); });
        $b = new Promise(function () use (&$b): void { $b->resolve('good'); });

        $result = P\Await::inspect(P\Aggregate::some(2, [$a, $b]));

        $this->assertSame(PromiseInterface::REJECTED, $result['state']);
        $this->assertInstanceOf(AggregateException::class, $result['reason']);
        $this->assertContains('bad', $result['reason']->getReason());
    }

    public function testCanWaitUntilSomeCountIsSatisfied(): void
    {
        $a = new Promise(function () use (&$a): void { $a->resolve('a'); });
        $b = new Promise(function () use (&$b): void { $b->resolve('b'); });
        $c = new Promise(function () use (&$c): void { $c->resolve('c'); });
        $d = P\Aggregate::some(2, [$a, $b, $c]);
        $this->assertSame(['a', 'b'], $d->wait());
    }

    public function testThrowsIfImpossibleToWaitForSomeCount(): void
    {
        $this->expectException(AggregateException::class);
        $this->expectExceptionMessage('Not enough promises to fulfill count');

        $a = new Promise(function () use (&$a): void { $a->resolve('a'); });
        $d = P\Aggregate::some(2, [$a]);
        $d->wait();
    }

    public function testThrowsIfResolvedWithoutCountTotalResults(): void
    {
        $this->expectException(AggregateException::class);
        $this->expectExceptionMessage('Not enough promises to fulfill count');

        $a = new Promise();
        $b = new Promise();
        $d = P\Aggregate::some(3, [$a, $b]);
        $a->resolve('a');
        $b->resolve('b');
        $d->wait();
    }

    public function testAnyReturnsFirstMatch(): void
    {
        $a = new Promise();
        $b = new Promise();
        $c = P\Aggregate::any([$a, $b]);
        $b->resolve('b');
        $a->resolve('a');
        $c->then(function (string $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame('b', $result);
    }

    public function testSettleFulfillsWithFulfilledAndRejected(): void
    {
        $a = new Promise();
        $b = new Promise();
        $c = new Promise();
        $d = P\Aggregate::settle([$a, $b, $c]);
        $b->resolve('b');
        $c->resolve('c');
        $a->reject('a');
        P\Utils::queue()->run();
        $this->assertTrue(P\State::isFulfilled($d));
        $d->then(function (array $value) use (&$result): void { $result = $value; });
        P\Utils::queue()->run();
        $this->assertSame([
            ['state' => 'rejected', 'reason' => 'a'],
            ['state' => 'fulfilled', 'value' => 'b'],
            ['state' => 'fulfilled', 'value' => 'c'],
        ], $result);
    }

    public function testSettleRecursivelyWaitsOnDynamicallyAddedPendingPromise(): void
    {
        $promises = new \ArrayIterator();
        $waited = false;

        $promises['a'] = $promise = new Promise(function () use (&$promise, $promises, &$waited): void {
            $promise->resolve('a');

            $promises['b'] = $next = new Promise(function () use (&$next, &$waited): void {
                $waited = true;
                $next->resolve('b');
            });
        });

        $result = P\Aggregate::settle($promises, true)->wait();

        $this->assertTrue($waited);
        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 'a'],
            'b' => ['state' => PromiseInterface::FULFILLED, 'value' => 'b'],
        ], $result);
    }

    public function testSettleRecursivelyIncludesDynamicallyAddedFulfilledPromise(): void
    {
        $promises = new \ArrayIterator();

        $promises['a'] = $promise = new Promise(function () use (&$promise, $promises): void {
            $promise->resolve('a');
            $promises['b'] = new FulfilledPromise('b');
        });

        $result = P\Aggregate::settle($promises, true)->wait();

        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 'a'],
            'b' => ['state' => PromiseInterface::FULFILLED, 'value' => 'b'],
        ], $result);
    }

    public function testSettleRecursivelyIncludesDynamicallyAddedRejectedPromise(): void
    {
        $promises = new \ArrayIterator();

        $promises['a'] = $promise = new Promise(function () use (&$promise, $promises): void {
            $promise->resolve('a');
            $promises['b'] = new RejectedPromise('bad');
        });

        $result = P\Aggregate::settle($promises, true)->wait();

        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 'a'],
            'b' => ['state' => PromiseInterface::REJECTED, 'reason' => 'bad'],
        ], $result);
    }

    public function testSettleRecursivelyHandlesRawValues(): void
    {
        $result = P\Aggregate::settle(new \ArrayIterator(['a' => 1]), true)->wait();

        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 1],
        ], $result);
    }

    public function testSettleRecursivelyHandlesGeneratorInput(): void
    {
        $gen = (static function (): \Generator {
            yield 'a' => new FulfilledPromise(1);
            yield 'b' => new RejectedPromise('bad');
        })();

        $result = P\Aggregate::settle($gen, true)->wait();

        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 1],
            'b' => ['state' => PromiseInterface::REJECTED, 'reason' => 'bad'],
        ], $result);
    }

    public function testSettleDoesNotRecurseByDefault(): void
    {
        $promises = new \ArrayIterator();

        $promises['a'] = $promise = new Promise(function () use (&$promise, $promises): void {
            $promise->resolve('a');
            $promises['b'] = new FulfilledPromise('b');
        });

        $result = P\Aggregate::settle($promises)->wait();

        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 'a'],
        ], $result);
    }

    public function testSettleRecursivelyHandlesRepeatedDynamicAdditions(): void
    {
        $promises = new \ArrayIterator();

        $promises['a'] = $first = new Promise(function () use (&$first, $promises): void {
            $first->resolve('a');

            $promises['b'] = $second = new Promise(function () use (&$second, $promises): void {
                $second->resolve('b');
                $promises['c'] = new FulfilledPromise('c');
            });
        });

        $result = P\Aggregate::settle($promises, true)->wait();

        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 'a'],
            'b' => ['state' => PromiseInterface::FULFILLED, 'value' => 'b'],
            'c' => ['state' => PromiseInterface::FULFILLED, 'value' => 'c'],
        ], $result);
    }

    public function testSettleLimitsConcurrencyForLazyIterable(): void
    {
        $created = 0;
        $promises = [];

        $iterable = static function () use (&$created, &$promises): \Generator {
            foreach (['a', 'b', 'c'] as $key) {
                ++$created;
                $promises[$key] = new Promise();

                yield $key => $promises[$key];
            }
        };

        $aggregate = P\Aggregate::settle($iterable(), false, ['concurrency' => 1]);

        $this->assertSame(1, $created);

        $promises['a']->resolve('A');
        P\Utils::queue()->run();

        $this->assertSame(2, $created);

        $promises['b']->reject('B');
        P\Utils::queue()->run();

        $this->assertSame(3, $created);

        $promises['c']->resolve('C');

        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 'A'],
            'b' => ['state' => PromiseInterface::REJECTED, 'reason' => 'B'],
            'c' => ['state' => PromiseInterface::FULFILLED, 'value' => 'C'],
        ], $aggregate->wait());
    }

    public function testSettleIgnoresCallbackConfigKeys(): void
    {
        $result = P\Aggregate::settle([new FulfilledPromise('a')], false, [
            'fulfilled' => static function (): void {
                throw new \RuntimeException('Should not be called.');
            },
            'rejected' => static function (): void {
                throw new \RuntimeException('Should not be called.');
            },
        ])->wait();

        $this->assertSame([
            ['state' => PromiseInterface::FULFILLED, 'value' => 'a'],
        ], $result);
    }

    public function testSettleRecursivelyHandlesConcurrencyConfig(): void
    {
        $promises = new \ArrayIterator();
        $counter = 0;
        $promises['a'] = new FulfilledPromise('a');
        $promises['b'] = $promise = new Promise(function () use (&$promise, $promises, &$counter): void {
            ++$counter;
            $promise->resolve('b');
            $promises['c'] = $subPromise = new Promise(function () use (&$subPromise): void {
                $subPromise->resolve('c');
            });
        });

        $result = P\Aggregate::settle($promises, true, ['concurrency' => 1])->wait();

        $this->assertSame(1, $counter);
        $this->assertSame([
            'a' => ['state' => PromiseInterface::FULFILLED, 'value' => 'a'],
            'b' => ['state' => PromiseInterface::FULFILLED, 'value' => 'b'],
            'c' => ['state' => PromiseInterface::FULFILLED, 'value' => 'c'],
        ], $result);
    }
}
