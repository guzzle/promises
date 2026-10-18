<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase
{
    public function testKnowsIfFulfilled(): void
    {
        $p = new FulfilledPromise(null);
        $this->assertTrue(P\State::isFulfilled($p));
        $this->assertFalse(P\State::isRejected($p));
    }

    public function testKnowsIfRejected(): void
    {
        $p = new RejectedPromise(null);
        $this->assertTrue(P\State::isRejected($p));
        $this->assertFalse(P\State::isFulfilled($p));
    }

    public function testKnowsIfSettled(): void
    {
        $p = new RejectedPromise(null);
        $this->assertTrue(P\State::isSettled($p));
        $this->assertFalse(P\State::isPending($p));
    }

    public function testKnowsIfPending(): void
    {
        $p = new Promise();
        $this->assertFalse(P\State::isSettled($p));
        $this->assertTrue(P\State::isPending($p));
    }
}
