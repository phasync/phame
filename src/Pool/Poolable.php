<?php

namespace Phame\Pool;

/**
 * Object pooling for small mutable value types (vectors, matrices, rectangles) that are used every frame.
 * The class makes its constructor private and hands out instances with `get()`-style factories built on
 * `acquire()`; `release()` puts an instance back for reuse. Recycled instances hold stale values, so a factory
 * must initialise every field. Do not touch an object after releasing it, and never release it twice.
 */
trait Poolable
{
    private const POOL_LIMIT = 4096;

    /** @var array<int, static> */
    private static array $pool = [];
    private static int $pooled = 0;
    private bool $inPool = false;

    private static function acquire(): static
    {
        if (self::$pooled === 0) {
            return new static();
        }
        $object = self::$pool[--self::$pooled];
        unset(self::$pool[self::$pooled]);
        $object->inPool = false;
        return $object;
    }

    public function release(): void
    {
        if ($this->inPool) {
            throw new \LogicException(static::class . ' released twice');
        }
        if (self::$pooled < self::POOL_LIMIT) {
            $this->inPool = true;
            self::$pool[self::$pooled++] = $this;
        }
    }

    /** How many idle instances wait in the pool. */
    public static function pooledCount(): int
    {
        return self::$pooled;
    }
}
