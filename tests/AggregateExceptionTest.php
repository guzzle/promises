<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\AggregateException;
use PHPUnit\Framework\TestCase;

class AggregateExceptionTest extends TestCase
{
    public function testHasReason(): void
    {
        $e = new AggregateException('foo', ['baz', 'bar']);
        $this->assertStringContainsString('foo', $e->getMessage());
        $this->assertSame(['baz', 'bar'], $e->getReason());
    }
}
