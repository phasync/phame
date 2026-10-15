<?php

namespace Phame\Tests;

use Phame\{Async, World};
use Phame\Display\Quad;
use PHPUnit\Framework\TestCase;

/** Worlds without an engine: no window, no GL. This is how a server runs many games in one process. */
final class WorldTest extends TestCase
{
    public function testTwoWorldsRunSideBySideWithTheirOwnTimeCoroutinesAndMotion(): void
    {
        $a = new World(400, 300);
        $b = new World(400, 300);
        $log = [];
        $drive = static function (World $world, string $name, float $tick, int $ticks) use (&$log): \Closure {
            return static function () use ($world, $name, $tick, $ticks, &$log) {
                $world->run(static function (World $world) use ($name, $tick, $ticks, &$log) {
                    $ship = $world->stage->addChild(new Quad(4, 4));
                    $ship->go(static function () use ($ship, $world, $name, &$log) {
                        $log[] = "$name start in " . (World::current() === $world ? 'own world' : 'WRONG world');
                        Async::wait(0.2);
                        $ship->velocityX = 100;
                        Async::until(static fn () => $ship->x >= 9.999);   // positions are computed from time: allow float rounding
                        $log[] = sprintf('%s arrived at t=%.2f', $name, $world->clock->time);
                    });
                    for ($i = 0; $i < $ticks; ++$i) {
                        $world->tick($tick);
                        \phasync::sleep(0);   // let the other world's driver run between ticks
                    }
                });
            };
        };
        \phasync::run(static function () use ($a, $b, $drive) {
            \phasync::go($drive($a, 'a', 0.04, 15));   // 25 ticks a second
            \phasync::go($drive($b, 'b', 0.1, 6));     // 10 ticks a second
        });
        sort($log);
        // a's coroutine starts during its first tick, so its 0.2 s wait runs from t=0.04
        $this->assertSame(['a arrived at t=0.36', 'a start in own world', 'b arrived at t=0.40', 'b start in own world'], $log);
        $a->dispose();
        $b->dispose();
    }

    public function testAFailureStopsOnlyItsOwnWorld(): void
    {
        $a = new World();
        $b = new World();
        $bTicks = 0;
        \phasync::run(static function () use ($a, $b, &$bTicks) {
            \phasync::go(static fn () => $a->run(static function (World $w) {
                $w->go(static function () { Async::awaitFrame(); throw new \RuntimeException('boom in a'); });
                while (!$w->stopped) {
                    $w->tick(0.1);
                    \phasync::sleep(0);
                }
            }));
            \phasync::go(static function () use ($b, &$bTicks) {
                $b->run(static function (World $w) use (&$bTicks) {
                    for ($i = 0; $i < 5; ++$i, ++$bTicks) {
                        $w->tick(0.1);
                        \phasync::sleep(0);
                    }
                });
            });
        });
        $this->assertSame('boom in a', $a->takeFailure()?->getMessage());
        $this->assertNull($b->takeFailure());
        $this->assertSame(5, $bTicks, 'b kept running');
        $a->dispose();
        $b->dispose();
    }

    public function testSyncStartsWhatOutsideCodeChangesAtTheRealTime(): void
    {
        // between events runEvented() sleeps and the clock stands still: a coroutine woken from outside (here a
        // phasync timer, in a game a player's message) must catch it up first, or its changes start in the past
        $world = new World();
        $scene = $world->stage->addChild(new \Phame\Scene\Scene3D(100, 100));
        $added = null;
        $published = [];
        \phasync::run(static function () use ($world, $scene, &$added, &$published) { $world->run(static function (World $w) use ($scene, &$added, &$published) {
            $w->go(static function () use ($w, $scene, &$added) {
                \phasync::sleep(0.2);
                $w->sync();
                $node = new \Phame\Scene\Node3D();
                $node->moveTo(1.0, 0.0, 0.0);
                $node->setVelocity(10.0, 0.0, 0.0);
                $node->published = true;
                $scene->root->addChild($node);
                $added = $node->describe();
                \phasync::sleep(0.1);
                $w->stop();
            });
            $w->runEvented(static function (World $w) use (&$published) {
                foreach ($w->takeChangedDescriptions() as [$node, $description]) {
                    $published[] = $description;
                }
            });
        }); });
        $this->assertGreaterThan(0.19, $added[0], 'it starts now, not at the clock of the last event');
        foreach ($published as $d) {   // every description the world sent says where it is at the same moment
            $this->assertEqualsWithDelta($added[1] + 10.0 * (0.5 - $added[0]), $d[1] + $d[4] * (0.5 - $d[0]), 1e-9);
        }
        $world->dispose();
    }

    public function testSyncDoesNotRunTheClockAhead(): void
    {
        // the driver and outside code wake together (a wall contact every 10 ms, and a coroutine on the same 10 ms):
        // a catch-up must count its stretch of real time once, even when the driver ticks right behind it
        $world = new World();
        $scene = $world->stage->addChild(new \Phame\Scene\Scene3D(100, 100));
        [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-1.0, -10.0, -10.0];
        [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [1.0, 10.0, 10.0];
        $world->onCollision(function (\Phame\Scene\Node3D $ball, \Phame\Physics\Wall $wall, \Phame\Physics\Contact $c) {
            $ball->setVelocity(-$ball->velocityX, 0.0, 0.0);
        });
        $ball = new \Phame\Scene\Node3D();
        $ball->radius = 0.5;
        $scene->root->addChild($ball);
        $ball->setVelocity(100.0, 0.0, 0.0);   // 1 across at 100 per second: a wall every 10 ms
        $drift = null;
        \phasync::run(static function () use ($world, &$drift) {
            $world->run(static function (World $w) use (&$drift) {
                $w->go(static function () use ($w, &$drift) {
                    $start = hrtime(true) / 1e9 - $w->clock->time;
                    for ($i = 0; $i < 50; ++$i) {
                        \phasync::sleep(0.01);
                        $w->sync();
                    }
                    $drift = $w->clock->time - (hrtime(true) / 1e9 - $start);
                    $w->stop();
                });
                $w->runEvented();
            });
        });
        $this->assertEqualsWithDelta(0.0, $drift, 0.005, 'game time is real time');
        $world->dispose();
    }
}
