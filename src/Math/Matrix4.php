<?php

namespace Phame\Math;

use Phame\Pool\Poolable;

/**
 * A mutable 4x4 transform for 3D, stored column-major like OpenGL: `$m{column * 4 + row}`. The coordinate system
 * is right-handed with y up; a camera looks down its own -z axis. Points are column vectors.
 *
 * It is a pooled type with typed float fields, so a frame's worth of matrix math allocates nothing: get one
 * with `Matrix4::get()` (the identity), fill it with the in-place operations below, and `release()` temporaries.
 */
final class Matrix4
{
    use Poolable;

    public float $m0 = 1.0;
    public float $m1 = 0.0;
    public float $m2 = 0.0;
    public float $m3 = 0.0;
    public float $m4 = 0.0;
    public float $m5 = 1.0;
    public float $m6 = 0.0;
    public float $m7 = 0.0;
    public float $m8 = 0.0;
    public float $m9 = 0.0;
    public float $m10 = 1.0;
    public float $m11 = 0.0;
    public float $m12 = 0.0;
    public float $m13 = 0.0;
    public float $m14 = 0.0;
    public float $m15 = 1.0;

    private function __construct()
    {
    }

    /** An identity matrix. */
    public static function get(): self
    {
        return self::acquire()->identity();
    }

    public function identity(): self
    {
        [$this->m0, $this->m1, $this->m2, $this->m3] = [1.0, 0.0, 0.0, 0.0];
        [$this->m4, $this->m5, $this->m6, $this->m7] = [0.0, 1.0, 0.0, 0.0];
        [$this->m8, $this->m9, $this->m10, $this->m11] = [0.0, 0.0, 1.0, 0.0];
        [$this->m12, $this->m13, $this->m14, $this->m15] = [0.0, 0.0, 0.0, 1.0];
        return $this;
    }

    public function copyFrom(self $o): self
    {
        $this->m0 = $o->m0;
        $this->m1 = $o->m1;
        $this->m2 = $o->m2;
        $this->m3 = $o->m3;
        $this->m4 = $o->m4;
        $this->m5 = $o->m5;
        $this->m6 = $o->m6;
        $this->m7 = $o->m7;
        $this->m8 = $o->m8;
        $this->m9 = $o->m9;
        $this->m10 = $o->m10;
        $this->m11 = $o->m11;
        $this->m12 = $o->m12;
        $this->m13 = $o->m13;
        $this->m14 = $o->m14;
        $this->m15 = $o->m15;
        return $this;
    }

    /** Translation, then rotation about y, x and z (applied to a point in the order z, x, y), then scale. Angles in radians. */
    /** Translation, rotation by the unit quaternion (qx, qy, qz, qw), then scale: the same layout as compose(). */
    public function composeQuaternion(float $x, float $y, float $z, float $qx, float $qy, float $qz, float $qw, float $sx, float $sy, float $sz): self
    {
        $xx = $qx * $qx;
        $yy = $qy * $qy;
        $zz = $qz * $qz;
        $xy = $qx * $qy;
        $xz = $qx * $qz;
        $yz = $qy * $qz;
        $wx = $qw * $qx;
        $wy = $qw * $qy;
        $wz = $qw * $qz;
        $this->m0 = (1.0 - 2.0 * ($yy + $zz)) * $sx;
        $this->m1 = 2.0 * ($xy + $wz) * $sx;
        $this->m2 = 2.0 * ($xz - $wy) * $sx;
        $this->m3 = 0.0;
        $this->m4 = 2.0 * ($xy - $wz) * $sy;
        $this->m5 = (1.0 - 2.0 * ($xx + $zz)) * $sy;
        $this->m6 = 2.0 * ($yz + $wx) * $sy;
        $this->m7 = 0.0;
        $this->m8 = 2.0 * ($xz + $wy) * $sz;
        $this->m9 = 2.0 * ($yz - $wx) * $sz;
        $this->m10 = (1.0 - 2.0 * ($xx + $yy)) * $sz;
        $this->m11 = 0.0;
        $this->m12 = $x;
        $this->m13 = $y;
        $this->m14 = $z;
        $this->m15 = 1.0;
        return $this;
    }

    public function compose(float $x, float $y, float $z, float $rx, float $ry, float $rz, float $sx, float $sy, float $sz): self
    {
        $cx = cos($rx);
        $sinx = sin($rx);
        $cy = cos($ry);
        $siny = sin($ry);
        $cz = cos($rz);
        $sinz = sin($rz);
        $this->m0 = ($cy * $cz + $siny * $sinx * $sinz) * $sx;
        $this->m1 = $cx * $sinz * $sx;
        $this->m2 = (-$siny * $cz + $cy * $sinx * $sinz) * $sx;
        $this->m3 = 0.0;
        $this->m4 = (-$cy * $sinz + $siny * $sinx * $cz) * $sy;
        $this->m5 = $cx * $cz * $sy;
        $this->m6 = ($siny * $sinz + $cy * $sinx * $cz) * $sy;
        $this->m7 = 0.0;
        $this->m8 = $siny * $cx * $sz;
        $this->m9 = -$sinx * $sz;
        $this->m10 = $cy * $cx * $sz;
        $this->m11 = 0.0;
        $this->m12 = $x;
        $this->m13 = $y;
        $this->m14 = $z;
        $this->m15 = 1.0;
        return $this;
    }

    /** A perspective projection. $fovY is the vertical field of view in radians; depth maps to -1..1. */
    public function perspective(float $fovY, float $aspect, float $near, float $far): self
    {
        $f = 1.0 / tan($fovY / 2.0);
        $this->m0 = $f / $aspect;
        $this->m5 = $f;
        $this->m10 = ($far + $near) / ($near - $far);
        $this->m11 = -1.0;
        $this->m14 = 2.0 * $far * $near / ($near - $far);
        $this->m1 = $this->m2 = $this->m3 = $this->m4 = $this->m6 = $this->m7 = $this->m8 = $this->m9 = $this->m12 = $this->m13 = $this->m15 = 0.0;
        return $this;
    }

    /** The world-to-camera transform for a camera at $eye looking at $target; $up defaults to +y. */
    public function lookAt(Vector3 $eye, Vector3 $target, ?Vector3 $up = null): self
    {
        $fx = $target->x - $eye->x;
        $fy = $target->y - $eye->y;
        $fz = $target->z - $eye->z;
        $len = sqrt($fx * $fx + $fy * $fy + $fz * $fz);
        if ($len == 0.0) {
            $fx = 0.0;
            $fy = 0.0;
            $fz = -1.0;
        } else {
            $fx /= $len;
            $fy /= $len;
            $fz /= $len;
        }
        $ux = $up?->x ?? 0.0;
        $uy = $up?->y ?? 1.0;
        $uz = $up?->z ?? 0.0;
        $sx = $fy * $uz - $fz * $uy;
        $sy = $fz * $ux - $fx * $uz;
        $sz = $fx * $uy - $fy * $ux;
        $len = sqrt($sx * $sx + $sy * $sy + $sz * $sz);
        if ($len < 1e-9) {
            // looking along the up vector: any perpendicular will do, pick the one that continues -z "up"
            $sx = 1.0;
            $sy = 0.0;
            $sz = 0.0;
        } else {
            $sx /= $len;
            $sy /= $len;
            $sz /= $len;
        }
        $tx = $sy * $fz - $sz * $fy;
        $ty = $sz * $fx - $sx * $fz;
        $tz = $sx * $fy - $sy * $fx;
        $this->m0 = $sx;
        $this->m1 = $tx;
        $this->m2 = -$fx;
        $this->m3 = 0.0;
        $this->m4 = $sy;
        $this->m5 = $ty;
        $this->m6 = -$fy;
        $this->m7 = 0.0;
        $this->m8 = $sz;
        $this->m9 = $tz;
        $this->m10 = -$fz;
        $this->m11 = 0.0;
        $this->m12 = -($sx * $eye->x + $sy * $eye->y + $sz * $eye->z);
        $this->m13 = -($tx * $eye->x + $ty * $eye->y + $tz * $eye->z);
        $this->m14 = $fx * $eye->x + $fy * $eye->y + $fz * $eye->z;
        $this->m15 = 1.0;
        return $this;
    }

    /** Sets this to $a · $b, which applies $b first. Either operand may be this matrix. Unrolled: it is the hottest function in the 3D path. */
    public function product(self $a, self $b): self
    {
        $a0 = $a->m0;
        $a1 = $a->m1;
        $a2 = $a->m2;
        $a3 = $a->m3;
        $a4 = $a->m4;
        $a5 = $a->m5;
        $a6 = $a->m6;
        $a7 = $a->m7;
        $a8 = $a->m8;
        $a9 = $a->m9;
        $a10 = $a->m10;
        $a11 = $a->m11;
        $a12 = $a->m12;
        $a13 = $a->m13;
        $a14 = $a->m14;
        $a15 = $a->m15;
        $b0 = $b->m0;
        $b1 = $b->m1;
        $b2 = $b->m2;
        $b3 = $b->m3;
        $b4 = $b->m4;
        $b5 = $b->m5;
        $b6 = $b->m6;
        $b7 = $b->m7;
        $b8 = $b->m8;
        $b9 = $b->m9;
        $b10 = $b->m10;
        $b11 = $b->m11;
        $b12 = $b->m12;
        $b13 = $b->m13;
        $b14 = $b->m14;
        $b15 = $b->m15;
        $this->m0 = $a0 * $b0 + $a4 * $b1 + $a8 * $b2 + $a12 * $b3;
        $this->m1 = $a1 * $b0 + $a5 * $b1 + $a9 * $b2 + $a13 * $b3;
        $this->m2 = $a2 * $b0 + $a6 * $b1 + $a10 * $b2 + $a14 * $b3;
        $this->m3 = $a3 * $b0 + $a7 * $b1 + $a11 * $b2 + $a15 * $b3;
        $this->m4 = $a0 * $b4 + $a4 * $b5 + $a8 * $b6 + $a12 * $b7;
        $this->m5 = $a1 * $b4 + $a5 * $b5 + $a9 * $b6 + $a13 * $b7;
        $this->m6 = $a2 * $b4 + $a6 * $b5 + $a10 * $b6 + $a14 * $b7;
        $this->m7 = $a3 * $b4 + $a7 * $b5 + $a11 * $b6 + $a15 * $b7;
        $this->m8 = $a0 * $b8 + $a4 * $b9 + $a8 * $b10 + $a12 * $b11;
        $this->m9 = $a1 * $b8 + $a5 * $b9 + $a9 * $b10 + $a13 * $b11;
        $this->m10 = $a2 * $b8 + $a6 * $b9 + $a10 * $b10 + $a14 * $b11;
        $this->m11 = $a3 * $b8 + $a7 * $b9 + $a11 * $b10 + $a15 * $b11;
        $this->m12 = $a0 * $b12 + $a4 * $b13 + $a8 * $b14 + $a12 * $b15;
        $this->m13 = $a1 * $b12 + $a5 * $b13 + $a9 * $b14 + $a13 * $b15;
        $this->m14 = $a2 * $b12 + $a6 * $b13 + $a10 * $b14 + $a14 * $b15;
        $this->m15 = $a3 * $b12 + $a7 * $b13 + $a11 * $b14 + $a15 * $b15;
        return $this;
    }

    /** Sets this to the inverse of $o (which may be this matrix). Returns false and changes nothing if $o is singular. */
    public function invertFrom(self $o): bool
    {
        $m0 = $o->m0;
        $m1 = $o->m1;
        $m2 = $o->m2;
        $m3 = $o->m3;
        $m4 = $o->m4;
        $m5 = $o->m5;
        $m6 = $o->m6;
        $m7 = $o->m7;
        $m8 = $o->m8;
        $m9 = $o->m9;
        $m10 = $o->m10;
        $m11 = $o->m11;
        $m12 = $o->m12;
        $m13 = $o->m13;
        $m14 = $o->m14;
        $m15 = $o->m15;
        $i0 = $m5 * $m10 * $m15 - $m5 * $m11 * $m14 - $m9 * $m6 * $m15 + $m9 * $m7 * $m14 + $m13 * $m6 * $m11 - $m13 * $m7 * $m10;
        $i1 = -$m1 * $m10 * $m15 + $m1 * $m11 * $m14 + $m9 * $m2 * $m15 - $m9 * $m3 * $m14 - $m13 * $m2 * $m11 + $m13 * $m3 * $m10;
        $i2 = $m1 * $m6 * $m15 - $m1 * $m7 * $m14 - $m5 * $m2 * $m15 + $m5 * $m3 * $m14 + $m13 * $m2 * $m7 - $m13 * $m3 * $m6;
        $i3 = -$m1 * $m6 * $m11 + $m1 * $m7 * $m10 + $m5 * $m2 * $m11 - $m5 * $m3 * $m10 - $m9 * $m2 * $m7 + $m9 * $m3 * $m6;
        $i4 = -$m4 * $m10 * $m15 + $m4 * $m11 * $m14 + $m8 * $m6 * $m15 - $m8 * $m7 * $m14 - $m12 * $m6 * $m11 + $m12 * $m7 * $m10;
        $i5 = $m0 * $m10 * $m15 - $m0 * $m11 * $m14 - $m8 * $m2 * $m15 + $m8 * $m3 * $m14 + $m12 * $m2 * $m11 - $m12 * $m3 * $m10;
        $i6 = -$m0 * $m6 * $m15 + $m0 * $m7 * $m14 + $m4 * $m2 * $m15 - $m4 * $m3 * $m14 - $m12 * $m2 * $m7 + $m12 * $m3 * $m6;
        $i7 = $m0 * $m6 * $m11 - $m0 * $m7 * $m10 - $m4 * $m2 * $m11 + $m4 * $m3 * $m10 + $m8 * $m2 * $m7 - $m8 * $m3 * $m6;
        $i8 = $m4 * $m9 * $m15 - $m4 * $m11 * $m13 - $m8 * $m5 * $m15 + $m8 * $m7 * $m13 + $m12 * $m5 * $m11 - $m12 * $m7 * $m9;
        $i9 = -$m0 * $m9 * $m15 + $m0 * $m11 * $m13 + $m8 * $m1 * $m15 - $m8 * $m3 * $m13 - $m12 * $m1 * $m11 + $m12 * $m3 * $m9;
        $i10 = $m0 * $m5 * $m15 - $m0 * $m7 * $m13 - $m4 * $m1 * $m15 + $m4 * $m3 * $m13 + $m12 * $m1 * $m7 - $m12 * $m3 * $m5;
        $i11 = -$m0 * $m5 * $m11 + $m0 * $m7 * $m9 + $m4 * $m1 * $m11 - $m4 * $m3 * $m9 - $m8 * $m1 * $m7 + $m8 * $m3 * $m5;
        $i12 = -$m4 * $m9 * $m14 + $m4 * $m10 * $m13 + $m8 * $m5 * $m14 - $m8 * $m6 * $m13 - $m12 * $m5 * $m10 + $m12 * $m6 * $m9;
        $i13 = $m0 * $m9 * $m14 - $m0 * $m10 * $m13 - $m8 * $m1 * $m14 + $m8 * $m2 * $m13 + $m12 * $m1 * $m10 - $m12 * $m2 * $m9;
        $i14 = -$m0 * $m5 * $m14 + $m0 * $m6 * $m13 + $m4 * $m1 * $m14 - $m4 * $m2 * $m13 - $m12 * $m1 * $m6 + $m12 * $m2 * $m5;
        $i15 = $m0 * $m5 * $m10 - $m0 * $m6 * $m9 - $m4 * $m1 * $m10 + $m4 * $m2 * $m9 + $m8 * $m1 * $m6 - $m8 * $m2 * $m5;
        $det = $m0 * $i0 + $m1 * $i4 + $m2 * $i8 + $m3 * $i12;
        if ($det == 0.0) {
            return false;
        }
        $this->m0 = $i0 / $det;
        $this->m1 = $i1 / $det;
        $this->m2 = $i2 / $det;
        $this->m3 = $i3 / $det;
        $this->m4 = $i4 / $det;
        $this->m5 = $i5 / $det;
        $this->m6 = $i6 / $det;
        $this->m7 = $i7 / $det;
        $this->m8 = $i8 / $det;
        $this->m9 = $i9 / $det;
        $this->m10 = $i10 / $det;
        $this->m11 = $i11 / $det;
        $this->m12 = $i12 / $det;
        $this->m13 = $i13 / $det;
        $this->m14 = $i14 / $det;
        $this->m15 = $i15 / $det;
        return true;
    }

    /** Transforms the point in place, including the perspective divide. */
    public function transformPoint(Vector3 $p): Vector3
    {
        $x = $p->x;
        $y = $p->y;
        $z = $p->z;
        // fdiv: a point on the camera plane (w = 0) becomes INF, not a DivisionByZeroError
        $iw = fdiv(1.0, $this->m3 * $x + $this->m7 * $y + $this->m11 * $z + $this->m15);
        $p->x = ($this->m0 * $x + $this->m4 * $y + $this->m8 * $z + $this->m12) * $iw;
        $p->y = ($this->m1 * $x + $this->m5 * $y + $this->m9 * $z + $this->m13) * $iw;
        $p->z = ($this->m2 * $x + $this->m6 * $y + $this->m10 * $z + $this->m14) * $iw;
        return $p;
    }

    /** Transforms the direction in place: rotation and scale apply, translation does not. */
    public function transformDirection(Vector3 $d): Vector3
    {
        $x = $d->x;
        $y = $d->y;
        $z = $d->z;
        $d->x = $this->m0 * $x + $this->m4 * $y + $this->m8 * $z;
        $d->y = $this->m1 * $x + $this->m5 * $y + $this->m9 * $z;
        $d->z = $this->m2 * $x + $this->m6 * $y + $this->m10 * $z;
        return $d;
    }

    /** The 16 floats as bytes for glUniformMatrix4fv. */
    public function pack(): string
    {
        return pack('f16', $this->m0, $this->m1, $this->m2, $this->m3, $this->m4, $this->m5, $this->m6, $this->m7, $this->m8, $this->m9, $this->m10, $this->m11, $this->m12, $this->m13, $this->m14, $this->m15);
    }

    /** The 9 floats for glUniformMatrix3fv that transform normals: the cofactor matrix of the upper 3x3 (a normal needs normalizing afterwards). */
    public function packNormalMatrix(): string
    {
        return pack(
            'f9',
            $this->m5 * $this->m10 - $this->m6 * $this->m9, $this->m6 * $this->m8 - $this->m4 * $this->m10, $this->m4 * $this->m9 - $this->m5 * $this->m8,
            $this->m9 * $this->m2 - $this->m10 * $this->m1, $this->m10 * $this->m0 - $this->m8 * $this->m2, $this->m8 * $this->m1 - $this->m9 * $this->m0,
            $this->m1 * $this->m6 - $this->m2 * $this->m5, $this->m2 * $this->m4 - $this->m0 * $this->m6, $this->m0 * $this->m5 - $this->m1 * $this->m4,
        );
    }
}
