<?php

namespace Phame\Math;

final class Rectangle
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $width = 0.0,
        public float $height = 0.0,
    ) {
    }

    public float $right {
        get => $this->x + $this->width;
    }

    public float $bottom {
        get => $this->y + $this->height;
    }

    public function contains(float $x, float $y): bool
    {
        return $x >= $this->x && $y >= $this->y && $x < $this->x + $this->width && $y < $this->y + $this->height;
    }

    public function intersects(Rectangle $o): bool
    {
        return $this->x < $o->right && $o->x < $this->right && $this->y < $o->bottom && $o->y < $this->bottom;
    }

    public function union(Rectangle $o): Rectangle
    {
        $x = min($this->x, $o->x);
        $y = min($this->y, $o->y);
        return new Rectangle($x, $y, max($this->right, $o->right) - $x, max($this->bottom, $o->bottom) - $y);
    }
}
