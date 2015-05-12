<?php
namespace GuzzleHttp\Tests\RejectedPromise;

use GuzzleHttp\Promise\AggregateException;

class AggregateExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasReasons()
    {
        $e = new AggregateException('foo', ['baz', 'bar']);
        $this->assertContains('foo', $e->getMessage());
        $this->assertEquals(['baz', 'bar'], $e->getReasons());
    }
}
