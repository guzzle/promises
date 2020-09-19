<?php

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\AggregateException;

class AggregateExceptionTest extends TestCase
{
    public function testHasReason()
    {
        $e = new AggregateException('foo', ['baz', 'bar']);
        $this->assertTrue(strpos($e->getMessage(), 'foo') !== false, "'" . $e->getMessage() . " does not contain 'foo'");
        $this->assertSame(['baz', 'bar'], $e->getReason());
    }
}
