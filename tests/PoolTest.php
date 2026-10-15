<?php

namespace Phame\Tests;

use PHPUnit\Framework\TestCase;
use Phame\Math\IntRect;
use Phame\Math\Matrix4;
use Phame\Math\Vector3;

final class PoolTest extends TestCase
{
    public function testConstructorsArePrivate(): void
    {
        foreach ([Vector3::class, Matrix4::class, IntRect::class] as $class) {
            $this->assertTrue((new \ReflectionClass($class))->getConstructor()->isPrivate(), $class);
        }
    }

    public function testReleasedInstancesAreReused(): void
    {
        $a = Vector3::get(1, 2, 3);
        $a->release();
        $b = Vector3::get(4, 5, 6);
        $this->assertSame($a, $b);
        $this->assertSame([4.0, 5.0, 6.0], [$b->x, $b->y, $b->z], 'a recycled vector is fully reinitialised');
    }

    public function testPoolsAreSeparatePerClass(): void
    {
        $before = IntRect::pooledCount();
        Vector3::get()->release();
        $this->assertSame($before, IntRect::pooledCount());
    }

    public function testReleasingTwiceIsAnError(): void
    {
        $v = Vector3::get();
        $v->release();
        $this->expectException(\LogicException::class);
        $v->release();
    }

    public function testAnInstanceInUseIsNeverHandedOutTwice(): void
    {
        $a = Vector3::get();
        $b = Vector3::get();
        $this->assertNotSame($a, $b);
        $a->release();
        $c = Vector3::get();
        $this->assertSame($a, $c);
        $this->assertNotSame($b, $c);
    }

    public function testSteadyStateUsePerformsNoAllocation(): void
    {
        $warm = Vector3::get();
        $warm->release();
        $before = memory_get_usage();
        for ($i = 0; $i < 10000; ++$i) {
            Vector3::get($i, 0, 0)->release();
        }
        $this->assertLessThan(2048, memory_get_usage() - $before);
    }

    public function testVectorOperations(): void
    {
        $v = Vector3::get(3, 4, 0);
        $this->assertSame(5.0, $v->length());
        $this->assertEqualsWithDelta(1.0, $v->normalize()->length(), 1e-12);
        $this->assertSame(0.0, Vector3::get()->normalize()->length(), 'zero stays zero');
        $c = Vector3::get()->cross(Vector3::get(1, 0, 0), Vector3::get(0, 1, 0));
        $this->assertSame([0.0, 0.0, 1.0], [$c->x, $c->y, $c->z]);
        $a = Vector3::get(1, 0, 0);
        $a->cross($a, Vector3::get(0, 1, 0));
        $this->assertSame(1.0, $a->z, 'cross may overwrite its operand');
        $s = Vector3::get(1, 2, 3)->add(Vector3::get(1, 1, 1))->sub(Vector3::get(0, 0, 1))->scale(2.0);
        $this->assertSame([4.0, 6.0, 6.0], [$s->x, $s->y, $s->z]);
        $this->assertSame(32.0, Vector3::get(1, 2, 3)->dot(Vector3::get(4, 5, 6)));
    }

    public function testIntRectIntersection(): void
    {
        $r = IntRect::get(0, 0, 10, 10)->intersect(IntRect::get(5, 5, 10, 10));
        $this->assertSame([5, 5, 5, 5], [$r->x, $r->y, $r->width, $r->height]);
        $r = IntRect::get(0, 0, 4, 4)->intersect(IntRect::get(10, 10, 4, 4));
        $this->assertSame([0, 0], [$r->width, $r->height], 'disjoint rectangles end up empty');
    }
}
