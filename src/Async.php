<?php

namespace Phame;

use phasync;
use Phame\Animation\Transitions;
use Phame\Events\Event;
use Phame\Display\DisplayObject;
use Phame\Events\EventDispatcher;
use Phame\Events\KeyEvent;
use Phame\Events\TouchEvent;

/**
 * Waiting in game terms, for coroutines started with Engine::go(): the equivalent of phasync::sleep(), but
 * measured in frames and in game time, so a paused or slowed game pauses and slows its coroutines.
 *
 * ```php
 * $engine->go(function () use ($guard) {
 *     while (true) {
 *         Async::tween($guard, 2.0, ['x' => 400]);   // walk there, then
 *         Async::wait(1.5);                          // stand still for a while
 *         Async::tween($guard, 2.0, ['x' => 100]);
 *     }
 * });
 * ```
 */
final class Async
{
    /**
     * Suspends until the next frame, after the engine has handled input and advanced the juggler.
     *
     * @return float the game-time seconds that passed during the frame you woke up in (`Clock::$delta`: scaled by
     *               timeScale, 0 while paused). After waiting several frames it is the last frame's delta.
     */
    public static function awaitFrame(int $frames = 1): float
    {
        $world = World::current();
        ++$world->frameWaiters;   // an evented world ticks every frame while anyone waits for frames
        try {
            for ($i = 0; $i < $frames; ++$i) {
                phasync::awaitFlag($world->frameFlag);
            }
        } finally {
            --$world->frameWaiters;
        }
        return $world->clock->delta;
    }

    /** Suspends for this many seconds of game time. Costs nothing while waiting. */
    public static function wait(float $seconds): void
    {
        $flag = new \stdClass();
        World::current()->juggler->delayCall(static fn () => phasync::raiseFlag($flag), $seconds);
        phasync::awaitFlag($flag);
    }

    /**
     * Animates properties of $target and suspends until it has arrived.
     *
     * The position of a body (x, y, and z for 3D nodes) is not animated frame by frame: the world manager only knows
     * straight-line motion, so the curve is driven as straight segments ($segmentsPerSecond of them), each a velocity
     * towards the eased position at its end. Browsers and certificates see exact straight lines; a linear tween is one
     * segment. Other properties (alpha, scale, ...) are animated by the juggler.
     *
     * @param array<string, float> $properties
     */
    public static function tween(object $target, float $seconds, array $properties, string|\Closure $transition = Transitions::LINEAR, float $segmentsPerSecond = 10.0): void
    {
        if ($target instanceof \Phame\Kinetic\Body) {
            $motion = array_intersect_key($properties, ['x' => 0, 'y' => 0, 'z' => 0]);
            $rest = array_diff_key($properties, $motion);
            if ($motion !== []) {
                if ($rest !== []) {
                    World::current()->juggler->tween($target, $seconds, $rest, $transition);
                }
                self::glide($target, $seconds, $motion, $transition, $segmentsPerSecond);
                return;
            }
        }
        $flag = new \stdClass();
        World::current()->juggler->tween($target, $seconds, $properties, $transition, static fn () => phasync::raiseFlag($flag));
        phasync::awaitFlag($flag);
    }

    /** Drives a body's position along an eased path as straight segments (see tween()). @param array<string, float> $to */
    private static function glide(\Phame\Kinetic\Body $body, float $seconds, array $to, string|\Closure $transition, float $segmentsPerSecond): void
    {
        $ease = Transitions::get($transition);
        $is3d = $body instanceof \Phame\Scene\Node3D;
        $fromX = $body->x;
        $fromY = $body->y;
        $fromZ = $is3d ? $body->z : 0.0;
        $toX = $to['x'] ?? $fromX;
        $toY = $to['y'] ?? $fromY;
        $toZ = $to['z'] ?? $fromZ;
        $linear = $transition === Transitions::LINEAR;
        $segments = $seconds <= 0.0 ? 0 : ($linear ? 1 : max(1, (int) ceil($seconds * $segmentsPerSecond)));
        $clock = World::current()->clock;
        $start = $clock->time;
        for ($i = 1; $i <= $segments; ++$i) {
            // each segment aims at where the curve is at its scheduled end, from where the body is now: waking late
            // (wakes land on ticks) shortens the next segment instead of delaying the whole tween
            $left = $start + $seconds * $i / $segments - $clock->time;
            if ($left <= 1e-9) {
                continue;
            }
            $f = $ease($i / $segments);
            $vx = ($fromX + ($toX - $fromX) * $f - $body->x) / $left;
            $vy = ($fromY + ($toY - $fromY) * $f - $body->y) / $left;
            if ($is3d) {
                $body->setVelocity($vx, $vy, ($fromZ + ($toZ - $fromZ) * $f - $body->z) / $left);
            } else {
                $body->setVelocity($vx, $vy);
            }
            $body->wait($left);
        }
        $is3d ? $body->moveTo($toX, $toY, $toZ) : $body->moveTo($toX, $toY);   // exactly there, and stopped
    }

    /**
     * Suspends until $condition returns something other than null or false, and returns that. The engine checks the
     * condition once per frame, after moving objects and advancing tweens, without waking this coroutine: cheap
     * enough for every bullet in a shooter.
     *
     * ```php
     * $bullet->velocityY = -600;
     * $hit = Async::until(fn () => $bullet->y < -10 ? true : $bullet->firstOverlap($enemies));
     * ```
     */
    public static function until(\Closure $condition): mixed
    {
        $watch = Watch::get($condition);
        $engine = World::current();
        $engine->watch($watch);
        try {
            phasync::awaitFlag($watch);
            return $watch->value;
        } finally {
            $engine->unwatch($watch);
            $watch->free();
        }
    }

    /**
     * Whether the coroutine's object is being removed or cancelled: a cheap check (no exception) for loops that want
     * to stop cleanly, `while (!Async::cancelled()) { ... }`.
     */
    public static function cancelled(): bool
    {
        return phasync::frame()->isCancellationRequested();
    }

    /**
     * Suspends until the dispatcher dispatches an event of the type (that $accept returns true for, if given), and
     * returns it. The first such event wins, even if more arrive before the coroutine resumes.
     */
    public static function awaitEvent(EventDispatcher $dispatcher, string $type, ?\Closure $accept = null): Event
    {
        $flag = new \stdClass();
        $received = null;
        $listener = static function (Event $e) use (&$received, $flag, $accept) {
            if ($received === null && ($accept === null || $accept($e))) {
                $received = $e;
                phasync::raiseFlag($flag);
            }
        };
        $dispatcher->addEventListener($type, $listener);
        try {
            phasync::awaitFlag($flag);
        } finally {
            $dispatcher->removeEventListener($type, $listener);
        }
        return $received;
    }

    /** Suspends until a key is pressed (any key, or the one given: a Key constant) and returns the event. */
    public static function awaitKey(?int $keyCode = null, string $type = Event::KEY_DOWN): KeyEvent
    {
        return self::awaitEvent(World::current()->stage, $type, $keyCode === null ? null : static fn (KeyEvent $e) => $e->keyCode === $keyCode);
    }

    /**
     * Suspends until the pointer does something to $target or a child of it, and returns the event. The default is
     * a click: the button released over the object.
     */
    public static function awaitTouch(DisplayObject $target, string $phase = TouchEvent::ENDED): TouchEvent
    {
        return self::awaitEvent($target, Event::TOUCH, static fn (TouchEvent $e) => $e->phase === $phase);
    }

    /**
     * Runs the branches concurrently, returns what the first one to finish returns, and cancels the others (their
     * finally blocks run). An exception in a branch is rethrown here. The caller resumes up to a frame after the winner finished.
     *
     * ```php
     * $key = Async::race(fn () => Async::awaitKey(), fn () => Async::wait(5.0));   // a KeyEvent, or null after 5 s
     * ```
     */
    public static function race(\Closure ...$branches): mixed
    {
        $done = new \stdClass();
        $finished = false;
        $result = null;
        $error = null;
        $frames = [];
        try {
            foreach ($branches as $i => $branch) {
                // each branch gets a frame of its own, so that cancelling it also ends what the branch started
                phasync::go(static function () use ($i, $branch, $done, &$finished, &$result, &$error, &$frames) {
                    phasync::run(static function () use ($i, $branch, $done, &$finished, &$result, &$error, &$frames) {
                        $frames[$i] = phasync::frame();
                        try {
                            $value = $branch();
                        } catch (\phasync\CancelledException $e) {
                            throw $e;
                        } catch (\Throwable $e) {
                            if (!$finished) {
                                $finished = true;
                                $error = $e;
                                phasync::raiseFlag($done);
                            }
                            return;
                        }
                        if (!$finished) {
                            $finished = true;
                            $result = $value;
                            phasync::raiseFlag($done);
                        }
                    });
                });
                if ($finished) {
                    break;
                }
            }
            if (!$finished) {
                phasync::awaitFlag($done);
            }
        } finally {
            foreach ($frames as $frame) {
                if (!$frame->ended) {
                    $frame->cancel();
                }
            }
        }
        if ($error !== null) {
            throw $error;
        }
        return $result;
    }

    /** Runs $coroutine, but gives up after this many seconds of game time: returns $default then. */
    public static function timeout(float $seconds, \Closure $coroutine, mixed $default = null): mixed
    {
        return self::race($coroutine, static function () use ($seconds, $default) {
            self::wait($seconds);
            return $default;
        });
    }
}
