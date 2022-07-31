<?php

namespace GuzzleHttp\Promise\Tests;

class Thing1
{
    private $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function __toString()
    {
        return $this->message;
    }
}
