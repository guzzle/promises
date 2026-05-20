<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\Utils;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CoroutineTest extends TestCase
{
    public function testReturnsCoroutine(): void
    {
        $fn = function () { yield 'foo'; };
        $this->assertInstanceOf(Coroutine::class, Coroutine::of($fn));
    }

    /**
     * @dataProvider promiseInterfaceMethodProvider
     */
    public function testShouldProxyPromiseMethodsToResultPromise(string $method, array $args = []): void
    {
        $coroutine = new Coroutine(function () { yield 0; });
        $mockPromise = $this->createMock(PromiseInterface::class);
        $mockPromise->expects($this->once())->method($method)->with(...$args);

        $resultPromiseProp = (new ReflectionClass(Coroutine::class))->getProperty('result');

        if (PHP_VERSION_ID < 80100) {
            $resultPromiseProp->setAccessible(true);
        }

        $resultPromiseProp->setValue($coroutine, $mockPromise);

        $coroutine->{$method}(...$args);
    }

    public static function promiseInterfaceMethodProvider(): array
    {
        return [
            ['then', [null, null]],
            ['otherwise', [function (): void {}]],
            ['wait', [true]],
            ['getState', []],
            ['resolve', [null]],
            ['reject', [null]],
        ];
    }

    public function testShouldProxyResolveWithoutValueToResultPromiseAsNull(): void
    {
        $coroutine = new Coroutine(function () { yield 0; });
        $mockPromise = $this->createMock(PromiseInterface::class);
        $mockPromise->expects($this->once())->method('resolve')->with(null);

        $resultPromiseProp = (new ReflectionClass(Coroutine::class))->getProperty('result');

        if (PHP_VERSION_ID < 80100) {
            $resultPromiseProp->setAccessible(true);
        }

        $resultPromiseProp->setValue($coroutine, $mockPromise);

        $coroutine->resolve();
    }

    public function testShouldCancelResultPromiseAndOutsideCurrentPromise(): void
    {
        $coroutine = new Coroutine(function () { yield 0; });

        $mockPromises = [
            'result' => $this->createMock(PromiseInterface::class),
            'currentPromise' => $this->createMock(PromiseInterface::class),
        ];
        foreach ($mockPromises as $propName => $mockPromise) {
            $mockPromise->expects($this->once())
                ->method('cancel')
                ->with();

            $promiseProp = (new ReflectionClass(Coroutine::class))->getProperty($propName);

            if (PHP_VERSION_ID < 80100) {
                $promiseProp->setAccessible(true);
            }

            $promiseProp->setValue($coroutine, $mockPromise);
        }

        $coroutine->cancel();
    }

    public function testCurrentPromiseIsResetToNullAfterFulfilledCoroutine(): void
    {
        $coroutine = new Coroutine(function () {
            yield new FulfilledPromise('ok');
        });

        Utils::queue()->run();

        $this->assertNull(PropertyHelper::get($coroutine, 'currentPromise'));

        $coroutine->cancel();

        $this->assertSame(PromiseInterface::FULFILLED, $coroutine->getState());
    }

    public function testCurrentPromiseIsResetToNullAfterRejectedCoroutine(): void
    {
        $coroutine = new Coroutine(function () {
            yield new RejectedPromise('no');
        });

        Utils::queue()->run();

        $this->assertNull(PropertyHelper::get($coroutine, 'currentPromise'));

        $coroutine->cancel();

        $this->assertSame(PromiseInterface::REJECTED, $coroutine->getState());
    }

    public function testWaitShouldResolveChainedCoroutines(): void
    {
        $promisor = function () {
            return Coroutine::of(function () {
                yield $promise = new Promise(function () use (&$promise): void {
                    $promise->resolve(1);
                });
            });
        };

        $promise = $promisor()->then($promisor)->then($promisor);

        $this->assertSame(1, $promise->wait());
    }

    public function testWaitShouldHandleIntermediateErrors(): void
    {
        $promise = Coroutine::of(function () {
            yield $promise = new Promise(function () use (&$promise): void {
                $promise->resolve(1);
            });
        })
        ->then(function () {
            return Coroutine::of(function () {
                yield $promise = new Promise(function () use (&$promise): void {
                    $promise->reject(new \Exception());
                });
            });
        })
        ->otherwise(function (?\Exception $error = null) {
            if (!$error) {
                self::fail('Error did not propagate.');
            }

            return 3;
        });

        $this->assertSame(3, $promise->wait());
    }
}
