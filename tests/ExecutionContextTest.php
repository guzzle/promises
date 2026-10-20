<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\ContextualTaskQueue;
use GuzzleHttp\Promise\ExecutionContext;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\TaskQueue;
use PHPUnit\Framework\TestCase;

class ExecutionContextTest extends TestCase
{
    private TestExecutionContextProvider $provider;

    protected function setUp(): void
    {
        if (PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('Execution context providers require PHP 8.0 or newer.');
        }

        $this->provider = new TestExecutionContextProvider();
        ExecutionContext::setProvider($this->provider);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setProvider(null);
        P\Utils::queue()->run();
    }

    public function testWithoutProviderTheGlobalQueueIsReturned(): void
    {
        ExecutionContext::setProvider(null);

        $this->assertInstanceOf(TaskQueue::class, P\Utils::queue());
        $this->assertSame(P\Utils::queue(), P\Utils::queue());
        $this->assertNull(ExecutionContext::current());
    }

    public function testTasksStayInTheContextThatQueuedThem(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $ranIn = [];

        $this->provider->in($a, function () use (&$ranIn): void {
            P\Utils::queue()->add(function () use (&$ranIn): void {
                $ranIn[] = $this->provider->current;
            });
        });

        $this->provider->in($b, function (): void {
            $this->assertTrue(P\Utils::queue()->isEmpty());
            P\Utils::queue()->run();
        });
        $this->assertSame([], $ranIn);

        $this->provider->in($a, function (): void {
            $this->assertFalse(P\Utils::queue()->isEmpty());
            P\Utils::queue()->run();
        });
        $this->assertSame([$a], $ranIn);
    }

    public function testWorkOutsideManagedContextsUsesTheGlobalQueue(): void
    {
        $global = new TaskQueue(false);
        P\Utils::queue($global);
        $ran = false;

        P\Utils::queue()->add(function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($global->isEmpty());
        $this->provider->in(new \stdClass(), function (): void {
            P\Utils::queue()->run();
        });
        $this->assertFalse($ran);

        P\Utils::queue()->run();
        $this->assertTrue($ran);
    }

    public function testCallbacksOfSettledPromisesRunInTheSettlingContext(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $ranIn = [];

        $this->provider->in($a, function () use (&$ranIn): void {
            foreach ([1, 2] as $i) {
                $promise = new Promise();
                $promise->then(function () use ($i, &$ranIn): void {
                    $ranIn[$i] = $this->provider->current;
                });
                $promise->resolve(true);
            }
        });

        $this->provider->in($b, function (): void {
            P\Utils::queue()->run();
        });
        $this->provider->in($a, function (): void {
            P\Utils::queue()->run();
        });

        $this->assertSame([1 => $a, 2 => $a], $ranIn);
    }

    public function testFinishRunsFinalizersBeforeDrainingTheQueue(): void
    {
        $context = new \stdClass();
        $events = [];

        $this->provider->in($context, function () use (&$events): void {
            $promise = new Promise();
            $promise->then(null, function () use (&$events): void {
                $events[] = 'rejection callback in '.($this->provider->current === null ? 'none' : 'context');
            });
            ExecutionContext::onFinish(static function () use ($promise, &$events): void {
                $events[] = 'finalizer';
                $promise->cancel();
            });
        });

        $this->provider->end($context);

        $this->assertSame(['finalizer', 'rejection callback in context'], $events);
    }

    public function testFinalizingProvidersAreRegisteredOncePerContext(): void
    {
        $context = new \stdClass();

        $this->provider->in($context, function (): void {
            P\Utils::queue()->add(static function (): void {});
            P\Utils::queue()->add(static function (): void {});
            ExecutionContext::onFinish(static function (): void {});
        });

        $this->assertCount(1, $this->provider->finalizers[spl_object_id($context)]);
    }

    public function testFinishDrainsWorkQueuedWhileFinishing(): void
    {
        $context = new \stdClass();
        $ran = [];

        $this->provider->in($context, static function () use (&$ran): void {
            P\Utils::queue()->add(static function () use (&$ran): void {
                $ran[] = 'first';
                P\Utils::queue()->add(static function () use (&$ran): void {
                    $ran[] = 'second';
                });
            });
        });

        $this->provider->end($context);

        $this->assertSame(['first', 'second'], $ran);
    }

    public function testFinishRethrowsTheFirstFailureAfterDraining(): void
    {
        $context = new \stdClass();
        $ran = false;

        $this->provider->in($context, static function () use (&$ran): void {
            ExecutionContext::onFinish(static function (): void {
                throw new \RuntimeException('finalizer failed');
            });
            P\Utils::queue()->add(static function () use (&$ran): void {
                $ran = true;
            });
        });

        try {
            $this->provider->in($context, static function () use ($context): void {
                ExecutionContext::finish($context);
            });
            $this->fail('The finalizer failure was not rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('finalizer failed', $e->getMessage());
        }

        $this->assertTrue($ran);
    }

    public function testFinishIsIdempotent(): void
    {
        $context = new \stdClass();
        $calls = 0;

        $this->provider->in($context, static function () use (&$calls): void {
            ExecutionContext::onFinish(static function () use (&$calls): void {
                ++$calls;
            });
        });

        $this->provider->in($context, static function () use ($context): void {
            ExecutionContext::finish($context);
            ExecutionContext::finish($context);
        });

        $this->assertSame(1, $calls);
    }

    public function testFinishingAContextFromItsOwnFinalizerDoesNothing(): void
    {
        $context = new \stdClass();
        $events = [];

        $this->provider->in($context, static function () use ($context, &$events): void {
            ExecutionContext::onFinish(static function () use ($context, &$events): void {
                ExecutionContext::finish($context);
                $events[] = 'first';
            });
            ExecutionContext::onFinish(static function () use (&$events): void {
                $events[] = 'second';
            });
        });

        $this->provider->in($context, static function () use ($context): void {
            ExecutionContext::finish($context);
        });

        $this->assertSame(['first', 'second'], $events);
    }

    public function testFinishedContextsThatUsePromisesAgainAreFinishedAgain(): void
    {
        $context = new \stdClass();
        $ran = false;
        $this->provider->in($context, static function (): void {
            P\Utils::queue()->add(static function (): void {});
        });
        $this->provider->end($context);

        $this->provider->in($context, static function () use (&$ran): void {
            P\Utils::queue()->add(static function () use (&$ran): void {
                $ran = true;
            });
        });

        $this->assertCount(1, $this->provider->finalizers[spl_object_id($context)]);
        $this->assertFalse($ran);

        $this->provider->end($context);

        $this->assertTrue($ran);
    }

    public function testFinalizersRequireAManagedContext(): void
    {
        $this->expectException(\LogicException::class);

        ExecutionContext::onFinish(static function (): void {});
    }

    public function testContextStateDoesNotKeepTheContextAlive(): void
    {
        $context = new \stdClass();
        $reference = \WeakReference::create($context);

        $this->provider->in($context, static function (): void {
            P\Utils::queue()->add(static function (): void {});
            new Promise();
        });
        $this->provider->finalizers = [];
        unset($context);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    public function testDisableShutdownReachesTheGlobalQueue(): void
    {
        $global = new TaskQueue();
        P\Utils::queue($global);
        $queue = P\Utils::queue();
        $this->assertInstanceOf(ContextualTaskQueue::class, $queue);

        $queue->disableShutdown();

        $this->assertFalse(PropertyHelper::get($global, 'enableShutdown'));
    }
}
