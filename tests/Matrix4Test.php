<?php

namespace Phame\Tests;

use PHPUnit\Framework\TestCase;
use Phame\Math\Matrix4;
use Phame\Math\Vector3;

final class Matrix4Test extends TestCase
{
    private function assertPoint(array $expected, Vector3 $actual, string $msg = ''): void
    {
        $got = [$actual->x, $actual->y, $actual->z];
        foreach ($expected as $i => $v) {
            $this->assertEqualsWithDelta($v, $got[$i], 1e-9, $msg . ' [' . implode(',', $got) . ']');
        }
    }

    private function compose(float ...$a): Matrix4
    {
        return Matrix4::get()->compose(...$a);
    }

    public function testIdentityLeavesPointsAlone(): void
    {
        $this->assertPoint([1, 2, 3], Matrix4::get()->transformPoint(Vector3::get(1, 2, 3)));
    }

    public function testComposeTranslatesAndScales(): void
    {
        $this->assertPoint([12, 23, 34], $this->compose(10, 20, 30, 0, 0, 0, 2, 3, 4)->transformPoint(Vector3::get(1, 1, 1)));
    }

    public function testRotationAboutYMapsXToMinusZ(): void
    {
        $this->assertPoint([0, 0, -1], $this->compose(0, 0, 0, 0, M_PI / 2, 0, 1, 1, 1)->transformPoint(Vector3::get(1, 0, 0)), 'right-handed');
    }

    public function testRotationAboutXMapsYToZ(): void
    {
        $this->assertPoint([0, 0, 1], $this->compose(0, 0, 0, M_PI / 2, 0, 0, 1, 1, 1)->transformPoint(Vector3::get(0, 1, 0)));
    }

    public function testRotationAboutZMapsXToY(): void
    {
        $this->assertPoint([0, 1, 0], $this->compose(0, 0, 0, 0, 0, M_PI / 2, 1, 1, 1)->transformPoint(Vector3::get(1, 0, 0)));
    }

    public function testCombinedRotationAppliesZThenXThenY(): void
    {
        $m = $this->compose(0, 0, 0, M_PI / 2, M_PI / 2, M_PI / 2, 1, 1, 1);
        // (1,0,0) -z-> (0,1,0) -x-> (0,0,1) -y-> (1,0,0)
        $this->assertPoint([1, 0, 0], $m->transformPoint(Vector3::get(1, 0, 0)));
        // (0,1,0) -z-> (-1,0,0) -x-> (-1,0,0) -y-> (0,0,1)
        $this->assertPoint([0, 0, 1], $m->transformPoint(Vector3::get(0, 1, 0)));
    }

    public function testProductAppliesTheRightOperandFirst(): void
    {
        $translate = $this->compose(5, 0, 0, 0, 0, 0, 1, 1, 1);
        $scale = $this->compose(0, 0, 0, 0, 0, 0, 2, 2, 2);
        $this->assertPoint([7, 0, 0], Matrix4::get()->product($translate, $scale)->transformPoint(Vector3::get(1, 0, 0)), 'scale then translate');
        $this->assertPoint([12, 0, 0], Matrix4::get()->product($scale, $translate)->transformPoint(Vector3::get(1, 0, 0)), 'translate then scale');
    }

    public function testProductMayOverwriteAnOperand(): void
    {
        $a = $this->compose(1, 2, 3, 0.3, 0.2, 0.1, 2, 2, 2);
        $b = $this->compose(-3, 1, 0, 0, 0.5, 0, 1, 1, 1);
        $expected = Matrix4::get()->product($a, $b);
        $a->product($a, $b);
        $this->assertSame($expected->pack(), $a->pack());
        $b2 = $this->compose(-3, 1, 0, 0, 0.5, 0, 1, 1, 1);
        $b2->product($this->compose(1, 2, 3, 0.3, 0.2, 0.1, 2, 2, 2), $b2);
        $this->assertSame($expected->pack(), $b2->pack());
    }

    public function testInverse(): void
    {
        $m = $this->compose(1, -2, 3, 0.3, 0.5, -0.7, 2, 3, 0.5);
        $inverse = Matrix4::get();
        $this->assertTrue($inverse->invertFrom($m));
        $this->assertPoint([4, 5, 6], Matrix4::get()->product($m, $inverse)->transformPoint(Vector3::get(4, 5, 6)));
        $this->assertTrue($m->invertFrom($m), 'in place');
        $this->assertSame($inverse->pack(), $m->pack());
    }

    public function testSingularMatrixIsNotInvertedAndLeavesTheTargetAlone(): void
    {
        $zero = $this->compose(0, 0, 0, 0, 0, 0, 0, 0, 0);
        $target = $this->compose(1, 2, 3, 0, 0, 0, 1, 1, 1);
        $before = $target->pack();
        $this->assertFalse($target->invertFrom($zero));
        $this->assertSame($before, $target->pack());
    }

    public function testLookAtPutsTheTargetOnTheNegativeZAxis(): void
    {
        $v = Matrix4::get()->lookAt(Vector3::get(0, 0, 5), Vector3::get(0, 0, 0));
        $this->assertPoint([0, 0, -5], $v->transformPoint(Vector3::get(0, 0, 0)));
        $v = Matrix4::get()->lookAt(Vector3::get(5, 5, 5), Vector3::get(0, 0, 0));
        $p = $v->transformPoint(Vector3::get(0, 0, 0));
        $this->assertEqualsWithDelta(0.0, $p->x, 1e-9);
        $this->assertEqualsWithDelta(0.0, $p->y, 1e-9);
        $this->assertEqualsWithDelta(-sqrt(75), $p->z, 1e-9);
    }

    public function testPerspectiveMapsNearAndFarPlanesToMinusOneAndOne(): void
    {
        $p = Matrix4::get()->perspective(M_PI / 2, 2.0, 1.0, 11.0);
        $this->assertEqualsWithDelta(-1.0, $p->transformPoint(Vector3::get(0, 0, -1))->z, 1e-9);
        $this->assertEqualsWithDelta(1.0, $p->transformPoint(Vector3::get(0, 0, -11))->z, 1e-9);
        // 90 degree vertical fov: at distance 4 the visible half-height is 4, the half-width 8
        $this->assertPoint([1.0, 0.0], $p->transformPoint(Vector3::get(8, 0, -4)));
        $this->assertPoint([0.0, 1.0], $p->transformPoint(Vector3::get(0, 4, -4)));
    }

    public function testPerspectiveOverwritesAllStaleValues(): void
    {
        $m = $this->compose(9, 9, 9, 1, 2, 3, 4, 5, 6);
        $m->perspective(M_PI / 2, 1.0, 1.0, 3.0);
        $this->assertSame(Matrix4::get()->perspective(M_PI / 2, 1.0, 1.0, 3.0)->pack(), $m->pack());
    }

    public function testNormalMatrixKeepsNormalsPerpendicularUnderNonUniformScale(): void
    {
        $m = $this->compose(0, 0, 0, 0.4, 0.2, 0.1, 4, 1, 1);
        $n = array_values(unpack('f9', $m->packNormalMatrix()));
        // surface through the origin with normal (1,1,0) contains the tangent (1,-1,0); both are transformed
        $t = $m->transformDirection(Vector3::get(1, -1, 0));
        $nn = [$n[0] + $n[3], $n[1] + $n[4], $n[2] + $n[5]];   // N · (1,1,0)
        $this->assertEqualsWithDelta(0.0, $nn[0] * $t->x + $nn[1] * $t->y + $nn[2] * $t->z, 1e-5);
    }

    public function testPackIsColumnMajorFloat32(): void
    {
        $floats = array_values(unpack('f16', $this->compose(7, 8, 9, 0, 0, 0, 1, 1, 1)->pack()));
        $this->assertSame([7.0, 8.0, 9.0, 1.0], array_slice($floats, 12, 4));
    }

    public function testGetAlwaysReturnsTheIdentityEvenForRecycledMatrices(): void
    {
        $this->compose(5, 6, 7, 1, 1, 1, 3, 3, 3)->release();
        $this->assertSame(Matrix4::get()->pack(), pack('f16', 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1));
    }

    public function testLookAtAlongTheUpVectorStillLooksDownMinusZ(): void
    {
        foreach ([[0, 5, 0, 0, 0, 0], [0, -5, 0, 0, 0, 0], [1, 1, 1, 1, 1, 1]] as [$ex, $ey, $ez, $tx, $ty, $tz]) {
            $m = Matrix4::get()->lookAt(Vector3::get($ex, $ey, $ez), Vector3::get($tx, $ty, $tz));
            $this->assertPoint([0, 0, 0], $m->transformPoint(Vector3::get($ex, $ey, $ez)), 'the eye is the origin');
            $distance = sqrt(($tx - $ex) ** 2 + ($ty - $ey) ** 2 + ($tz - $ez) ** 2);
            $this->assertPoint([0, 0, -$distance], $m->transformPoint(Vector3::get($tx, $ty, $tz)), 'the target is straight ahead');
        }
    }

    public function testTransformPointOnTheCameraPlaneIsInfiniteNotAnError(): void
    {
        $p = Matrix4::get()->perspective(1.0, 1.0, 0.1, 100.0)->transformPoint(Vector3::get(0, 0, 0));
        $this->assertTrue(is_infinite($p->x) || is_nan($p->x));
    }
}
