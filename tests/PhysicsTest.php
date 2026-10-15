<?php

namespace Phame\Tests;

use Phame\World;
use Phame\Physics\{Contact, Space, Wall};
use Phame\Scene\{Node3D, Scene3D};
use PHPUnit\Framework\TestCase;

/** A rock as a game would write it: its class decides how it reacts; the engine only reports contacts. */
final class TestRock extends Node3D
{
    public int $hits = 0;

    public function bounceOff(Contact $c, float $impulse, float $sign): void
    {
        $k = $sign * $impulse / $this->mass;
        $this->setVelocity($this->velocityX + $k * $c->nx, $this->velocityY + $k * $c->ny, $this->velocityZ + $k * $c->nz);
        ++$this->hits;
    }

    public function mirror(Wall $wall): void
    {
        match ($wall->axis) {
            0 => $this->velocityX = -$this->velocityX,
            1 => $this->velocityY = -$this->velocityY,
            default => $this->velocityZ = -$this->velocityZ,
        };
    }
}

final class PhysicsTest extends TestCase
{
    public function testRocksInABoxCollideWithoutOverlapAndConserveEnergy(): void
    {
        mt_srand(11);
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-20.0, -20.0, -20.0];
        [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [20.0, 20.0, 20.0];
        $world->onCollision(function (TestRock $a, TestRock $b, Contact $c) {
            $j = $c->impulse(1.0);
            $a->bounceOff($c, $j, $c->sign($a));
            $b->bounceOff($c, $j, $c->sign($b));
        });
        $world->onCollision(function (TestRock $rock, Wall $wall, Contact $c) {
            $rock->mirror($wall);
        });
        $rocks = [];
        for ($i = 0; $i < 150; ++$i) {
            $rock = new TestRock();
            $rock->mass = mt_rand(5, 20) / 10;
            do {   // a free place
                [$x, $y, $z] = [mt_rand(-180, 180) / 10, mt_rand(-180, 180) / 10, mt_rand(-180, 180) / 10];
                $free = true;
                foreach ($rocks as $o) {
                    $free = $free && (($o->x - $x) ** 2 + ($o->y - $y) ** 2 + ($o->z - $z) ** 2) > 2.2 ** 2;
                }
            } while (!$free);
            $rock->moveTo($x, $y, $z);
            $rock->setVelocity(mt_rand(-50, 50) / 10, mt_rand(-50, 50) / 10, mt_rand(-50, 50) / 10);
            $rock->radius = 1.0;
            $scene->root->addChild($rock);
            $rocks[] = $rock;
        }
        $energy = static function () use ($rocks) {
            $e = 0.0;
            foreach ($rocks as $r) {
                $e += 0.5 * $r->mass * ($r->velocityX ** 2 + $r->velocityY ** 2 + $r->velocityZ ** 2);
            }
            return $e;
        };
        $e0 = $energy();
        $worst = 0.0;
        \phasync::run(static fn () => $world->run(static function (World $w) use ($rocks, &$worst) {
            for ($tick = 0; $tick < 400; ++$tick) {   // 20 s at 20 ticks per second
                $w->tick(0.05);
                if ($tick % 10 !== 0) {
                    continue;   // the check below is every pair: sample it
                }
                for ($i = 0, $n = count($rocks); $i < $n; ++$i) {
                    $a = $rocks[$i];
                    for ($j = $i + 1; $j < $n; ++$j) {
                        $b = $rocks[$j];
                        $d = sqrt(($a->x - $b->x) ** 2 + ($a->y - $b->y) ** 2 + ($a->z - $b->z) ** 2);
                        $worst = max($worst, 2.0 - $d);
                    }
                }
            }
        }));
        $hits = array_sum(array_map(static fn ($r) => $r->hits, $rocks));
        $this->assertGreaterThan(200, $hits, 'they do collide');
        $this->assertLessThan(1e-6, $worst, 'no two rocks ever overlap at a tick');
        $this->assertEqualsWithDelta($e0, $energy(), $e0 * 1e-9, 'elastic: kinetic energy is conserved');
        foreach ($rocks as $r) {
            $this->assertTrue(abs($r->x) <= 19.000001 && abs($r->y) <= 19.000001 && abs($r->z) <= 19.000001, 'inside the box');
        }
        $world->dispose();
    }

    public function testLargeSpheresTouchingFromCellsApartCollide(): void
    {
        // radius 4 in cells of 4: they touch when their centres are 8 apart, from cells 0 and 2, which are no neighbours
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $hits = [];
        $world->onCollision(function (TestRock $a, TestRock $b, Contact $c) use (&$hits) {
            $hits[] = $c->time;
        });
        foreach ([[0.5, 1.0], [10.5, -1.0]] as [$x, $v]) {
            $ball = new TestRock();
            $ball->moveTo($x, 0.0, 0.0);
            $ball->setVelocity($v, 0.0, 0.0);
            $ball->radius = 4.0;
            $scene->root->addChild($ball);
        }
        \phasync::run(static fn () => $world->run(static function (World $w) {
            $w->tick(2.0);
        }));
        $this->assertCount(1, $hits);
        $this->assertEqualsWithDelta(1.0, $hits[0], 1e-9, 'they touch when 8 apart');
        $world->dispose();
    }

    public function testASmallSphereFindsALargeOneFarAcrossTheGrid(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $hits = 0;
        $world->onCollision(function (TestRock $a, TestRock $b, Contact $c) use (&$hits) {
            ++$hits;
        });
        $big = new TestRock();
        $big->radius = 9.0;
        $scene->root->addChild($big);
        $small = new TestRock();
        $small->moveTo(30.0, 0.5, 0.0);
        $small->setVelocity(-10.0, 0.0, 0.0);   // touches at x = 9.5, after 2.05 s
        $small->radius = 0.5;
        $scene->root->addChild($small);
        \phasync::run(static fn () => $world->run(static function (World $w) {
            $w->tick(3.0);
        }));
        $this->assertSame(1, $hits);
        $world->dispose();
    }

    public function testAnUnconfinedBodyGoesThroughTheWalls(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-5.0, -5.0, -5.0];
        [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [5.0, 5.0, 5.0];
        $walls = 0;
        $world->onCollision(function (TestRock $rock, Wall $wall, Contact $c) use (&$walls) {
            ++$walls;
            $rock->mirror($wall);
        });
        $ball = new TestRock();
        $ball->confined = false;
        $ball->setVelocity(10.0, 0.0, 0.0);
        $ball->radius = 1.0;
        $scene->root->addChild($ball);
        \phasync::run(static fn () => $world->run(static function (World $w) {
            $w->tick(2.0);
        }));
        $this->assertSame(0, $walls);
        $this->assertEqualsWithDelta(20.0, $ball->x, 1e-9);
        $world->dispose();
    }

    public function testATautTetherPullsTwoBodiesBack(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $tethers = 0;
        $world->onCollision(function (TestRock $a, TestRock $b, Contact $c) use (&$tethers) {
            $j = $c->impulse(1.0);
            $a->bounceOff($c, $j, $c->sign($a));
            $b->bounceOff($c, $j, $c->sign($b));
            $tethers += $c->tether ? 1 : 0;
        });
        $a = new TestRock();
        $b = new TestRock();
        foreach ([[$a, -1.0], [$b, 1.0]] as [$rock, $side]) {
            $rock->moveTo($side, 0.0, 0.0);
            $rock->setVelocity($side, 0.3, 0.0);   // apart, and drifting: the pair moves on as it bounces
            $rock->radius = 0.5;
            $scene->root->addChild($rock);
        }
        $world->physics($scene)->tether($a, $b, 5.0);
        $worst = 0.0;
        \phasync::run(static fn () => $world->run(static function (World $w) use ($a, $b, &$worst) {
            for ($i = 0; $i < 200; ++$i) {
                $w->tick(0.05);
                $worst = max($worst, sqrt(($b->x - $a->x) ** 2 + ($b->y - $a->y) ** 2 + ($b->z - $a->z) ** 2));
            }
        }));
        $this->assertGreaterThan(2, $tethers, 'held more than once: apart, together, apart');
        $this->assertLessThanOrEqual(5.0 + 1e-6, $worst, 'never further apart than the tether');
        $this->assertEqualsWithDelta(0.3, $a->velocityY, 1e-9, 'the pull is along the tether only');
        $this->assertEqualsWithDelta(2.0 * 1.0 ** 2, $a->velocityX ** 2 + $b->velocityX ** 2, 1e-9, 'an elastic hold keeps the energy');
        $world->dispose();
    }

    public function testATetheredChainKeepsItsLengthsWithoutRunningAway(): void
    {
        mt_srand(5);
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $events = 0;
        $world->onCollision(function (TestRock $a, TestRock $b, Contact $c) use (&$events) {
            $j = $c->impulse(1.0);
            $a->bounceOff($c, $j, $c->sign($a));
            $b->bounceOff($c, $j, $c->sign($b));
            ++$events;
        });
        $chain = [];
        for ($i = 0; $i < 5; ++$i) {
            $rock = new TestRock();
            $rock->moveTo($i * 2.0, 0.0, 0.0);
            $rock->setVelocity(mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10);
            $rock->radius = 0.4;
            $scene->root->addChild($rock);
            if ($chain !== []) {
                $world->physics($scene)->tether(end($chain), $rock, 2.1);
            }
            $chain[] = $rock;
        }
        $worst = 0.0;
        \phasync::run(static fn () => $world->run(static function (World $w) use ($chain, &$worst) {
            for ($i = 0; $i < 200; ++$i) {
                $w->tick(0.05);
                for ($k = 1; $k < 5; ++$k) {
                    [$p, $q] = [$chain[$k - 1], $chain[$k]];
                    $worst = max($worst, sqrt(($q->x - $p->x) ** 2 + ($q->y - $p->y) ** 2 + ($q->z - $p->z) ** 2));
                }
            }
        }));
        $this->assertLessThanOrEqual(2.1 + 1e-6, $worst);
        $this->assertGreaterThan(10, $events);
        $this->assertLessThan(20000, $events, 'a few hundred per second at most, not an endless run of instant events');
        $world->dispose();
    }

    public function testEveryChangeInATickIsPublishedWithItsOwnDescription(): void
    {
        // a ball between walls 1 apart at 10 per second turns every 0.1 s: one tick of 0.25 s holds three, and a
        // viewer needs both, each at its moment, or it draws the old path past the first turn and then jumps
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-1.0, -10.0, -10.0];
        [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [1.0, 10.0, 10.0];
        $world->onCollision(function (TestRock $rock, Wall $wall, Contact $c) {
            $rock->mirror($wall);
        });
        $ball = new TestRock();
        $ball->radius = 0.5;
        $scene->root->addChild($ball);
        $ball->published = true;
        $ball->setVelocity(10.0, 0.0, 0.0);
        $changes = [];
        \phasync::run(static function () use ($world, &$changes) {
            $world->run(static function (World $w) use (&$changes) {
                $w->takeChangedDescriptions();   // the start
                $w->tick(0.25);
                $changes = $w->takeChangedDescriptions();
            });
        });
        $this->assertCount(3, $changes);
        $this->assertEqualsWithDelta([0.05, 0.15, 0.25], array_map(static fn ($c) => $c[1][0], $changes), 1e-9, 'each turn at its moment');
        $this->assertSame([-10.0, 10.0, -10.0], array_map(static fn ($c) => $c[1][4], $changes), 'with the velocity it turned to');
        $world->dispose();
    }

    public function testARockHitsThePartOfATurningCompoundWhenItTouches(): void
    {
        // a compound: a root spinning about z, with an arm whose part sits 4 out along x; a rock comes in along y
        // where the part will sweep past. The contact must come when the part touches, not when the rock reaches
        // the bounding sphere, and with the part's world velocity (omega x r) in it
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $hits = [];
        $gap = null;
        $world->onCollision(function (TestRock $part, TestRock $rock, Contact $c) use (&$hits, &$gap) {
            $hits[] = $c;
            $m = $part->getWorldMatrix();
            $gap = sqrt(($rock->x - $m->m12) ** 2 + ($rock->y - $m->m13) ** 2 + ($rock->z - $m->m14) ** 2) - 1.0;
        });
        $root = new TestRock();
        $root->compound = true;
        $root->radius = 5.0;
        $root->spin(0.0, 0.0, 1.0);   // 1 rad/s: the part at (4, 0, 0) moves along +y at 4 units per second
        $part = new TestRock();
        $part->radius = 0.5;
        $part->moveTo(4.0, 0.0, 0.0);
        $root->addChild($part);
        $scene->root->addChild($root);
        $rock = new TestRock();
        $rock->radius = 0.5;
        $rock->moveTo(0.0, 20.0, 0.0);
        $rock->setVelocity(0.0, -10.0, 0.0);
        $scene->root->addChild($rock);
        \phasync::run(static function () use ($world, $part, $rock, &$hits) {
            $world->run(static function (World $w) use ($part, $rock, &$hits) {
                while ($hits === [] && $w->clock->time < 3.0) {
                    $w->tick(0.01);
                }
            });
        });
        $this->assertCount(1, $hits);
        $c = $hits[0];
        $this->assertSame($part, $c->a, 'the part that touched, not the compound');
        $this->assertGreaterThan(Space::PROBE_TOLERANCE, $gap, 'when it touched: in the contact zone');
        $this->assertLessThanOrEqual(2.0 * Space::PROBE_TOLERANCE, $gap);
        $this->assertLessThan(0.0, $c->normalSpeed, 'closing in');
        $world->dispose();
    }
}
