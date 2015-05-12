<?php
namespace GuzzleHttp\Promise;

/**
 * Exception thrown when too many errors occur in the some() or any() methods.
 */
class AggregateException extends \Exception
{
    private $reasons;

    public function __construct($msg, array $reasons)
    {
        parent::__construct($msg);
        $this->reasons = $reasons;
    }

    public function getReasons()
    {
        return $this->reasons;
    }
}
