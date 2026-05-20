<?php

declare(strict_types=1);

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
     * @return mixed
     *
     * @throws \ReflectionException
     */
    public static function get(object $object, string $property)
    {
        $property = (new \ReflectionObject($object))->getProperty($property);

        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property->getValue($object);
    }
}
