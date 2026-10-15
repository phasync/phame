<?php

namespace Phame\Tests;

use Phame\Async;
use Phame\Engine;
use Phame\Display\{Quad, Sprite};
use Phame\Events\{Key, KeyEvent, TouchEvent, EventDispatcher};
use Phame\Scene\Node3D;
use Phame\Events\{EnterFrameEvent, Event};

final class EngineLoopTest extends GlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->engine->clock->forcedDelta = 0.1;
    }

    public function testRunStopsAfterMaxFramesAndAdvancesGameTime(): void
    {
        $this->engine->maxFrames = 5;
        $this->engine->run();
        $this->assertSame(5, $this->engine->clock->frame);
        $this->assertEqualsWithDelta(0.5, $this->engine->clock->time, 1e-9);
    }

    /** Regression: quit() stayed in effect, so a second run() returned at once. */
    public function testRunCanBeCalledAgainAfterItEnded(): void
    {
        $this->engine->maxFrames = 3;
        $this->engine->run();
        $this->engine->maxFrames = 7;
        $this->engine->run();
        $this->assertSame(7, $this->engine->clock->frame);
    }

    public function testEnterFrameReachesEveryObjectInTheTree(): void
    {
        $q = new Quad(1, 1);
        $this->engine->stage->addChild($q);
        $total = 0.0;
        $q->addEventListener(Event::ENTER_FRAME, function (EnterFrameEvent $e) use (&$total) { $total += $e->passedTime; });
        $this->engine->maxFrames = 3;
        $this->engine->run();
        $this->assertEqualsWithDelta(0.3, $total, 1e-9);
    }

    public function testEnterFrameStillReachesAListenerWhenAnotherObjectStopsListening(): void
    {
        // the world skips the enter-frame walk when nobody listens: removing one object's listener must not stop it
        $listening = new Quad(1, 1);
        $leaving = new Quad(1, 1);
        $this->engine->stage->addChild($listening);
        $this->engine->stage->addChild($leaving);
        $frames = 0;
        $listening->addEventListener(Event::ENTER_FRAME, function () use (&$frames) { ++$frames; });
        $leaving->addEventListener(Event::ENTER_FRAME, $gone = static function () {});
        $leaving->removeEventListener(Event::ENTER_FRAME, $gone);
        $leaving->removeEventListener(Event::ENTER_FRAME);   // twice: the count must not go below what is left
        $this->engine->maxFrames = 3;
        $this->engine->run();
        $this->assertSame(3, $frames);
    }

    public function testAwaitFrameResumesOncePerFrameBeforeRender(): void
    {
        $seen = [];
        $this->engine->maxFrames = 4;
        $this->engine->run(function ($engine) use (&$seen) {
            for ($i = 0; $i < 3; ++$i) {
                Async::awaitFrame();
                $seen[] = $engine->clock->frame;
            }
        });
        $this->assertSame([1, 2, 3], $seen);
    }

    public function testAwaitFrameReturnsTheScaledFrameDelta(): void
    {
        $deltas = [];
        $this->engine->maxFrames = 4;
        $this->engine->run(function ($engine) use (&$deltas) {
            $deltas[] = Async::awaitFrame();
            $engine->clock->timeScale = 0.5;
            $deltas[] = Async::awaitFrame();
            $engine->clock->paused = true;
            $deltas[] = Async::awaitFrame();
        });
        $this->assertEqualsWithDelta([0.1, 0.05, 0.0], $deltas, 1e-9);
    }

    public function testAwaitFrameWithCount(): void
    {
        $at = null;
        $this->engine->maxFrames = 6;
        $this->engine->run(function ($engine) use (&$at) {
            Async::awaitFrame(4);
            $at = $engine->clock->frame;
        });
        $this->assertSame(4, $at);
    }

    public function testWaitIsMeasuredInGameTime(): void
    {
        $at = null;
        $this->engine->maxFrames = 10;
        $this->engine->run(function ($engine) use (&$at) {
            Async::wait(0.35);
            $at = $engine->clock->time;
        });
        $this->assertEqualsWithDelta(0.4, $at, 1e-9, 'resumes in the first frame that reaches 0.35s');
    }

    public function testTimeScaleSlowsWaits(): void
    {
        $this->engine->clock->timeScale = 0.5;
        $this->engine->maxFrames = 20;
        $at = null;
        $this->engine->run(function ($engine) use (&$at) {
            Async::wait(0.48);
            $at = $engine->clock->frame;
        });
        $this->assertSame(10, $at);
    }

    public function testAsyncTweenSuspendsUntilTheTweenHasArrived(): void
    {
        $q = new Quad(1, 1);
        $this->engine->stage->addChild($q);
        $after = null;
        $this->engine->maxFrames = 20;
        $this->engine->run(function () use ($q, &$after) {
            Async::tween($q, 1.0, ['x' => 100.0]);
            $after = $q->x;
        });
        $this->assertSame(100.0, $after);
    }

    public function testCoroutinesRunInParallelAndInterleaveByFrame(): void
    {
        $log = [];
        $this->engine->maxFrames = 6;
        $this->engine->run(function ($engine) use (&$log) {
            $engine->go(function () use (&$log) {
                foreach (['a1', 'a2', 'a3'] as $s) {
                    Async::awaitFrame();
                    $log[] = $s;
                }
            });
            $engine->go(function () use (&$log) {
                foreach (['b1', 'b2', 'b3'] as $s) {
                    Async::awaitFrame();
                    $log[] = $s;
                }
            });
        });
        $this->assertSame(['a1', 'b1', 'a2', 'b2', 'a3', 'b3'], $log);
    }

    public function testQuitCancelsCoroutinesStillWaiting(): void
    {
        $finished = false;
        $cleaned = false;
        $this->engine->maxFrames = 3;
        $this->engine->run(function () use (&$finished, &$cleaned) {
            try {
                Async::wait(1000.0);
                $finished = true;
            } finally {
                $cleaned = true;
            }
        });
        $this->assertFalse($finished);
        $this->assertTrue($cleaned, 'finally blocks run when the game ends');
    }

    public function testQuitFromACoroutineEndsTheLoop(): void
    {
        $this->engine->run(function ($engine) {
            Async::awaitFrame(2);
            $engine->quit();
        });
        $this->assertSame(2, $this->engine->clock->frame, 'quit takes effect when the frame in progress has finished');
    }

    public function testAwaitEventReturnsTheEvent(): void
    {
        $q = new Quad(10, 10);
        $this->engine->stage->addChild($q);
        $got = null;
        $this->engine->maxFrames = 5;
        $this->engine->run(function ($engine) use ($q, &$got) {
            $engine->go(function () use ($engine) {
                Async::awaitFrame();
                $engine->pointer(5, 5, \Phame\Events\TouchEvent::BEGAN, 1);
            });
            $got = Async::awaitEvent($q, Event::TOUCH);
        });
        $this->assertInstanceOf(\Phame\Events\TouchEvent::class, $got);
        $this->assertSame($q, $got->target);
    }

    public function testCoroutineOwnedByADisplayObjectEndsWhenItLeavesTheStage(): void
    {
        $sprite = new Sprite();
        $this->engine->stage->addChild($sprite);
        $ticks = 0;
        $cleanedUp = false;
        $this->engine->maxFrames = 8;
        $this->engine->run(function () use ($sprite, &$ticks, &$cleanedUp) {
            $sprite->go(function () use (&$ticks, &$cleanedUp) {
                try {
                    while (true) {
                        Async::awaitFrame();
                        ++$ticks;
                    }
                } finally {
                    $cleanedUp = true;
                }
            });
            Async::awaitFrame(3);
            $sprite->removeFromParent();
            $atRemoval = $ticks;
            Async::awaitFrame(3);
            $this->assertSame($atRemoval, $ticks, 'no ticks after the sprite was removed');
        });
        $this->assertTrue($cleanedUp, 'finally blocks run');
        $this->assertGreaterThanOrEqual(2, $ticks, 'it ran every frame until then');
    }

    public function testCoroutinesStartedByAnOwnedCoroutineAreCancelledToo(): void
    {
        $sprite = new Sprite();
        $this->engine->stage->addChild($sprite);
        $ticks = 0;
        $this->engine->maxFrames = 8;
        $this->engine->run(function () use ($sprite, &$ticks) {
            $sprite->go(function () use (&$ticks) {
                Engine::current()->go(function () use (&$ticks) {
                    while (true) {
                        Async::awaitFrame();
                        ++$ticks;
                    }
                });
                Async::wait(100);
            });
            Async::awaitFrame(3);
            $sprite->dispose();
            $atDispose = $ticks;
            Async::awaitFrame(3);
            $this->assertSame($atDispose, $ticks, 'the coroutine it started ended with it');
        });
        $this->assertGreaterThanOrEqual(2, $ticks);
    }

    /** Regression (pass 3): a bullet's coroutine, started by the player's, died with the player. */
    public function testCoroutineOfAnotherObjectOutlivesTheCoroutineThatStartedIt(): void
    {
        $player = $this->engine->stage->addChild(new Quad(1, 1));
        $bullet = null;
        $raceChildTicks = 0;
        $this->engine->maxFrames = 10;
        $this->engine->run(function () use ($player, &$bullet, &$raceChildTicks) {
            $player->go(function () use (&$bullet, &$raceChildTicks) {
                $bullet = Engine::current()->stage->addChild(new Quad(1, 1));
                $bullet->go(function () use ($bullet) {
                    while (true) {
                        $bullet->y += 1;
                        Async::awaitFrame();
                    }
                });
                Async::race(function () use (&$raceChildTicks) {
                    Engine::current()->go(function () use (&$raceChildTicks) {   // belongs to the player, through the race
                        while (true) {
                            Async::awaitFrame();
                            ++$raceChildTicks;
                        }
                    });
                    Async::wait(100);
                });
            });
            Async::awaitFrame(3);
            $player->removeFromParent();
            $atRemoval = $raceChildTicks;
            $y = $bullet->y;
            Async::awaitFrame(3);
            $this->assertSame($atRemoval, $raceChildTicks, 'the race branch child ended with the player');
            $this->assertSame($y + 3, $bullet->y, 'the bullet kept moving every frame');
        });
    }

    /** Regression: removing its own object cancelled the coroutine mid-removal, leaving the object attached. */
    public function testCoroutineCanRemoveItsOwnObject(): void
    {
        $group = $this->engine->stage->addChild(new Sprite());
        $sibling = $group->addChild(new Quad(1, 1));
        $log = [];
        $this->engine->maxFrames = 5;
        $this->engine->run(function () use ($group, $sibling, &$log) {
            $bullet = $group->addChild(new Quad(1, 1));
            $bullet->go(function () use ($bullet, &$log) {
                Async::awaitFrame();
                $bullet->removeFromParent();
                $log[] = 'after remove';
                try {
                    Async::awaitFrame();
                    $log[] = 'still running';
                } finally {
                    $log[] = 'stopped at next wait';
                }
            });
            $quick = $group->addChild(new Quad(1, 1));
            $quick->go(function () use ($quick, &$log) {
                Async::awaitFrame();
                $quick->removeFromParent();
                $log[] = 'quick ends';
            });
            $sibling->go(function () use (&$log) {
                while (true) {
                    Async::awaitFrame();
                }
            });
        });
        $this->assertEqualsCanonicalizing(['after remove', 'quick ends', 'stopped at next wait'], $log);
        $this->assertSame([$sibling], $group->getChildren(), 'both removed themselves; the sibling stays');
    }

    public function testCancelledIsACheapFlagForCleanExits(): void
    {
        $log = [];
        $this->engine->maxFrames = 6;
        $this->engine->run(function () use (&$log) {
            $q = Engine::current()->stage->addChild(new Quad(1, 1));
            $q->go(function () use ($q, &$log) {
                $log[] = Async::cancelled() ? 'cancelled' : 'running';
                Async::awaitFrame();
                $q->removeFromParent();
                $log[] = Async::cancelled() ? 'cancelled' : 'running';
            });
        });
        $this->assertSame(['running', 'cancelled'], $log);
    }

    public function testAnyDispatcherCanBeACancelGroup(): void
    {
        $wave = new EventDispatcher();
        $spawned = 0;
        $this->engine->maxFrames = 10;
        $this->engine->run(function () use ($wave, &$spawned) {
            for ($i = 0; $i < 3; ++$i) {
                $wave->go(function () use (&$spawned) {
                    Async::wait(0.45);
                    ++$spawned;
                });
            }
            Async::wait(0.2);
            $wave->cancelCoroutines();
            Async::wait(0.5);
        });
        $this->assertSame(0, $spawned);
    }

    public function testNode3DCoroutineEndsWhenRemovedFromItsParent(): void
    {
        $root = new Node3D();
        $node = new Node3D();
        $root->addChild($node);
        $ticks = 0;
        $this->engine->maxFrames = 8;
        $this->engine->run(function () use ($root, $node, &$ticks) {
            $node->go(function () use (&$ticks) {
                while (true) {
                    Async::awaitFrame();
                    ++$ticks;
                }
            });
            Async::awaitFrame(3);
            $root->removeChild($node);
            $atRemoval = $ticks;
            Async::awaitFrame(3);
            $this->assertSame($atRemoval, $ticks);
        });
        $this->assertGreaterThanOrEqual(2, $ticks);
    }

    public function testAwaitKeyWaitsForTheRequestedKey(): void
    {
        $got = null;
        $this->engine->maxFrames = 6;
        $this->engine->run(function ($engine) use (&$got) {
            $engine->go(function () use (&$got, $engine) {
                $got = Async::awaitKey(32)->keyCode;
            });
            Async::awaitFrame();
            $engine->stage->dispatchEvent(new KeyEvent(Event::KEY_DOWN, 65));
            Async::awaitFrame();
            $this->assertNull($got, 'a different key does not end the wait');
            $engine->stage->dispatchEvent(new KeyEvent(Event::KEY_DOWN, 32));
            Async::awaitFrame();
        });
        $this->assertSame(32, $got);
    }

    public function testSimulateKeyDrivesIsKeyDownAndAwaitKey(): void
    {
        $got = null;
        $this->engine->maxFrames = 4;
        $this->engine->run(function ($engine) use (&$got) {
            $engine->go(function () use (&$got) {
                $got = Async::awaitKey(Key::SPACE)->keyCode;
            });
            Async::awaitFrame();
            $engine->simulateKey(Key::LEFT, true);
            $this->assertTrue($engine->isKeyDown(Key::LEFT));
            $engine->simulateKey(Key::SPACE, true);
            Async::awaitFrame();
            $engine->simulateKey(Key::LEFT, false);
            $this->assertFalse($engine->isKeyDown(Key::LEFT));
        });
        $this->assertSame(Key::SPACE, $got);
        $this->assertSame(ord('w'), Key::W);
    }

    /** Regression: a second event before the waiter resumed replaced the first, so awaitKey(SPACE) missed SPACE. */
    public function testAwaitKeyDoesNotLoseKeysPressedInTheSameFrame(): void
    {
        $got = [];
        $this->engine->maxFrames = 4;
        $this->engine->run(function ($engine) use (&$got) {
            $engine->go(function () use (&$got) {
                $got[] = Async::awaitKey(Key::SPACE)->keyCode;
            });
            $engine->go(function () use (&$got) {
                $got[] = Async::awaitKey()->keyCode;
            });
            Async::awaitFrame();
            $engine->simulateKey(Key::A, true);       // all in one frame, as SDL delivers them
            $engine->simulateKey(Key::SPACE, true);
            $engine->simulateKey(Key::B, true);
            Async::awaitFrame();
        });
        $this->assertEqualsCanonicalizing([Key::SPACE, Key::A], $got, 'SPACE is found behind A; "any key" gets the first');
    }

    public function testMousePositionIsInStageCoordinates(): void
    {
        $this->engine->pointer(10, 20, TouchEvent::HOVER);
        [$x, $y] = [$this->engine->mouseX, $this->engine->mouseY];
        $this->assertGreaterThan(0.0, $x);
        $this->assertEqualsWithDelta($x * 2, $y, 1e-6, 'window and stage share an aspect ratio here, so y stays twice x');
    }

    public function testVelocityMovesObjectsInGameTimeWithoutCoroutines(): void
    {
        $q = $this->engine->stage->addChild(new Quad(1, 1));
        $q->velocityX = 10;
        $q->velocityY = -20;
        $this->engine->maxFrames = 5;
        $this->engine->run(function ($engine) {
            Async::awaitFrame(3);
            $engine->clock->paused = true;
        });
        $this->assertEqualsWithDelta(3.0, $q->x, 1e-9, '3 frames of 0.1 s, then paused');
        $this->assertEqualsWithDelta(-6.0, $q->y, 1e-9);
        $q->velocityX = $q->velocityY = 0;
        $this->engine->clock->paused = false;
        $this->engine->maxFrames = 8;
        $this->engine->run();
        $this->assertEqualsWithDelta(3.0, $q->x, 1e-9, 'stopped');
    }

    public function testUntilWakesOnceWithTheConditionsValue(): void
    {
        $bullet = $this->engine->stage->addChild(new Quad(2, 2));
        $enemies = $this->engine->stage->addChild(new Sprite());
        $enemy = $enemies->addChild(new Quad(4, 4));
        $enemy->y = -30;
        $checks = 0;
        $hit = null;
        $wokeAt = null;
        $this->engine->maxFrames = 10;
        $this->engine->run(function ($engine) use ($bullet, $enemies, &$checks, &$hit, &$wokeAt) {
            $bullet->velocityY = -100;   // 10 points per frame
            $hit = Async::until(function () use ($bullet, $enemies, &$checks) {
                ++$checks;
                return $bullet->firstOverlap($enemies);
            });
            $wokeAt = $engine->clock->frame;
        });
        $this->assertSame($enemy, $hit, 'returns what the condition returned');
        $this->assertSame(3, $wokeAt, 'after 3 frames the bullet (y -30) overlaps the enemy');
        $this->assertSame(3, $checks, 'checked once per frame by the engine, until true');
    }

    public function testFirstOverlapUsesTheGroupTreeInsideUntilAndFollowsChanges(): void
    {
        $bullet = $this->engine->stage->addChild(new Quad(2, 2));
        $enemies = $this->engine->stage->addChild(new Sprite());
        for ($i = 0; $i < 50; ++$i) {
            $e = $enemies->addChild(new Quad(4, 4));
            $e->x = $i * 10 + 100;   // all far to the right
        }
        $near = $enemies->addChild(new Quad(4, 4));
        $near->y = -40;
        $hit = null;
        $this->engine->maxFrames = 12;
        $this->engine->run(function () use ($bullet, $enemies, $near, &$hit) {
            Async::awaitFrame();
            $near->removeFromParent();   // the tree must forget it
            $late = $enemies->addChild(new Quad(4, 4));
            $late->y = -60;
            $late->name = 'late';
            $bullet->velocityY = -100;
            $hit = Async::until(fn () => $bullet->firstOverlap($enemies));
        });
        $this->assertSame('late', $hit?->name, 'removed children are forgotten, added ones found');
    }

    public function testRemovingTheOwnerWhileInUntilLeavesNoWatchBehind(): void
    {
        $q = $this->engine->stage->addChild(new Quad(1, 1));
        $checks = 0;
        $this->engine->maxFrames = 8;
        $this->engine->run(function () use ($q, &$checks) {
            $q->go(function () use (&$checks) {
                Async::until(function () use (&$checks) { ++$checks; return false; });
            });
            Async::awaitFrame(3);
            $q->removeFromParent();
            $at = $checks;
            Async::awaitFrame(3);
            $this->assertSame($at, $checks, 'the condition is no longer checked');
        });
    }

    public function testAwaitTouchWaitsForTheRequestedPhase(): void
    {
        $quad = new Quad(10, 10);
        $this->engine->stage->addChild($quad);
        $phase = null;
        $this->engine->maxFrames = 6;
        $this->engine->run(function ($engine) use ($quad, &$phase) {
            $engine->go(function () use ($quad, &$phase) {
                $phase = Async::awaitTouch($quad)->phase;
            });
            Async::awaitFrame();
            $quad->dispatchEvent(new TouchEvent(TouchEvent::BEGAN, 1, 1, 1));
            Async::awaitFrame();
            $this->assertNull($phase, 'a press is not a click');
            $quad->dispatchEvent(new TouchEvent(TouchEvent::ENDED, 1, 1, 1));
            Async::awaitFrame();
        });
        $this->assertSame(TouchEvent::ENDED, $phase);
    }

    public function testRaceReturnsTheFirstResultAndCancelsTheRest(): void
    {
        $slowCancelled = false;
        $result = null;
        $this->engine->maxFrames = 30;
        $this->engine->run(function () use (&$result, &$slowCancelled) {
            $result = Async::race(
                function () use (&$slowCancelled) {
                    try {
                        Async::wait(5.0);
                        return 'slow';
                    } finally {
                        $slowCancelled = true;
                    }
                },
                function () {
                    Async::wait(0.3);
                    return 'fast';
                },
            );
        });
        $this->assertSame('fast', $result);
        $this->assertTrue($slowCancelled);
    }

    /** Regression (pass 4): cancelling the racer cancelled only the first branch; the others ran to completion. */
    public function testRemovingTheOwnerCancelsEveryRaceBranch(): void
    {
        $log = [];
        $this->engine->maxFrames = 40;
        $this->engine->run(function () use (&$log) {
            $racer = Engine::current()->stage->addChild(new Quad(1, 1));
            $racer->go(function () use (&$log) {
                Async::race(
                    function () use (&$log) { Async::wait(1.0); $log[] = 'a'; },
                    function () use (&$log) { Async::wait(2.0); $log[] = 'b'; },
                    function () use (&$log) { Async::wait(3.0); $log[] = 'c'; },
                );
            });
            Async::awaitFrame(2);
            $racer->removeFromParent();
        });
        $this->assertSame([], $log);
    }

    public function testRaceRethrowsAnExceptionFromABranch(): void
    {
        $caught = null;
        $this->engine->maxFrames = 10;
        $this->engine->run(function () use (&$caught) {
            try {
                Async::race(fn () => throw new \DomainException('boom'), fn () => Async::wait(5.0));
            } catch (\DomainException $e) {
                $caught = $e->getMessage();
            }
        });
        $this->assertSame('boom', $caught);
    }

    public function testTimeoutUsesGameTime(): void
    {
        $results = [];
        $this->engine->maxFrames = 40;
        $this->engine->run(function ($engine) use (&$results) {
            $results[] = Async::timeout(0.5, function () { Async::wait(0.2); return 'done'; }, 'late');
            $results[] = Async::timeout(0.5, function () { Async::wait(2.0); return 'done'; }, 'late');
            $engine->clock->timeScale = 0.5;
            $start = $engine->clock->time;
            Async::timeout(0.5, function () { Async::wait(2.0); });
            $results[] = $engine->clock->time - $start;
        });
        $this->assertSame(['done', 'late'], array_slice($results, 0, 2));
        $this->assertEqualsWithDelta(0.5, $results[2], 0.06, 'half a game second at timeScale 0.5, give or take the frame it resumes in');
    }

    public function testExceptionInACoroutineStopsTheGameAndSurfacesFromRun(): void
    {
        $sprite = new Sprite();
        $this->engine->stage->addChild($sprite);
        $otherCleanedUp = false;
        $this->engine->maxFrames = 50;
        try {
            $this->engine->run(function ($engine) use ($sprite, &$otherCleanedUp) {
                $engine->go(function () use (&$otherCleanedUp) {
                    try {
                        while (true) {
                            Async::awaitFrame();
                        }
                    } finally {
                        $otherCleanedUp = true;
                    }
                });
                $sprite->go(function () {
                    Async::wait(0.25);
                    throw new \DomainException('boss logic bug');
                });
                Async::wait(100);
            });
            $this->fail('the exception vanished');
        } catch (\DomainException $e) {
            $this->assertSame('boss logic bug', $e->getMessage());
        }
        $this->assertTrue($otherCleanedUp);
        $this->assertLessThan(10, $this->engine->clock->frame, 'the game stopped at once');
    }

    public function testCancellationIsNotAFailure(): void
    {
        $sprite = new Sprite();
        $this->engine->stage->addChild($sprite);
        $this->engine->maxFrames = 6;
        $this->engine->run(function () use ($sprite) {
            $sprite->go(fn () => Async::wait(100));
            Async::awaitFrame();
            $sprite->dispose();
            Async::awaitFrame(3);
        });
        $this->assertSame(6, $this->engine->clock->frame);
    }
}
