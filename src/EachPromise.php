<?php
namespace GuzzleHttp\Promise;

/**
 * Represents a promise that iterates over many promises and invokes
 * side-effect functions in the process.
 */
class EachPromise implements PromisorInterface
{
    private $iterable;
    private $limit;
    private $onFulfilled;
    private $onRejected;
    private $pending = [];
    private $aggregate;

    /**
     * Configuration hash can include the following key value pairs:
     *
     * - fulfilled: (callable) Invoked when a promise fulfills. The function
     *   is invoked with three arguments: the fulfillment value, the index
     *   position from the iterable list of the promise, and the aggregate
     *   promise that manages all of the promises. The aggregate promise may
     *   be resolved from within the callback to short-circuit the promise.
     * - rejected: (callable) Invoked when a promise is rejected. The
     *   function is invoked with three arguments: the rejection reason, the
     *   index position from the iterable list of the promise, and the
     *   aggregate promise that manages all of the promises. The aggregate
     *   promise may be resolved from within the callback to short-circuit
     *   the promise.
     * - limit: (integer) Pass this configuration option to limit the allowed
     *   number of outstanding promises, creating a capped pool of promises.
     *   There is no limit by default.
     *
     * @param mixed    $iterable Promises or values to iterate.
     * @param array    $config   Configuration options
     */
    public function __construct($iterable, array $config)
    {
        $this->iterable = iter_for($iterable);
        $this->limit = isset($config['limit']) ? $config['limit'] : null;
        $this->onFulfilled = isset($config['onFulfilled']) ? $config['onFulfilled'] : null;
        $this->onRejected = isset($config['onRejected']) ? $config['onRejected'] : null;
    }

    public function promise()
    {
        if ($this->aggregate) {
            return $this->aggregate;
        }

        $this->createPromise();
        $this->iterable->rewind();
        $this->refillPending();

        return $this->aggregate;
    }

    private function createPromise()
    {
        $this->aggregate = new Promise(function () {
            $this->refillPending();
            reset($this->pending);
            // Consume a potentially fluctuating list of promises while
            // ensuring that indexes are maintained (precluding array_shift).
            while ($promise = current($this->pending)) {
                next($this->pending);
                $promise->wait();
                if ($this->aggregate->getState() !== PromiseInterface::PENDING) {
                    return;
                }
            }
        });

        // Clear the references when the promise is resolved.
        $clearFn = function () {
            $this->iterable = $this->limit = $this->pending = null;
            $this->onFulfilled = $this->onRejected = null;
        };

        $this->aggregate->then($clearFn, $clearFn);
    }

    private function refillPending()
    {
        if (!$this->limit) {
            // Add all pending promises.
            while ($this->addPending());
        } else {
            // Add only up to N pending promises.
            $limit = is_callable($this->limit)
                ? call_user_func($this->limit, count($this->pending))
                : $this->limit;
            $limit = max($limit - count($this->pending), 0);
            while ($limit-- && $this->addPending());
        }
    }

    private function addPending()
    {
        add_next:
        if (!$this->iterable || !$this->iterable->valid()) {
            return false;
        }

        $promise = promise_for($this->iterable->current());
        $idx = $this->iterable->key();
        $this->iterable->next();

        switch ($promise->getState()) {
            case PromiseInterface::PENDING:
                $this->pending[$idx] = $promise->then(
                    function ($value) use ($idx) {
                        $this->doFulfilled($value, $idx);
                        $this->step($idx);
                    },
                    function ($reason) use ($idx) {
                        $this->doRejected($reason, $idx);
                        $this->step($idx);
                    }
                );
                break;
            case PromiseInterface::FULFILLED:
                // Prevent recursion
                $this->doFulfilled($promise->wait(), $idx);
                if (!$this->checkIfFinished()) {
                    goto add_next;
                }
                return false;
            case PromiseInterface::REJECTED:
                // Prevent recursion
                $this->doRejected(inspect($promise)['reason'], $idx);
                if (!$this->checkIfFinished()) {
                    goto add_next;
                }
                return false;
        }

        return true;
    }

    private function doFulfilled($value, $idx)
    {
        if ($this->onFulfilled) {
            call_user_func($this->onFulfilled, $value, $idx, $this->aggregate);
        }
    }

    private function doRejected($reason, $idx)
    {
        if ($this->onRejected) {
            call_user_func($this->onRejected, $reason, $idx, $this->aggregate);
        }
    }

    private function checkIfFinished()
    {
        if (!$this->pending && !$this->iterable->valid()) {
            // Resolve the promise if there's nothing left to do.
            $this->aggregate->resolve(null);
            return true;
        }

        return false;
    }

    private function step($idx)
    {
        // If the promise was already resolved, then ignore this step.
        if ($this->aggregate->getState() !== PromiseInterface::PENDING) {
            return;
        }

        unset($this->pending[$idx]);

        if (!$this->checkIfFinished()) {
            // Add more pending promises if possible.
            $this->refillPending();
        }
    }
}
