<?php

namespace Phame\Tests\Verification;

use Phame\Physics\Contact;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 baseline: invariants of the engine checked on seeded random scenes (see docs/PHYSICS-PLAN.md, "Phase 0").
 * No walls here: a wall is an infinite mass, so it is deliberately excluded from the momentum check.
 */
final class InvariantsTest extends TestCase
{
    /** @return list<Node3D> */
    private function randomRocks(int $seed, int $n): array
    {
        mt_srand($seed);
        $rocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $rock = new Node3D();
            $rock->mass = mt_rand(5, 20) / 10;
            $rock->radius = 0.5;
            do {
                [$x, $y, $z] = [mt_rand(-100, 100) / 10, mt_rand(-100, 100) / 10, mt_rand(-100, 100) / 10];
                $free = true;
                foreach ($rocks as $o) {
                    $free = $free && (($o->x - $x) ** 2 + ($o->y - $y) ** 2 + ($o->z - $z) ** 2) > 1.1 ** 2;
                }
            } while (!$free);
            $rock->moveTo($x, $y, $z);
            $rock->setVelocity(mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10);
            $rocks[] = $rock;
        }
        return $rocks;
    }

    public function testTotalMomentumIsExactAcrossPairCollisions(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $rocks = $this->randomRocks(101, 30);
        foreach ($rocks as $r) {
            $scene->root->addChild($r);
        }
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) {
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $a->setVelocity($a->velocityX + $k * $c->nx, $a->velocityY + $k * $c->ny, $a->velocityZ + $k * $c->nz);
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $b->setVelocity($b->velocityX + $k * $c->nx, $b->velocityY + $k * $c->ny, $b->velocityZ + $k * $c->nz);
        });
        $momentum = static function () use ($rocks) {
            [$px, $py, $pz] = [0.0, 0.0, 0.0];
            foreach ($rocks as $r) {
                $px += $r->mass * $r->velocityX;
                $py += $r->mass * $r->velocityY;
                $pz += $r->mass * $r->velocityZ;
            }
            return [$px, $py, $pz];
        };
        $p0 = $momentum();
        \phasync::run(static fn () => $world->run(static function (World $w) {
            for ($i = 0; $i < 100; ++$i) {
                $w->tick(0.05);
            }
        }));
        $this->assertEqualsWithDelta($p0, $momentum(), 1e-7, 'elastic pair collisions conserve total momentum exactly');
        $world->dispose();
    }

    public function testEnergyIsConservedAtRestitutionOneAndNeverGainedBelowOne(): void
    {
        foreach ([1.0, 0.7] as $restitution) {
            $world = new World();
            $scene = $world->stage->addChild(new Scene3D(100, 100));
            $rocks = $this->randomRocks(202, 30);
            foreach ($rocks as $r) {
                $scene->root->addChild($r);
            }
            $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use ($restitution) {
                $k = $c->sign($a) * $c->impulse($restitution) / $a->mass;
                $a->setVelocity($a->velocityX + $k * $c->nx, $a->velocityY + $k * $c->ny, $a->velocityZ + $k * $c->nz);
                $k = $c->sign($b) * $c->impulse($restitution) / $b->mass;
                $b->setVelocity($b->velocityX + $k * $c->nx, $b->velocityY + $k * $c->ny, $b->velocityZ + $k * $c->nz);
            });
            $energy = static function () use ($rocks) {
                $e = 0.0;
                foreach ($rocks as $r) {
                    $e += 0.5 * $r->mass * ($r->velocityX ** 2 + $r->velocityY ** 2 + $r->velocityZ ** 2);
                }
                return $e;
            };
            $e0 = $energy();
            $worst = 0.0;
            \phasync::run(static fn () => $world->run(static function (World $w) use ($energy, $e0, &$worst) {
                for ($i = 0; $i < 100; ++$i) {
                    $w->tick(0.05);
                    $worst = max($worst, $energy() - $e0);   // positive: energy gained since the start
                }
            }));
            if ($restitution === 1.0) {
                $this->assertEqualsWithDelta($e0, $energy(), $e0 * 1e-7, 'restitution 1: energy is conserved');
            } else {
                $this->assertLessThan($e0 * 1e-9, $worst, "restitution $restitution: energy never rises above its start");
            }
            $world->dispose();
        }
    }

    public function testNoTwoSpheresEverPenetrateBeyondFloatingPointTolerance(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $rocks = $this->randomRocks(303, 30);
        foreach ($rocks as $r) {
            $scene->root->addChild($r);
        }
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) {
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $a->setVelocity($a->velocityX + $k * $c->nx, $a->velocityY + $k * $c->ny, $a->velocityZ + $k * $c->nz);
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $b->setVelocity($b->velocityX + $k * $c->nx, $b->velocityY + $k * $c->ny, $b->velocityZ + $k * $c->nz);
        });
        $worst = 0.0;
        \phasync::run(static fn () => $world->run(static function (World $w) use ($rocks, &$worst) {
            for ($tick = 0; $tick < 100; ++$tick) {
                $w->tick(0.05);
                for ($i = 0, $n = count($rocks); $i < $n; ++$i) {
                    for ($j = $i + 1; $j < $n; ++$j) {
                        $d = sqrt(($rocks[$i]->x - $rocks[$j]->x) ** 2 + ($rocks[$i]->y - $rocks[$j]->y) ** 2 + ($rocks[$i]->z - $rocks[$j]->z) ** 2);
                        $worst = max($worst, 1.0 - $d);   // positive: overlapping (radius 0.5 each)
                    }
                }
            }
        }));
        $this->assertLessThan(1e-6, $worst, 'no two spheres overlap at a sampled tick');
        $world->dispose();
    }

    public function testContactTimeOfAPairDoesNotDependOnTheOrderTheyWereAdded(): void
    {
        $times = [];
        foreach ([false, true] as $swapped) {
            $world = new World();
            $scene = $world->stage->addChild(new Scene3D(100, 100));
            $a = new Node3D();
            $a->radius = 1.0;
            $a->moveTo(-8.0, 0.2, 0.0);
            $a->setVelocity(2.0, 0.0, 0.0);
            $b = new Node3D();
            $b->radius = 1.0;
            $b->moveTo(8.0, -0.2, 0.0);
            $b->setVelocity(-2.0, 0.0, 0.0);
            foreach ($swapped ? [$b, $a] : [$a, $b] as $r) {
                $scene->root->addChild($r);
            }
            $hit = null;
            $world->onCollision(function (Node3D $x, Node3D $y, Contact $c) use (&$hit) {
                $hit = $c->time;
            });
            \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(20.0)));
            $times[] = $hit;
            $world->dispose();
        }
        $this->assertNotNull($times[0]);
        $this->assertEqualsWithDelta($times[0], $times[1], 1e-9, 'the time a pair touches does not depend on which is $a and which is $b');
    }

    public function testSameSeedGivesAnIdenticalEventLog(): void
    {
        $run = function () {
            $world = new World();
            $scene = $world->stage->addChild(new Scene3D(100, 100));
            $rocks = $this->randomRocks(404, 25);
            foreach ($rocks as $r) {
                $scene->root->addChild($r);
            }
            $index = [];
            foreach ($rocks as $i => $r) {
                $index[spl_object_id($r)] = $i;
            }
            $log = [];
            $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$log, $index) {
                $pair = [$index[spl_object_id($a)], $index[spl_object_id($b)]];
                sort($pair);
                $log[] = [round($c->time, 9), ...$pair];
                $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
                $a->setVelocity($a->velocityX + $k * $c->nx, $a->velocityY + $k * $c->ny, $a->velocityZ + $k * $c->nz);
                $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
                $b->setVelocity($b->velocityX + $k * $c->nx, $b->velocityY + $k * $c->ny, $b->velocityZ + $k * $c->nz);
            });
            \phasync::run(static fn () => $world->run(static function (World $w) {
                for ($i = 0; $i < 100; ++$i) {
                    $w->tick(0.05);
                }
            }));
            $world->dispose();
            return md5(serialize($log));
        };
        $this->assertSame($run(), $run(), 'the same seed produces the same event log (time and pair of every contact)');
    }
}
