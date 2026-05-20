<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

class EachTest extends TestCase
{
    public function testCallsEachLimit(): void
    {
        $p = new Promise();
        $aggregate = P\Each::ofLimit([$p], 2);

        $p->resolve('a');
        P\Utils::queue()->run();
        $this->assertTrue(P\Is::fulfilled($aggregate));
    }

    public function testEachOfLimitsConcurrencyWithConfig(): void
    {
        $created = 0;
        $promises = [];
        $results = [];

        $iterable = static function () use (&$created, &$promises): \Generator {
            foreach (['a', 'b', 'c'] as $key) {
                ++$created;
                $promises[$key] = new Promise();

                yield $key => $promises[$key];
            }
        };

        $aggregate = P\Each::of(
            $iterable(),
            function (string $value, string $key) use (&$results): void {
                $results[$key] = $value;
            },
            null,
            ['concurrency' => 1]
        );

        $this->assertSame(1, $created);

        $promises['a']->resolve('A');
        P\Utils::queue()->run();

        $this->assertSame(2, $created);

        $promises['b']->resolve('B');
        P\Utils::queue()->run();

        $this->assertSame(3, $created);

        $promises['c']->resolve('C');

        $this->assertNull($aggregate->wait());
        $this->assertSame(['a' => 'A', 'b' => 'B', 'c' => 'C'], $results);
    }

    public function testEachOfIgnoresCallbackConfigKeys(): void
    {
        $results = [];

        P\Each::of(
            [new FulfilledPromise('a')],
            function (string $value) use (&$results): void {
                $results[] = $value;
            },
            null,
            [
                'fulfilled' => static function (): void {
                    throw new \RuntimeException('Should not be called.');
                },
                'rejected' => static function (): void {
                    throw new \RuntimeException('Should not be called.');
                },
            ]
        )->wait();

        $this->assertSame(['a'], $results);
    }

    public function testEachLimitAllRejectsOnFailure(): void
    {
        $p = [new FulfilledPromise('a'), new RejectedPromise('b')];
        $aggregate = P\Each::ofLimitAll($p, 2);

        P\Utils::queue()->run();
        $this->assertTrue(P\Is::rejected($aggregate));

        $result = P\Utils::inspect($aggregate);
        $this->assertSame('b', $result['reason']);
    }
}
