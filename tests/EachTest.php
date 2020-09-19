<?php

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise as P;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;

class EachTest extends TestCase
{
    public function testCallsEachLimit()
    {
        $p = new Promise();
        $aggregate = P\Each::ofLimit($p, 2);

        $p->resolve('a');
        P\Utils::queue()->run();
        $this->assertTrue(P\Is::fulfilled($aggregate));
    }

    public function testEachLimitAllRejectsOnFailure()
    {
        $p = [new FulfilledPromise('a'), new RejectedPromise('b')];
        $aggregate = P\Each::ofLimitAll($p, 2);

        P\Utils::queue()->run();
        $this->assertTrue(P\Is::rejected($aggregate));

        $result = P\Utils::inspect($aggregate);
        $this->assertSame('b', $result['reason']);
    }
}
