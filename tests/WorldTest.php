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
}
