<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\RejectionException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Promise\RejectionException
 */
class RejectionExceptionTest extends TestCase
{
    public function testCanGetReasonFromException(): void
    {
        $thing = new Thing1('foo');
        $e = new RejectionException($thing);

        $this->assertSame($thing, $e->getReason());
        $this->assertSame('The promise was rejected with reason: foo', $e->getMessage());
    }

    public function testCanGetReasonMessageFromJson(): void
    {
        $reason = new Thing2();
        $e = new RejectionException($reason);
        $this->assertStringContainsString('{}', $e->getMessage());
    }
}
