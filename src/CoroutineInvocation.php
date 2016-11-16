<?php
namespace GuzzleHttp\Promise;

use Exception;
use Generator;
use Throwable;

/**
 * @internal
 */
final class CoroutineInvocation implements PromiseInterface
{
    /**
     * @var PromiseInterface|null
     */
    private $currentPromise;

    /**
     * @var Generator
     */
    private $generator;

    /**
     * @var Promise
     */
    private $result;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
        $this->result = new Promise(function () {
            while (isset($this->currentPromise)) {
                $this->currentPromise->wait();
            }
        });
        $this->nextCoroutine($this->generator->current());
    }

    public function then(
        callable $onFulfilled = null,
        callable $onRejected = null
    ) {
        return $this->result->then($onFulfilled, $onRejected);
    }

    public function otherwise(callable $onRejected)
    {
        return $this->result->otherwise($onRejected);
    }

    public function wait($unwrap = true)
    {
        return $this->result->wait($unwrap);
    }

    public function getState()
    {
        return $this->result->getState();
    }

    public function resolve($value)
    {
        $this->result->resolve($value);
    }

    public function reject($reason)
    {
        $this->result->reject($reason);
    }

    public function cancel()
    {
        $this->currentPromise->cancel();
        $this->result->cancel();
    }

    private function promiseFor($yielded)
    {
        if (is_object($yielded) && ($yielded instanceof Generator)) {
            // Threat the value like an already running coroutine.
            $yielded = coroutine_invocation($yielded);
        } elseif (is_array($yielded)) {
            $promises = [];
            foreach ($yielded as $key => $item) {
                $promises[$key] = $this->promiseFor($item);
            }

            $yielded = all($promises);
        }

        return promise_for($yielded);
    }

    private function nextCoroutine($yielded)
    {
        $this->currentPromise = $this->promiseFor($yielded)
            ->then([$this, '_handleSuccess'], [$this, '_handleFailure']);
    }

    /**
     * @internal
     */
    public function _handleSuccess($value)
    {
        unset($this->currentPromise);
        try {
            $next = $this->generator->send($value);

            if (!$this->generator->valid() || $next instanceof CoroutineStop) {
                $this->result->resolve($value);
            } else {
                $this->nextCoroutine($next);
            }
        } catch (Exception $exception) {
            $this->result->reject($exception);
        } catch (Throwable $throwable) {
            $this->result->reject($throwable);
        }
    }

    /**
     * @internal
     */
    public function _handleFailure($reason)
    {
        unset($this->currentPromise);
        try {
            $nextYield = $this->generator->throw(exception_for($reason));
            // The throw was caught, so keep iterating on the coroutine
            $this->nextCoroutine($nextYield);
        } catch (Exception $exception) {
            $this->result->reject($exception);
        } catch (Throwable $throwable) {
            $this->result->reject($throwable);
        }
    }
}
