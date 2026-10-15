<?php

namespace Phame\Math;

/** A 2D affine transform: x' = a*x + c*y + tx, y' = b*x + d*y + ty. */
final class Matrix
{
    public function __construct(
        public float $a = 1.0,
        public float $b = 0.0,
        public float $c = 0.0,
        public float $d = 1.0,
        public float $tx = 0.0,
        public float $ty = 0.0,
    ) {
    }

    /** Appends $m: the result applies this transform first, then $m. */
    public function concat(Matrix $m): void
    {
        [$a, $b, $c, $d, $tx, $ty] = [$this->a, $this->b, $this->c, $this->d, $this->tx, $this->ty];
        $this->a = $a * $m->a + $b * $m->c;
        $this->b = $a * $m->b + $b * $m->d;
        $this->c = $c * $m->a + $d * $m->c;
        $this->d = $c * $m->b + $d * $m->d;
        $this->tx = $tx * $m->a + $ty * $m->c + $m->tx;
        $this->ty = $tx * $m->b + $ty * $m->d + $m->ty;
    }

    public function invert(): void
    {
        $det = $this->a * $this->d - $this->b * $this->c;
        if ($det == 0.0) {
            [$this->a, $this->b, $this->c, $this->d, $this->tx, $this->ty] = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
            return;
        }
        [$a, $b, $c, $d, $tx, $ty] = [$this->a, $this->b, $this->c, $this->d, $this->tx, $this->ty];
        $this->a = $d / $det;
        $this->b = -$b / $det;
        $this->c = -$c / $det;
        $this->d = $a / $det;
        $this->tx = ($c * $ty - $d * $tx) / $det;
        $this->ty = ($b * $tx - $a * $ty) / $det;
    }

    /** @return array{float, float} */
    public function transform(float $x, float $y): array
    {
        return [$this->a * $x + $this->c * $y + $this->tx, $this->b * $x + $this->d * $y + $this->ty];
    }
}
