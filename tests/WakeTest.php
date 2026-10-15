<?php

namespace Phame\Tests;

use Phame\{Async, World};
use Phame\Display\{Quad, Sprite};
use Phame\Scene\{Node3D, Scene3D};
use Phame\Wake\Wake;
use Phame\Animation\Transitions;
use PHPUnit\Framework\TestCase;

/** DisplayObject::wait(): coroutines parked in slots, woken by the world manager. No engine, no GL. */
final class WakeTest extends TestCase
{
    private function runWorld(World $world, int $ticks, \Closure $setup, float $dt = 0.1): void
    {
        \phasync::run(static fn () => $world->run(static function (World $w) use ($ticks, $setup, $dt) {
            $setup($w);
            for ($i = 0; $i < $ticks && !$w->stopped; ++$i) {
                $w->tick($dt);
            }
        }));
        if ($f = $world->takeFailure()) {
            throw $f;
        }
    }

    public function testDeadlineInGameTimeReturnsNull(): void
    {
        $world = new World(200, 200);
        $log = [];
        $this->runWorld($world, 12, static function (World $w) use (&$log) {
            $q = $w->stage->addChild(new Quad(2, 2));
            $q->go(static function () use ($q, $w, &$log) {
                $start = $w->clock->time;
                $log[] = $q->wait(0.5);
                $log[] = round($w->clock->time - $start, 2);
            });
        });
        $this->assertSame([null, 0.5], $log);
        $world->dispose();
    }

    public function testCollisionWakesWithTheConditionAndContactAndNotBefore(): void
    {
        $world = new World(400, 400);
        $log = [];
        $this->runWorld($world, 30, static function (World $w) use (&$log) {
            $enemies = $w->stage->addChild(new Sprite());
            $enemy = $enemies->addChild(new Quad(20, 20));
            $enemy->y = 50;
            $enemy->name = 'enemy';
            $bullet = $w->stage->addChild(new Quad(4, 4));
            $bullet->y = 200;
            $wake = Wake::any(Wake::collision($enemies), Wake::leaveWorld());
            $bullet->go(static function () use ($bullet, $wake, $w, &$log) {
                $bullet->velocityY = -500;   // 50 points per tick: reaches y < 70 after 3 ticks
                $why = $bullet->wait(5.0, $wake);
                $log[] = [$why === Wake::collision($bullet->contact?->parent) ? 'collision' : 'other', $bullet->contact?->name, round($w->clock->time, 1)];
            });
        });
        $this->assertSame([['collision', 'enemy', 0.4]], $log);
        $world->dispose();
    }

    public function testLeaveWorldAndProximity(): void
    {
        $world = new World(100, 100);
        $log = [];
        $this->runWorld($world, 40, static function (World $w) use (&$log) {
            $players = $w->stage->addChild(new Sprite());
            $player = $players->addChild(new Quad(10, 10));
            $player->x = 50;
            $mine = $w->stage->addChild(new Quad(4, 4));
            $mine->x = 20;
            $mine->go(static function () use ($mine, $players, &$log) {
                $log[] = $mine->wait(10.0, Wake::proximity(15, $players)) === Wake::proximity(15, $players) ? 'near' : 'timeout';
            });
            $player->go(static function () use ($player, &$log) {
                $player->velocityX = -50;           // drifts towards the mine, then out of the world
                $log[] = $player->wait(10.0, Wake::leaveWorld()) === Wake::leaveWorld() ? 'left' : 'timeout';
            });
        });
        $this->assertSame(['near', 'left'], $log);
        $world->dispose();
    }

    public function testCancellingTheOwnerWhileWaitingCleansUp(): void
    {
        $world = new World(100, 100);
        $after = null;
        $this->runWorld($world, 10, static function (World $w) use (&$after) {
            $q = $w->stage->addChild(new Quad(2, 2));
            $q->go(static function () use ($q, &$after) {
                try {
                    $q->wait(100.0, Wake::leaveWorld());
                } finally {
                    $after = 'finally ran';
                }
            });
            $w->go(static function () use ($q) {
                Async::awaitFrame(3);
                $q->removeFromParent();
            });
        });
        $this->assertSame('finally ran', $after);
        $world->dispose();
    }

    public function testFactoriesReturnSharedInstances(): void
    {
        $g = new Sprite();
        $this->assertSame(Wake::collision($g), Wake::proximity(0, $g));
        $this->assertSame(Wake::any(Wake::collision($g), Wake::leaveWorld()), Wake::any(Wake::collision($g), Wake::leaveWorld()));
        $this->assertNotSame(Wake::proximity(5, $g), Wake::proximity(6, $g));
    }

    public function testThreeDimensionalProximityAndLeavingTheSceneBox(): void
    {
        $world = new World(100, 100);
        $log = [];
        $this->runWorld($world, 40, static function (World $w) use (&$log) {
            $scene = $w->stage->addChild(new Scene3D(100, 100));
            $scene->worldMinZ = -20.0;
            $targets = $scene->root->addChild(new Node3D());
            $target = $targets->addChild(new Node3D());
            $target->moveTo(0, 0, -10);
            $target->name = 'target';
            $probe = $scene->root->addChild(new Node3D());
            $probe->go(static function () use ($probe, $targets, $w, &$log) {
                $probe->setVelocity(0, 0, -20);   // 2 units per tick towards the target, then out of the box at z = -20
                $why = $probe->wait(10.0, Wake::any(Wake::proximity(1.0, $targets), Wake::leaveWorld()));
                $log[] = [$why === Wake::proximity(1.0, $targets) ? 'near' : 'other', $probe->contact?->name, round($w->clock->time, 2)];
                $why = $probe->wait(10.0, Wake::leaveWorld());
                $log[] = [$why === Wake::leaveWorld() ? 'left' : 'timeout', round($probe->z, 1)];
            });
        });
        // the coroutine starts in the first tick (t = 0.1): within 1 unit of z = -10 at t = 0.55, woken at the next
        // tick; a point leaves the box when it reaches its edge, z = -20
        $this->assertSame([['near', 'target', 0.6], ['left', -20.0]], $log);
        $world->dispose();
    }

    public function testPositionTweensAreStraightSegmentsDrivenByTheCoroutine(): void
    {
        $world = new World(400, 400);
        $samples = [];
        $done = null;
        $this->runWorld($world, 30, static function (World $w) use (&$samples, &$done) {
            $q = $w->stage->addChild(new Quad(2, 2));
            $q->go(static function () use ($q, $w, &$done) {
                Async::tween($q, 1.0, ['x' => 100.0, 'alpha' => 0.5], Transitions::EASE_IN, 4.0);   // 4 segments
                $done = [round($q->x, 6), $q->velocityX, round($w->clock->time, 2)];
            });
            $w->go(static function () use ($q, &$samples) {
                for ($i = 0; $i < 14; ++$i) {
                    Async::awaitFrame();
                    $samples[] = $q->velocityX;
                }
            });
        });
        $this->assertSame([100.0, 0.0, 1.1], $done, 'exactly there and stopped; started in the first tick');
        $changes = 0;
        for ($i = 1; $i < count($samples); ++$i) {
            $changes += $samples[$i] != $samples[$i - 1] ? 1 : 0;
        }
        $this->assertLessThanOrEqual(5, $changes, 'the velocity changes only between segments');
        $speeds = array_values(array_unique(array_filter($samples)));
        $this->assertGreaterThan($speeds[0], $speeds[count($speeds) - 1], 'ease-in: later segments are faster');
        $world->dispose();
    }

    public function testTargetsInheritTheirContainersMotionAndChangesPropagate(): void
    {
        $world = new World(400, 400);
        $log = [];
        $this->runWorld($world, 40, static function (World $w) use (&$log) {
            $fleet = $w->stage->addChild(new Sprite());   // moves; its children are the targets
            $enemies = $fleet->addChild(new Sprite());
            $enemy = $enemies->addChild(new Quad(10, 10));
            $enemy->moveTo(300, 100);
            $fleet->setVelocity(-100, 0);                 // brings the enemy towards x = 100 at t = 2
            $turret = $w->stage->addChild(new Quad(10, 10));
            $turret->moveTo(100, 100);
            $turret->go(static function () use ($turret, $enemies, $w, &$log) {
                $turret->wait(10.0, Wake::collision($enemies));
                $log[] = round($w->clock->time, 1);
            });
            $w->go(static function () use ($fleet) {
                Async::wait(0.5);
                $fleet->setVelocity(-200, 0);             // faster: the certificate is repaired, not re-polled
            });
        });
        // 190 points apart (overlap begins at a gap of 10): 0.5 s at 100 = 50, then 140 at 200 = 0.7 s
        $this->assertSame([1.2], $log);
        $world->dispose();
    }

    public function testBodiesOnARotatingParentArePredictedAlongTheArc(): void
    {
        $world = new World(400, 400);
        $log = [];
        $predictions = 0;
        $this->runWorld($world, 40, static function (World $w) use (&$log) {
            $arm = $w->stage->addChild(new Sprite());     // a shoulder at (200, 200), turning a quarter per second
            $arm->moveTo(200, 200);
            $hands = $arm->addChild(new Sprite());
            $hand = $hands->addChild(new Quad(4, 4));
            $hand->moveTo(98, -2);                        // 100 points out along +x
            $arm->spin(M_PI / 2);
            $target = $w->stage->addChild(new Quad(4, 4));
            $target->moveTo(198, 298);                    // straight below the shoulder: the hand gets there at t = 1
            $target->go(static function () use ($target, $hands, $w, &$log) {
                $target->wait(10.0, Wake::collision($hands));
                $log[] = $w->clock->time;
            });
        }, 0.05);
        $this->assertCount(1, $log);
        $this->assertEqualsWithDelta(1.0, $log[0], 0.12, 'woken where the arc meets the target, within a tick or two');
        $world->dispose();
    }
}
