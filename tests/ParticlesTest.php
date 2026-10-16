<?php

namespace Phame\Tests;

use Phame\Physics\Particles;
use PHPUnit\Framework\TestCase;

final class ParticlesTest extends TestCase
{
    /** A rope of $n particles along ($dx, $dy, $dz), moving at $v, with links that collide and soft bending. */
    private static function rope(Particles $p, array $at, array $step, int $n, array $v): array
    {
        $ids = [];
        for ($i = 0; $i < $n; ++$i) {
            $ids[] = $k = $p->addParticle($at[0] + $step[0] * $i, $at[1] + $step[1] * $i, $at[2] + $step[2] * $i);
            [$p->vx[$k], $p->vy[$k], $p->vz[$k]] = $v;
            if ($i > 0) {
                $p->addConstraint($ids[$i - 1], $k);
                $p->addSegment($ids[$i - 1], $k);
            }
            if ($i > 1) {
                $p->addConstraint($ids[$i - 2], $k, 1e-4);
            }
        }
        return $ids;
    }

    private static function segmentToPoint(Particles $p, int $a, int $b, float $cx, float $cy, float $cz): float
    {
        [$ex, $ey, $ez] = [$p->x[$b] - $p->x[$a], $p->y[$b] - $p->y[$a], $p->z[$b] - $p->z[$a]];
        $t = max(0.0, min(1.0, (($cx - $p->x[$a]) * $ex + ($cy - $p->y[$a]) * $ey + ($cz - $p->z[$a]) * $ez) / ($ex * $ex + $ey * $ey + $ez * $ez)));
        return sqrt(($p->x[$a] + $t * $ex - $cx) ** 2 + ($p->y[$a] + $t * $ey - $cy) ** 2 + ($p->z[$a] + $t * $ez - $cz) ** 2);
    }

    public function testARopeThrownAtASphereDrapesAroundItWithoutPassingThrough(): void
    {
        $p = new Particles();
        $p->thickness = 0.12;
        $ids = self::rope($p, [-2.85, 0.0, 0.0], [0.3, 0.0, 0.0], 20, [0.0, 0.0, -20.0]);
        $p->addCollider(0.0, 0.0, -3.0, 1.0);
        $deepest = 0.0;
        for ($f = 0; $f < 40; ++$f) {
            $p->step(1 / 60, 4);
            for ($s = 0; $s < $p->segments; ++$s) {
                $deepest = max($deepest, 1.12 - self::segmentToPoint($p, $p->sa[$s], $p->sb[$s], 0.0, 0.0, -3.0));
            }
        }
        $this->assertLessThan(0.02, $deepest, 'never into the sphere');
        $this->assertGreaterThan(-3.0, $p->z[$ids[10]], 'its middle stopped at the sphere');
        $this->assertLessThan($p->z[$ids[10]] - 0.3, $p->z[$ids[0]], 'its ends went on, bending around it');
        $this->assertLessThan($p->z[$ids[10]] - 0.3, $p->z[$ids[19]]);
        $length = 0.0;
        for ($i = 1; $i < 20; ++$i) {
            $length += sqrt(($p->x[$ids[$i]] - $p->x[$ids[$i - 1]]) ** 2 + ($p->y[$ids[$i]] - $p->y[$ids[$i - 1]]) ** 2 + ($p->z[$ids[$i]] - $p->z[$ids[$i - 1]]) ** 2);
        }
        $this->assertEqualsWithDelta(19 * 0.3, $length, 0.1, 'it keeps its length');
    }

    public function testTwoRopesNeverPassThroughEachOther(): void
    {
        $p = new Particles();
        $p->thickness = 0.12;
        $a = self::rope($p, [-2.85, 0.0, 0.0], [0.3, 0.0, 0.0], 20, [0.0, 0.0, -15.0]);   // flies into the other
        $b = self::rope($p, [0.0, -2.85, -2.0], [0.0, 0.3, 0.0], 20, [0.0, 0.0, 0.0]);    // still, crossways
        $closest = INF;
        for ($f = 0; $f < 240; ++$f) {
            $p->step(1 / 240, 1);
            foreach ([[$a, $b], [$b, $a]] as [$one, $other]) {
                foreach ($one as $i) {   // every particle of one rope against every link of the other
                    for ($k = 1; $k < 20; ++$k) {
                        $closest = min($closest, self::segmentToPoint($p, $other[$k - 1], $other[$k], $p->x[$i], $p->y[$i], $p->z[$i]));
                    }
                }
            }
        }
        $this->assertGreaterThan(0.24 - 0.02, $closest, 'held at twice the thickness');
        $this->assertGreaterThan($p->z[$b[10]], $p->z[$a[10]], 'the middle of the flying rope stays behind the other: it carries it');
    }
}
