<?php

namespace GuzzleHttp\Promise\Tests;

class Thing2 implements \JsonSerializable
{
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return '{}';
    }
}
