<?php

declare(strict_types=1);

namespace GuzzleHttp\Promise\Tests;

class Thing2 implements \JsonSerializable
{
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return '{}';
    }
}
