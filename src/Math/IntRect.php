<?php

namespace Phame\Math;

use Phame\Pool\Poolable;

/** A pooled rectangle of whole pixels, used for GL viewports and scissor boxes (origin bottom left). */
final class IntRect
{
    use Poolable;

    public int $x = 0;
    public int $y = 0;
    public int $width = 0;
    public int $height = 0;

    private function __construct()
    {
    }

    public static function get(int $x = 0, int $y = 0, int $width = 0, int $height = 0): self
    {
        $r = self::acquire();
        $r->x = $x;
        $r->y = $y;
        $r->width = $width;
        $r->height = $height;
        return $r;
    }

    public function copyFrom(self $o): self
    {
        $this->x = $o->x;
        $this->y = $o->y;
        $this->width = $o->width;
        $this->height = $o->height;
        return $this;
    }

    /** Shrinks this to the part it shares with $o; the size becomes 0 if they do not overlap. */
    public function intersect(self $o): self
    {
        $x = max($this->x, $o->x);
        $y = max($this->y, $o->y);
        $this->width = max(0, min($this->x + $this->width, $o->x + $o->width) - $x);
        $this->height = max(0, min($this->y + $this->height, $o->y + $o->height) - $y);
        $this->x = $x;
        $this->y = $y;
        return $this;
    }
}
