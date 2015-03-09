<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Promise;

class CoroutineTest extends \PHPUnit_Framework_TestCase
{
    public function testYieldsFromCoroutine()
    {
        $promise = Promise\coroutine(function () {
            $value = (yield new Promise\FulfilledPromise('a'));
            yield  $value . 'b';
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        $this->assertEquals('ab', $result);
    }

    public function testCanCatchExceptionsInCoroutine()
    {
        $promise = Promise\coroutine(function () {
            try {
                $value = (yield new Promise\RejectedPromise('a'));
                $this->fail('Should have thrown into the coroutine!');
            } catch (Promise\RejectionException $e) {
                $value = (yield new Promise\FulfilledPromise($e->getReason()));
            }
            yield  $value . 'b';
        });
        $promise->then(function ($value) use (&$result) { $result = $value; });
        $this->assertEquals('ab', $result);
    }

    public function testRejectsParentExceptionWhenException()
    {
        $promise = Promise\coroutine(function () {
            yield new Promise\FulfilledPromise(0);
            throw new \Exception('a');
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        $this->assertInstanceOf('Exception', $result);
        $this->assertEquals('a', $result->getMessage());
    }

    public function testCanRejectFromRejectionCallback()
    {
        $promise = Promise\coroutine(function () {
            yield new Promise\FulfilledPromise(0);
            yield new Promise\RejectedPromise('no!');
        });
        $promise->then(
            function () { $this->fail(); },
            function ($reason) use (&$result) { $result = $reason; }
        );
        $this->assertInstanceOf('GuzzleHttp\Promise\RejectionException', $result);
        $this->assertEquals('no!', $result->getReason());
    }
}
