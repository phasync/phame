<?php

namespace Phame\Tests\Verification;

use Phame\Physics\Contact;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 baseline: the same scene, changed in ways that should not change what happens, gives the same answer (see
 * docs/PHYSICS-PLAN.md, "Phase 0", "Metamorphic tests"). No walls: offsetting and rotating a box is extra machinery
 * that is not the point of these checks.
 */
final class MetamorphicTest extends TestCase
{
    /** @return list<array{float, float, float, float, float, float, float}> x, y, z, vx, vy, vz, mass */
    private function randomConditions(int $seed, int $n): array
    {
        mt_srand($seed);
        $out = [];
        foreach (range(1, $n) as $_) {
            do {
                [$x, $y, $z] = [mt_rand(-50, 50) / 10, mt_rand(-50, 50) / 10, mt_rand(-50, 50) / 10];
                $free = true;
                foreach ($out as [$ox, $oy, $oz]) {
                    $free = $free && (($ox - $x) ** 2 + ($oy - $y) ** 2 + ($oz - $z) ** 2) > 1.1 ** 2;
                }
            } while (!$free);
            $out[] = [$x, $y, $z, mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10, mt_rand(5, 20) / 10];
        }
        return $out;
    }

    /** @param list<array{float, float, float, float, float, float, float}> $conditions */
    private function buildScene(array $conditions, Scene3D $scene): array
    {
        $rocks = [];
        foreach ($conditions as [$x, $y, $z, $vx, $vy, $vz, $mass]) {
            $r = new Node3D();
            $r->radius = 0.5;
            $r->mass = $mass;
            $r->moveTo($x, $y, $z);
            $r->setVelocity($vx, $vy, $vz);
            $scene->root->addChild($r);
            $rocks[] = $r;
        }
        return $rocks;
    }

    /** Runs the scene, bouncing elastically, and returns the event log: [time, i, j] per contact, sorted by time. */
    private function simulate(World $world, array $rocks, int $ticks = 80, float $dt = 0.05): array
    {
        $index = [];
        foreach ($rocks as $i => $r) {
            $index[spl_object_id($r)] = $i;
        }
        $log = [];
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$log, $index) {
            $pair = [$index[spl_object_id($a)], $index[spl_object_id($b)]];
            sort($pair);
            $log[] = [$c->time, ...$pair];
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $a->setVelocity($a->velocityX + $k * $c->nx, $a->velocityY + $k * $c->ny, $a->velocityZ + $k * $c->nz);
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $b->setVelocity($b->velocityX + $k * $c->nx, $b->velocityY + $k * $c->ny, $b->velocityZ + $k * $c->nz);
        });
        \phasync::run(static function () use ($world, $ticks, $dt) {
            $world->run(static function (World $w) use ($ticks, $dt) {
                for ($i = 0; $i < $ticks; ++$i) {
                    $w->tick($dt);
                }
            });
        });
        return $log;
    }

    public function testShiftingTheWholeSceneLeavesTimesUnchangedAndPositionsShifted(): void
    {
        [$dx, $dy, $dz] = [37.5, -22.25, 105.0];
        $base = $this->randomConditions(11, 30);
        $shifted = array_map(static fn ($c) => [$c[0] + $dx, $c[1] + $dy, $c[2] + $dz, $c[3], $c[4], $c[5], $c[6]], $base);

        $w1 = new World();
        $rocks1 = $this->buildScene($base, $w1->stage->addChild(new Scene3D(100, 100)));
        $log1 = $this->simulate($w1, $rocks1);
        $w2 = new World();
        $rocks2 = $this->buildScene($shifted, $w2->stage->addChild(new Scene3D(100, 100)));
        $log2 = $this->simulate($w2, $rocks2);

        $this->assertNotEmpty($log1, 'the scene does collide');
        $this->assertEqualsWithDelta($log1, $log2, 1e-9, 'a shift changes nothing about when or who touches');
        foreach ($rocks1 as $i => $r) {
            $this->assertEqualsWithDelta($r->x + $dx, $rocks2[$i]->x, 1e-7);
            $this->assertEqualsWithDelta($r->y + $dy, $rocks2[$i]->y, 1e-7);
            $this->assertEqualsWithDelta($r->z + $dz, $rocks2[$i]->z, 1e-7);
        }
        $w1->dispose();
        $w2->dispose();
    }

    public function testBoostingEveryBodyByTheSameVelocityLeavesTimesUnchanged(): void
    {
        [$vx, $vy, $vz] = [4.0, -3.0, 2.0];
        $base = $this->randomConditions(12, 30);
        $boosted = array_map(static fn ($c) => [$c[0], $c[1], $c[2], $c[3] + $vx, $c[4] + $vy, $c[5] + $vz, $c[6]], $base);

        $w1 = new World();
        $rocks1 = $this->buildScene($base, $w1->stage->addChild(new Scene3D(100, 100)));
        $log1 = $this->simulate($w1, $rocks1);
        $w2 = new World();
        $rocks2 = $this->buildScene($boosted, $w2->stage->addChild(new Scene3D(100, 100)));
        $log2 = $this->simulate($w2, $rocks2);

        $this->assertNotEmpty($log1);
        $this->assertEqualsWithDelta($log1, $log2, 1e-9, 'a common boost changes nothing about when or who touches');
        $t = 80 * 0.05;
        foreach ($rocks1 as $i => $r) {
            $this->assertEqualsWithDelta($r->x + $vx * $t, $rocks2[$i]->x, 1e-6);
            $this->assertEqualsWithDelta($r->velocityX + $vx, $rocks2[$i]->velocityX, 1e-9);
        }
        $w1->dispose();
        $w2->dispose();
    }

    public function testRotatingTheWholeSceneNinetyDegreesLeavesTimesUnchanged(): void
    {
        // (x, y, z, vx, vy, vz) -> (-y, x, z, -vy, vx, vz): a 90 degree turn about z
        $base = $this->randomConditions(13, 30);
        $rotated = array_map(static fn ($c) => [-$c[1], $c[0], $c[2], -$c[4], $c[3], $c[5], $c[6]], $base);

        $w1 = new World();
        $rocks1 = $this->buildScene($base, $w1->stage->addChild(new Scene3D(100, 100)));
        $log1 = $this->simulate($w1, $rocks1);
        $w2 = new World();
        $rocks2 = $this->buildScene($rotated, $w2->stage->addChild(new Scene3D(100, 100)));
        $log2 = $this->simulate($w2, $rocks2);

        $this->assertNotEmpty($log1);
        $this->assertEqualsWithDelta($log1, $log2, 1e-9, 'a rotation changes nothing about when or who touches');
        foreach ($rocks1 as $i => $r) {
            $this->assertEqualsWithDelta(-$r->y, $rocks2[$i]->x, 1e-7);
            $this->assertEqualsWithDelta($r->x, $rocks2[$i]->y, 1e-7);
            $this->assertEqualsWithDelta($r->z, $rocks2[$i]->z, 1e-7);
        }
        $w1->dispose();
        $w2->dispose();
    }

    public function testAStillPartUnderAMovingCompoundBehavesLikeAFreeBodyAtTheSameWorldVelocity(): void
    {
        // a compound moving at (2, 0, 0), not spinning, with a part held still at local (3, 0, 0): the part's world
        // motion is uniform velocity (2, 0, 0) from world position (3, 0, 0), same as a free body given directly.
        // The rock is offset in x (3.3, not 3.0): dead centre with no rotation lands the probe exactly on
        // Space::PROBE_TOLERANCE every time (see EdgeCaseTest's grazing-compound baseline-failure) and is missed.
        $incoming = static fn (Scene3D $scene) => (static function () use ($scene) {
            $rock = new Node3D();
            $rock->radius = 0.5;
            $rock->moveTo(3.3, 10.0, 0.0);
            $rock->setVelocity(2.0, -2.0, 0.0);   // matches the compound's x velocity: closes mostly along y, as the free-body twin does
            $scene->root->addChild($rock);
            return $rock;
        })();

        $w1 = new World();
        $scene1 = $w1->stage->addChild(new Scene3D(100, 100));
        $root = new Node3D();
        $root->compound = true;
        $root->radius = 5.0;
        $root->setVelocity(2.0, 0.0, 0.0);
        $part = new Node3D();
        $part->radius = 0.5;
        $part->moveTo(3.0, 0.0, 0.0);
        $root->addChild($part);
        $scene1->root->addChild($root);
        $rock1 = $incoming($scene1);
        $hit1 = null;
        $w1->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hit1) {
            $hit1 = [$c->time, $c->normalSpeed];
        });
        \phasync::run(static function () use ($w1, $part, $rock1, &$hit1) {
            $w1->run(static function (World $w) use ($part, $rock1, &$hit1) {
                while ($hit1 === null && $w->clock->time < 10.0) {
                    $w->tick(0.01);
                }
            });
        });

        $w2 = new World();
        $scene2 = $w2->stage->addChild(new Scene3D(100, 100));
        $free = new Node3D();
        $free->radius = 0.5;
        $free->moveTo(3.0, 0.0, 0.0);
        $free->setVelocity(2.0, 0.0, 0.0);
        $scene2->root->addChild($free);
        $rock2 = $incoming($scene2);
        $hit2 = null;
        $w2->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hit2) {
            $hit2 = [$c->time, $c->normalSpeed];
        });
        \phasync::run(static function () use ($w2, $free, $rock2, &$hit2) {
            $w2->run(static function (World $w) use ($free, $rock2, &$hit2) {
                while ($hit2 === null && $w->clock->time < 10.0) {
                    $w->tick(0.01);
                }
            });
        });

        $this->assertNotNull($hit1);
        // the compound's probing engine only resolves the part's distance to Space::PROBE_TOLERANCE, unlike the
        // closed-form sphere-sphere test above: a time slack of a probe step (a few milliseconds) is expected
        $this->assertEqualsWithDelta($hit1[0], $hit2[0], 0.03, 'a still part under a moving compound touches when a free body at the same world velocity would');
        $this->assertEqualsWithDelta($hit1[1], $hit2[1], 0.05, 'and with about the same closing speed');
        $w1->dispose();
        $w2->dispose();
    }

    public function testRedescribingBodiesMidFlightToTheirCurrentStateChangesNothing(): void
    {
        $conditions = $this->randomConditions(14, 16);
        $w1 = new World();
        $rocks1 = $this->buildScene($conditions, $w1->stage->addChild(new Scene3D(100, 100)));
        $this->simulate($w1, $rocks1, ticks: 80);

        $w2 = new World();
        $rocks2 = $this->buildScene($conditions, $w2->stage->addChild(new Scene3D(100, 100)));
        $index = [];
        foreach ($rocks2 as $i => $r) {
            $index[spl_object_id($r)] = $i;
        }
        $w2->onCollision(function (Node3D $a, Node3D $b, Contact $c) {
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $a->setVelocity($a->velocityX + $k * $c->nx, $a->velocityY + $k * $c->ny, $a->velocityZ + $k * $c->nz);
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $b->setVelocity($b->velocityX + $k * $c->nx, $b->velocityY + $k * $c->ny, $b->velocityZ + $k * $c->nz);
        });
        \phasync::run(static function () use ($w2, $rocks2) {
            $w2->run(static function (World $w) use ($rocks2) {
                for ($i = 0; $i < 40; ++$i) {
                    $w->tick(0.05);
                }
                foreach ($rocks2 as $r) {   // re-described at a moment between events: no collision is happening now
                    [$x, $y, $z, $vx, $vy, $vz] = [$r->x, $r->y, $r->z, $r->velocityX, $r->velocityY, $r->velocityZ];
                    $r->moveTo($x, $y, $z);   // zeroes the velocity: read it first, or setVelocity() below gets 0
                    $r->setVelocity($vx, $vy, $vz);
                }
                for ($i = 0; $i < 40; ++$i) {
                    $w->tick(0.05);
                }
            });
        });

        foreach ($rocks1 as $i => $r) {
            $this->assertEqualsWithDelta($r->x, $rocks2[$i]->x, 1e-7);
            $this->assertEqualsWithDelta($r->y, $rocks2[$i]->y, 1e-7);
            $this->assertEqualsWithDelta($r->z, $rocks2[$i]->z, 1e-7);
            $this->assertEqualsWithDelta($r->velocityX, $rocks2[$i]->velocityX, 1e-7);
        }
        $w1->dispose();
        $w2->dispose();
    }
}
