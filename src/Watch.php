<?php

namespace Phame;

use Phame\Pool\Poolable;

/** @internal A condition of Async::until(): checked by the engine every frame; the object is also the flag it raises. */
final class Watch
{
    use Poolable;

    public ?\Closure $condition = null;
    public mixed $value = null;
    public bool $done = false;
    /** Position in the engine's list, for removal in O(1). */
    public int $index = -1;

    private function __construct()
    {
    }

    public static function get(\Closure $condition): self
    {
        $w = self::acquire();
        $w->condition = $condition;
        $w->value = null;
        $w->done = false;
        return $w;
    }

    public function free(): void
    {
        $this->condition = null;
        $this->value = null;
        $this->release();
    }
}
