<?php

namespace GuzzleHttp\Promise\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class TestCase extends PHPUnitTestCase
{
    public function setExpectedException($exception, $message = null, $code = null)
    {
        if (method_exists(get_parent_class(__CLASS__), 'setExpectedException')) {
            parent::setExpectedException($exception, $message, $code);
        } else {
            $this->expectException($exception);
            if ($message !== null) {
                $this->expectExceptionMessage($message);
            }
            if ($code !== null) {
                $this->expectExceptionCode($code);
            }
        }
    }

    public static function readAttribute($object, $attributeName)
    {
        if (method_exists(get_parent_class(__CLASS__), 'readAttribute')) {
            return parent::readAttribute($object, $attributeName);
        }

        $attribute = (new \ReflectionObject($object))->getProperty($attributeName);
        $attribute->setAccessible(true);

        return $attribute->getValue($object);
    }
}
