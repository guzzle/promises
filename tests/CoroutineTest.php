<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
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
     *
     * @param string $method
     * @param array  $args
     */
    public function testShouldProxyPromiseMethodsToResultPromise($method, $args = []): void
    {
        $coroutine = new Coroutine(function () { yield 0; });
        $mockPromise = $this->getMockForAbstractClass(PromiseInterface::class);
        $mockPromise->expects($this->once())->method($method)->with(...$args);

        $resultPromiseProp = (new ReflectionClass(Coroutine::class))->getProperty('result');
        $resultPromiseProp->setAccessible(true);
        $resultPromiseProp->setValue($coroutine, $mockPromise);

        $coroutine->{$method}(...$args);
    }

    public function promiseInterfaceMethodProvider()
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

    public function testShouldCancelResultPromiseAndOutsideCurrentPromise(): void
    {
        $coroutine = new Coroutine(function () { yield 0; });

        $mockPromises = [
            'result' => $this->getMockForAbstractClass(PromiseInterface::class),
            'currentPromise' => $this->getMockForAbstractClass(PromiseInterface::class),
        ];
        foreach ($mockPromises as $propName => $mockPromise) {
            /**
             * @var $mockPromise \PHPUnit_Framework_MockObject_MockObject
             */
            $mockPromise->expects($this->once())
                ->method('cancel')
                ->with();

            $promiseProp = (new ReflectionClass(Coroutine::class))->getProperty($propName);
            $promiseProp->setAccessible(true);
            $promiseProp->setValue($coroutine, $mockPromise);
        }

        $coroutine->cancel();
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
