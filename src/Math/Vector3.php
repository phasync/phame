<?php

namespace Phame\Math;

use Phame\Pool\Poolable;

/**
 * A mutable 3D vector. Get one with `Vector3::get()` and give it back with `release()` when it was only a
 * temporary. Operations change the vector in place and return it, so calls chain without allocating.
 */
final class Vector3
{
    use Poolable;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    private function __construct()
    {
    }

    public static function get(float $x = 0.0, float $y = 0.0, float $z = 0.0): self
    {
        $v = self::acquire();
        $v->x = $x;
        $v->y = $y;
        $v->z = $z;
        return $v;
    }

    public function set(float $x, float $y, float $z): self
    {
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        return $this;
    }

    public function copyFrom(self $o): self
    {
        $this->x = $o->x;
        $this->y = $o->y;
        $this->z = $o->z;
        return $this;
    }

    public function add(self $o): self
    {
        $this->x += $o->x;
        $this->y += $o->y;
        $this->z += $o->z;
        return $this;
    }

    public function sub(self $o): self
    {
        $this->x -= $o->x;
        $this->y -= $o->y;
        $this->z -= $o->z;
        return $this;
    }

    public function scale(float $s): self
    {
        $this->x *= $s;
        $this->y *= $s;
        $this->z *= $s;
        return $this;
    }

    public function dot(self $o): float
    {
        return $this->x * $o->x + $this->y * $o->y + $this->z * $o->z;
    }

    /** Sets this to a × b; either may be this vector. */
    public function cross(self $a, self $b): self
    {
        $x = $a->y * $b->z - $a->z * $b->y;
        $y = $a->z * $b->x - $a->x * $b->z;
        $z = $a->x * $b->y - $a->y * $b->x;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        return $this;
    }

    public function lengthSquared(): float
    {
        return $this->x * $this->x + $this->y * $this->y + $this->z * $this->z;
    }

    public function length(): float
    {
        return sqrt($this->x * $this->x + $this->y * $this->y + $this->z * $this->z);
    }

    /** Scales to length 1; the zero vector stays zero. */
    public function normalize(): self
    {
        $len = sqrt($this->x * $this->x + $this->y * $this->y + $this->z * $this->z);
        if ($len != 0.0) {
            $this->x /= $len;
            $this->y /= $len;
            $this->z /= $len;
        }
        return $this;
    }
}
