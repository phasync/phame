<?php

namespace Phame\Tests;

use Phame\World;
use Phame\Physics\{Contact, Wall};
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
}
