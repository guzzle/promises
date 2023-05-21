<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\TaskQueue;
use PHPUnit\Framework\TestCase;

class TaskQueueTest extends TestCase
{
    public function testKnowsIfEmpty(): void
    {
        $tq = new TaskQueue(false);
        $this->assertTrue($tq->isEmpty());
    }

    public function testKnowsIfFull(): void
    {
        $tq = new TaskQueue(false);
        $tq->add(function (): void {});
        $this->assertFalse($tq->isEmpty());
    }

    public function testExecutesTasksInOrder(): void
    {
        $tq = new TaskQueue(false);
        $called = [];
        $tq->add(function () use (&$called): void { $called[] = 'a'; });
        $tq->add(function () use (&$called): void { $called[] = 'b'; });
        $tq->add(function () use (&$called): void { $called[] = 'c'; });
        $tq->run();
        $this->assertSame(['a', 'b', 'c'], $called);
    }
}
