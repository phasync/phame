<?php

namespace Phame\Tests\Verification\Oracle;

/**
 * A 3x3 rotation matrix, row-major (m0..m2 the first row). The oracle's own math: no relation to the engine's
 * Matrix4 or its quaternions. Used only to turn compound-tree offsets and angular velocities into world space.
 */
final class Mat3
{
    public function __construct(
        public float $m0 = 1.0, public float $m1 = 0.0, public float $m2 = 0.0,
        public float $m3 = 0.0, public float $m4 = 1.0, public float $m5 = 0.0,
        public float $m6 = 0.0, public float $m7 = 0.0, public float $m8 = 1.0,
    ) {
    }

    /**
     * The rotation by $angle radians about the axis (x, y, z), which need not be normalized (zero gives identity).
     * Rodrigues' formula: R = I + sinθ·K + (1-cosθ)·K², K the cross-product matrix of the unit axis.
     */
    public static function axisAngle(float $x, float $y, float $z, float $angle): self
    {
        $len = sqrt($x * $x + $y * $y + $z * $z);
        if ($len < 1e-15 || $angle === 0.0) {
            return new self();
        }
        [$x, $y, $z] = [$x / $len, $y / $len, $z / $len];
        $s = sin($angle);
        $c = 1.0 - cos($angle);
        return new self(
            1.0 + $c * ($x * $x - 1.0), $c * $x * $y - $s * $z, $c * $x * $z + $s * $y,
            $c * $x * $y + $s * $z, 1.0 + $c * ($y * $y - 1.0), $c * $y * $z - $s * $x,
            $c * $x * $z - $s * $y, $c * $y * $z + $s * $x, 1.0 + $c * ($z * $z - 1.0),
        );
    }

    /** An angular velocity (x, y, z) held constant for $dt seconds: the rotation it turns through. */
    public static function fromSpin(float $x, float $y, float $z, float $dt): self
    {
        return self::axisAngle($x, $y, $z, sqrt($x * $x + $y * $y + $z * $z) * $dt);
    }

    /** $this * $other: applying $other first, then $this (standard composition of transforms). */
    public function multiply(self $o): self
    {
        return new self(
            $this->m0 * $o->m0 + $this->m1 * $o->m3 + $this->m2 * $o->m6,
            $this->m0 * $o->m1 + $this->m1 * $o->m4 + $this->m2 * $o->m7,
            $this->m0 * $o->m2 + $this->m1 * $o->m5 + $this->m2 * $o->m8,
            $this->m3 * $o->m0 + $this->m4 * $o->m3 + $this->m5 * $o->m6,
            $this->m3 * $o->m1 + $this->m4 * $o->m4 + $this->m5 * $o->m7,
            $this->m3 * $o->m2 + $this->m4 * $o->m5 + $this->m5 * $o->m8,
            $this->m6 * $o->m0 + $this->m7 * $o->m3 + $this->m8 * $o->m6,
            $this->m6 * $o->m1 + $this->m7 * $o->m4 + $this->m8 * $o->m7,
            $this->m6 * $o->m2 + $this->m7 * $o->m5 + $this->m8 * $o->m8,
        );
    }

    /** @return array{float, float, float} This matrix applied to the vector (x, y, z). */
    public function apply(float $x, float $y, float $z): array
    {
        return [
            $this->m0 * $x + $this->m1 * $y + $this->m2 * $z,
            $this->m3 * $x + $this->m4 * $y + $this->m5 * $z,
            $this->m6 * $x + $this->m7 * $y + $this->m8 * $z,
        ];
    }
}
