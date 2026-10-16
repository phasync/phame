<?php

namespace Phame\Tests;

use Phame\Events\Event;
use Phame\Physics\Contact;
use Phame\Physics\Wall;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\TestCase;

/**
 * Two Worlds, each with their own Scene3D, running at the same time in one phasync::run() (the server will soon do
 * this for real: the rocks demo and a solar-system game in the same worker process). What belongs to one world must
 * never leak into the other: see the audit this accompanies (EventDispatcher::$listening, Physics\Space's scratch
 * statics, World's own registries).
 */
final class TwoWorldsTest extends TestCase
{
    /**
     * A small box of free bodies, elastic, confined, deterministic from $seed; $gravity != 0 also gives each body
     * its own small acceleration (Phase 1), so the gravity scene exercises accelerating bodies too, not just the
     * root's field.
     *
     * @return array{World, EventLog}
     */
    private function buildScene(int $seed, float $gravity = 0.0): array
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-6.0, -6.0, -6.0];
        [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [6.0, 6.0, 6.0];
        $scene->root->gravityY = $gravity;
        $log = new EventLog();
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use ($log) {
            $log->events[] = [round($c->time, 9), spl_object_id($a), spl_object_id($b)];
            $j = $c->impulse(1.0);
            $ka = $c->sign($a) * $j / $a->mass;
            $a->setVelocity($a->velocityX + $ka * $c->nx, $a->velocityY + $ka * $c->ny, $a->velocityZ + $ka * $c->nz);
            $kb = $c->sign($b) * $j / $b->mass;
            $b->setVelocity($b->velocityX + $kb * $c->nx, $b->velocityY + $kb * $c->ny, $b->velocityZ + $kb * $c->nz);
        });
        $world->onCollision(function (Node3D $rock, Wall $wall, Contact $c) use ($log) {
            $log->events[] = [round($c->time, 9), spl_object_id($rock), 'wall' . $wall->axis . ($wall->side > 0 ? '+' : '-')];
            match ($wall->axis) {
                0 => $rock->velocityX = -$rock->velocityX,
                1 => $rock->velocityY = -$rock->velocityY,
                default => $rock->velocityZ = -$rock->velocityZ,
            };
        });
        mt_srand($seed);
        for ($i = 0; $i < 15; ++$i) {
            $rock = new Node3D();
            $rock->radius = 0.5;
            $rock->mass = mt_rand(5, 20) / 10;
            $rock->moveTo(mt_rand(-50, 50) / 10, mt_rand(-50, 50) / 10, mt_rand(-50, 50) / 10);
            $rock->setVelocity(mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10, mt_rand(-30, 30) / 10);
            if ($gravity != 0.0) {
                $rock->setAcceleration(0.0, 0.0, mt_rand(-5, 5) / 10);   // its own, on top of the root's field
            }
            $scene->root->addChild($rock);
        }
        return [$world, $log];
    }

    /** The ids in a log are spl_object_id()s, which differ between a solo run and the concurrent one: drop them. */
    private function shape(EventLog $log): array
    {
        return array_map(static fn ($e) => $e[0], $log->events);
    }

    public function testTwoWorldsRunningAtOnceMatchEachASoloRunExactly(): void
    {
        // the baselines: each scene alone, nothing else running
        [$solo1Log, $solo2Log] = [null, null];
        \phasync::run(function () use (&$solo1Log) {
            [$world, $log] = $this->buildScene(101, 0.0);
            $world->run(static function (World $w) {
                for ($i = 0; $i < 60; ++$i) {
                    $w->tick(0.05);
                }
            });
            $solo1Log = $log;
            $world->dispose();
        });
        \phasync::run(function () use (&$solo2Log) {
            [$world, $log] = $this->buildScene(202, -3.0);
            $world->run(static function (World $w) {
                for ($i = 0; $i < 60; ++$i) {
                    $w->tick(0.05);
                }
            });
            $solo2Log = $log;
            $world->dispose();
        });
        $this->assertNotEmpty($solo1Log->events, 'the box does collide');
        $this->assertNotEmpty($solo2Log->events, 'the gravity scene does collide');

        // the same two scenes, ticked at once: an enterFrame listener on world 1 only, none on world 2
        [$world1, $log1] = $this->buildScene(101, 0.0);
        [$world2, $log2] = $this->buildScene(202, -3.0);
        $frames1 = 0;
        $world1->stage->addEventListener(Event::ENTER_FRAME, function () use (&$frames1) {
            ++$frames1;
        });

        \phasync::run(function () use ($world1, $world2) {
            $fiber1 = \phasync::go(static function () use ($world1) {
                $world1->run(static function (World $w) {
                    for ($i = 0; $i < 60; ++$i) {
                        $w->tick(0.05);
                        \phasync::yield();   // hands control to world 2's fiber between every tick
                    }
                });
            });
            $fiber2 = \phasync::go(static function () use ($world2) {
                $world2->run(static function (World $w) {
                    for ($i = 0; $i < 60; ++$i) {
                        $w->tick(0.05);
                        \phasync::yield();
                    }
                });
            });
            \phasync::await($fiber1);
            \phasync::await($fiber2);
        });

        $this->assertSame(60, $frames1, 'world 1\'s own enterFrame listener fires once per its own tick');
        $this->assertSame($this->shape($solo1Log), $this->shape($log1), 'world 1, run alongside world 2, has exactly world 1\'s solo event times');
        $this->assertSame($this->shape($solo2Log), $this->shape($log2), 'world 2, run alongside world 1 (which has an enterFrame listener world 2 does not), has exactly world 2\'s solo event times');
        $world1->dispose();
        $world2->dispose();
    }

    public function testDisposingOneWorldMidRunLeavesTheOtherRunningAndCorrect(): void
    {
        $solo2Log = null;
        \phasync::run(function () use (&$solo2Log) {
            [$world, $log] = $this->buildScene(303, -2.0);
            $world->run(static function (World $w) {
                for ($i = 0; $i < 60; ++$i) {
                    $w->tick(0.05);
                }
            });
            $solo2Log = $log;
            $world->dispose();
        });
        $this->assertNotEmpty($solo2Log->events);

        [$world1, $log1] = $this->buildScene(404, 0.0);
        [$world2, $log2] = $this->buildScene(303, -2.0);

        \phasync::run(function () use ($world1, $world2) {
            $fiber1 = \phasync::go(static function () use ($world1) {
                $world1->run(static function (World $w) {
                    for ($i = 0; $i < 30; ++$i) {   // only half as long: disposed early, below
                        $w->tick(0.05);
                        \phasync::yield();
                    }
                });
            });
            $fiber2 = \phasync::go(static function () use ($world2) {
                $world2->run(static function (World $w) {
                    for ($i = 0; $i < 60; ++$i) {
                        $w->tick(0.05);
                        \phasync::yield();
                    }
                });
            });
            \phasync::await($fiber1);
            $world1->dispose();   // gone at t = 1.5 s; world 2 still has 1.5 s of its own 3 s left to run
            \phasync::await($fiber2);
        });

        $this->assertSame($this->shape($solo2Log), $this->shape($log2), 'world 2 is unaffected by world 1 disposing early');
    }
}

/** A collision handler closure captures this by value (an object), not the array it holds by reference. */
final class EventLog
{
    /** @var list<array{float, int, int|string}> */
    public array $events = [];
}
