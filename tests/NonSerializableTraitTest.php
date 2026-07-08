<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\TaskQueue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Promise\NonSerializableTrait
 */
class NonSerializableTraitTest extends TestCase
{
    /**
     * @dataProvider runtimeObjectProvider
     */
    public function testRuntimeObjectsCannotBeSerialized(object $object, string $class): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($class.' should never be serialized');

        serialize($object);
    }

    /**
     * @dataProvider runtimeClassProvider
     */
    public function testRuntimeObjectsCannotBeUnserialized(string $class): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($class.' should never be unserialized');

        unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class));
    }

    public static function runtimeObjectProvider(): array
    {
        return [
            'promise' => [new Promise(), Promise::class],
            'task-queue' => [new TaskQueue(false), TaskQueue::class],
            'coroutine' => [new Coroutine(static function (): \Generator {
                yield new Promise();
            }), Coroutine::class],
            'each-promise' => [new EachPromise([]), EachPromise::class],
        ];
    }

    public static function runtimeClassProvider(): array
    {
        return [
            'promise' => [Promise::class],
            'task-queue' => [TaskQueue::class],
            'coroutine' => [Coroutine::class],
            'each-promise' => [EachPromise::class],
        ];
    }
}
