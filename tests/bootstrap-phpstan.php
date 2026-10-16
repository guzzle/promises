<?php

declare(strict_types=1);

namespace {
    if (!\class_exists('WeakMap')) {
        /**
         * @template TKey of object
         * @template TValue
         *
         * @implements ArrayAccess<TKey, TValue>
         */
        final class WeakMap implements ArrayAccess
        {
            /**
             * @param TKey $offset
             */
            public function offsetExists($offset): bool
            {
                return false;
            }

            /**
             * @param TKey $offset
             *
             * @return TValue
             */
            public function offsetGet($offset)
            {
                throw new LogicException('WeakMap requires PHP 8.0 or newer.');
            }

            /**
             * @param TKey   $offset
             * @param TValue $value
             */
            public function offsetSet($offset, $value): void
            {
            }

            /**
             * @param TKey $offset
             */
            public function offsetUnset($offset): void
            {
            }
        }
    }

    if (!\class_exists('Fiber')) {
        final class Fiber
        {
            public static function getCurrent(): ?self
            {
                return null;
            }
        }
    }
}
