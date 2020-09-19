<?php

namespace GuzzleHttp\Promise\Tests;

/**
 * A class to help get properties of an object.
 *
 * @internal
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class PropertyHelper
{
    /**
     * @param object $object
     * @param string $property
     *
     * @throws \ReflectionException
     */
    public static function get($object, $property)
    {
        $property = (new \ReflectionObject($object))->getProperty($property);
        $property->setAccessible(true);

        return $property->getValue($object);
    }
}
