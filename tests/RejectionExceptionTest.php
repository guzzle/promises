<?php

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\RejectionException;

/**
 * @covers GuzzleHttp\Promise\RejectionException
 */
class RejectionExceptionTest extends TestCase
{
    public function testCanGetReasonFromException()
    {
        $thing = new Thing1('foo');
        $e = new RejectionException($thing);

        $this->assertSame($thing, $e->getReason());
        $this->assertSame('The promise was rejected with reason: foo', $e->getMessage());
    }

    public function testCanGetReasonMessageFromJson()
    {
        $reason = new Thing2();
        $e = new RejectionException($reason);
        $this->assertTrue(strpos($e->getMessage(), '{}') !== false, "'" . $e->getMessage() . " does not contain '{}'");
    }
}
