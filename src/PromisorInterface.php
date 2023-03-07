<?php

namespace GuzzleHttp\Promise;

/**
 * Interface used with classes that return a promise.
 *
 * @template ValueType
 * @template ReasonType
 */
interface PromisorInterface
{
    /**
     * Returns a promise.
     *
     * @return PromiseInterface<ValueType, ReasonType>
     */
    public function promise();
}
