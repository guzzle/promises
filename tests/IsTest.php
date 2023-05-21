<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

class IsTest extends TestCase
{
    public function testKnowsIfFulfilled(): void
    {
        $p = new FulfilledPromise(null);
        $this->assertTrue(P\Is::fulfilled($p));
        $this->assertFalse(P\Is::rejected($p));
    }

    public function testKnowsIfRejected(): void
    {
        $p = new RejectedPromise(null);
        $this->assertTrue(P\Is::rejected($p));
        $this->assertFalse(P\Is::fulfilled($p));
    }

    public function testKnowsIfSettled(): void
    {
        $p = new RejectedPromise(null);
        $this->assertTrue(P\Is::settled($p));
        $this->assertFalse(P\Is::pending($p));
    }

    public function testKnowsIfPending(): void
    {
        $p = new Promise();
        $this->assertFalse(P\Is::settled($p));
        $this->assertTrue(P\Is::pending($p));
    }
}
