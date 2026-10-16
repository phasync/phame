<?php

namespace Phame\Tests\Verification;

use Phame\Tests\Verification\Oracle\SceneSpec;

/**
 * Seeded random scenes, as a {@see SceneSpec}: the one plain description {@see EngineBuilder} and
 * Oracle\Oracle each build their own world from, so the two can never see different scenes. mt_srand() makes every
 * scene reproducible from its seed alone.
 */
final class SceneGenerator
{
    /** N spheres at random positions and velocities, confined to a box, no tethers or compounds. */
    public static function spheresInBox(int $seed, int $n, float $half = 12.0): SceneSpec
    {
        mt_srand($seed);
        $spec = new SceneSpec();
        [$spec->minX, $spec->minY, $spec->minZ] = [-$half, -$half, -$half];
        [$spec->maxX, $spec->maxY, $spec->maxZ] = [$half, $half, $half];
        $placed = [];
        for ($i = 0; $i < $n; ++$i) {
            $r = mt_rand(3, 8) / 10;
            $clear = (int) (10 * ($half - $r - 0.1));   // a margin, so it never starts already touching a wall
            do {
                $x = mt_rand(-$clear, $clear) / 10;
                $y = mt_rand(-$clear, $clear) / 10;
                $z = mt_rand(-$clear, $clear) / 10;
                $free = true;
                foreach ($placed as [$ox, $oy, $oz, $or]) {
                    $free = $free && ($ox - $x) ** 2 + ($oy - $y) ** 2 + ($oz - $z) ** 2 > ($or + $r + 0.05) ** 2;
                }
            } while (!$free);
            $placed[] = [$x, $y, $z, $r];
            $spec->addBody($x, $y, $z, mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10, $r, mt_rand(5, 20) / 10, true, false);
        }
        return $spec;
    }

    /** A chain of $links spheres, each tethered to the next at $length, drifting with random velocities. */
    public static function tetheredChain(int $seed, int $links, float $length = 2.0): SceneSpec
    {
        mt_srand($seed);
        $spec = new SceneSpec();
        [$spec->minX, $spec->minY, $spec->minZ] = [-50.0, -50.0, -50.0];
        [$spec->maxX, $spec->maxY, $spec->maxZ] = [50.0, 50.0, 50.0];
        $prev = null;
        for ($i = 0; $i < $links; ++$i) {
            $b = $spec->addBody($i * $length * 0.9, 0.0, 0.0, mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10, 0.3, mt_rand(5, 15) / 10, true, false);
            if ($prev !== null) {
                $spec->addTether($prev, $b, $length);
            }
            $prev = $b;
        }
        return $spec;
    }

    /**
     * A compound: a root sphere spinning about a random axis, with $arms arms (an intermediate turning node and a
     * part at its tip), and free spheres flying through the sweep, one of them aimed to be sure to touch.
     */
    public static function compoundWithTurningArms(int $seed, int $arms = 2, int $freeBodies = 4, float $boundRadius = 5.0): SceneSpec
    {
        mt_srand($seed);
        $spec = new SceneSpec();
        [$spec->minX, $spec->minY, $spec->minZ] = [-30.0, -30.0, -30.0];
        [$spec->maxX, $spec->maxY, $spec->maxZ] = [30.0, 30.0, 30.0];
        $root = $spec->addBody(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, $boundRadius, 3.0, true, true, 0.0, 0.0, mt_rand(5, 15) / 10);
        $armLen = $boundRadius - 1.0;
        for ($i = 0; $i < $arms; ++$i) {
            $angle = 2 * M_PI * $i / $arms;
            $arm = $spec->addNode(true, $root, cos($angle) * ($armLen - 1.0), sin($angle) * ($armLen - 1.0), 0.0, 0.0, 0.0, 0.0, mt_rand(-10, 10) / 10);
            $spec->addNode(false, $arm, cos($angle), sin($angle), 0.0, 0.5);
        }
        // guaranteed hit: a sphere crossing the plane where arm 0's part sweeps, timed to arrive while it is there
        $spec->addBody(0.0, $armLen, 6.0, 0.0, 0.0, -6.0, 0.4, 1.0, true, false);
        for ($i = 0; $i < $freeBodies; ++$i) {
            $far = $boundRadius + 10.0;
            $axis = mt_rand(0, 2);
            $pos = [mt_rand(-200, 200) / 10, mt_rand(-200, 200) / 10, mt_rand(-200, 200) / 10];
            $pos[$axis] = $far * (mt_rand(0, 1) === 0 ? 1 : -1);
            $vel = [mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10];
            $vel[$axis] = -($pos[$axis] <=> 0) * (mt_rand(15, 30) / 10);
            $spec->addBody($pos[0], $pos[1], $pos[2], $vel[0], $vel[1], $vel[2], 0.3, mt_rand(5, 15) / 10, true, false);
        }
        return $spec;
    }

    /**
     * An open world: no walls, unconfined bodies, sparse over a large volume, radii log-uniform from 0.01 to 1000,
     * most drifting at a walking pace and a few "bullets" at 200+ units/second - the scales a general engine (not
     * just the demo's rocks-in-a-box) has to get right. One slow target and one bullet are aimed to be sure to meet.
     */
    public static function openWorld(int $seed, int $n, float $volume = 2000.0, int $bullets = 2): SceneSpec
    {
        mt_srand($seed);
        $spec = new SceneSpec();   // minX..maxZ stay +/-INF: unconfined, no walls
        $half = (int) ($volume * 5);
        $dir = static function (): array {
            $theta = mt_rand(0, 6283185) / 1000000.0;
            $z = mt_rand(-1000000, 1000000) / 1000000.0;
            $s = sqrt(max(0.0, 1.0 - $z * $z));
            return [$s * cos($theta), $s * sin($theta), $z];
        };
        for ($i = 0; $i < $n; ++$i) {
            $r = 0.01 * 100000.0 ** (mt_rand(0, 1000000) / 1000000);   // log-uniform, 0.01 to 1000
            [$x, $y, $z] = [mt_rand(-$half, $half) / 10.0, mt_rand(-$half, $half) / 10.0, mt_rand(-$half, $half) / 10.0];
            $speed = $i < $bullets ? mt_rand(2000, 4000) / 10.0 : mt_rand(5, 20) / 10.0;
            [$dx, $dy, $dz] = $dir();
            $spec->addBody($x, $y, $z, $dx * $speed, $dy * $speed, $dz * $speed, $r, mt_rand(5, 200) / 10.0, false, false);
        }
        $t = 1.2;
        $target = $spec->addBody(0.0, 0.0, 0.0, 0.5, -0.3, 0.2, 8.0, 10.0, false, false);
        [$dx, $dy, $dz] = $dir();
        $speed = 250.0;
        $futureX = $spec->bodyVX[$target] * $t;   // the target starts at the origin: its position at t is just v * t
        $futureY = $spec->bodyVY[$target] * $t;
        $futureZ = $spec->bodyVZ[$target] * $t;
        $spec->addBody($futureX - $dx * $speed * $t, $futureY - $dy * $speed * $t, $futureZ - $dz * $speed * $t, $dx * $speed, $dy * $speed, $dz * $speed, 0.2, 1.0, false, false);
        return $spec;
    }

    /**
     * A bullet (>= 500 units/s) crossing a tiny (radius 0.01) body: at a coarse step the gap is positive at both
     * ends of a window and the body is gone through in between - the tunnelling a fixed-step-only check would miss.
     * $graze true offsets it so the bullet only just clips the target instead of passing dead-centre through it.
     */
    public static function bulletThroughTinyBody(int $seed, bool $graze = false): SceneSpec
    {
        mt_srand($seed);
        $spec = new SceneSpec();
        $targetR = 0.01;
        $bulletR = 0.01;
        $spec->addBody(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, $targetR, 1.0, false, false);
        $speed = mt_rand(5000, 8000) / 10.0;
        $offset = $graze ? ($targetR + $bulletR) * 0.95 : 0.0;
        $spec->addBody(-100.0, $offset, 0.0, $speed, 0.0, 0.0, $bulletR, 1.0, false, false);
        return $spec;
    }

    /** A bullet crossing a thin (radius 0.05) part of a slowly spinning compound, dead-centre. */
    public static function bulletThroughThinCompoundPart(int $seed): SceneSpec
    {
        mt_srand($seed);
        $spec = new SceneSpec();
        $root = $spec->addBody(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 3.0, 5.0, false, true, 0.0, 0.0, 0.3);
        $spec->addNode(true, $root, 2.0, 0.0, 0.0, 0.05);
        $speed = mt_rand(5000, 8000) / 10.0;
        $spec->addBody(2.0, -100.0, 0.0, 0.0, $speed, 0.0, 0.01, 1.0, false, false);
        return $spec;
    }

    /** Two bullets (>= 500 units/s) on a collision course, closing at over 1000 units/second combined. */
    public static function twoBulletsCrossing(int $seed): SceneSpec
    {
        mt_srand($seed);
        $spec = new SceneSpec();
        $speed = mt_rand(5000, 8000) / 10.0;
        $spec->addBody(-100.0, 0.0, 0.0, $speed, 0.0, 0.0, 0.02, 1.0, false, false);
        $spec->addBody(100.0, 0.0, 0.0, -$speed, 0.0, 0.0, 0.02, 1.0, false, false);
        return $spec;
    }
}
