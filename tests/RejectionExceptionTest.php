<?php
namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\RejectionException;

class Thing
{
    public function __construct($message)
    {
        $this->message = $message;
    }

    public function __toString()
    {
        return $this->message;
    }
}

/**
 * @covers GuzzleHttp\Promise\RejectionException
 */
class RejectionExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testCanGetReasonFromException()
    {
        $thing = new Thing('foo');
        $e = new RejectionException($thing);

        $this->assertSame($thing, $e->getReason());
        $this->assertEquals('The promise was rejected with reason: foo', $e->getMessage());
    }

    public function testCanGetReasonMessageFromArrayOrJson()
    {
        $reason = ['foo' => 'bar'];
        $e = new RejectionException($reason);
        $this->assertContains("{\n    \"foo\": \"bar\"\n}", $e->getMessage());
    }
}
