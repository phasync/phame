<?php

namespace Phame\Events;

use Phame\Display\DisplayObject;
use Phame\Scene\Node3D;

class EventDispatcher
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];
    /** The queue of this object's spawner (see Engine::startOwner()), and where it puts its frame. */
    private ?Owner $owner = null;

    /**
     * Starts a coroutine that belongs to this object. It ends when the object is removed from the stage (or, for a
     * Node3D, from its parent), when it is disposed, when `cancelCoroutines()` is called, and when the game ends;
     * its finally blocks run. Everything it starts belongs to this object too: Engine::go(), phasync::go(),
     * Async::race() branches, coroutines of libraries. A coroutine started with another object's go() belongs to
     * that object only, so a bullet fired by the player keeps flying when the player dies. Any dispatcher can be
     * used as a group: make a plain `new EventDispatcher()` for a wave of enemies and cancel them all at once.
     *
     * ```php
     * $enemy->go(function () use ($enemy, $player) {
     *     while (true) {
     *         Async::tween($enemy, 2.0, ['x' => $player->x]);
     *         Async::wait(0.5);
     *     }
     * });
     * $enemy->removeFromParent();   // the loop above ends here, its finally blocks run
     * ```
     *
     * The coroutine starts right after the calling code next waits (within the same frame), not inside go().
     * Moving an object to another parent removes it first, and so ends its coroutines.
     */
    public function go(\Closure $coroutine): void
    {
        if ($this->owner === null) {
            $this->owner = new Owner();
            $this->world()->startOwner($this->owner);
        }
        $this->owner->queue->enqueue($coroutine);
        \phasync::raiseFlag($this->owner->queue);
    }

    /**
     * Cancels every coroutine that belongs to this object: each stops with a CancelledException at the wait it is
     * in. When one of the object's own coroutines does this (for example by removing its object from the stage), the
     * removal and the code after it complete; Async::cancelled() is true from then on, and whatever still runs at the
     * start of the next frame is cancelled then. go() afterwards starts afresh.
     */
    public function cancelCoroutines(): void
    {
        if ($this->owner === null) {
            return;
        }
        $spawner = $this->owner->queue;
        $frame = $this->owner->frame;
        $this->owner = null;
        while (!$spawner->isEmpty()) {
            $spawner->dequeue();   // not started yet: never will be
        }
        $spawner->enqueue(null);
        \phasync::raiseFlag($spawner);
        if ($frame === null || $frame->ended) {
            return;
        }
        if (\phasync::frame() === $frame) {
            // One of this object's own coroutines removes it, and usually ends right after. A hard cancel now would
            // also wake the idle spawner with an exception (about 10x the cost); flag it, and let the engine cancel
            // at the start of the next frame whatever is still running.
            $frame->requestCancellation();
            $this->world()->cancelLater($frame);
            return;
        }
        $frame->cancel();
    }

    public function __destruct()
    {
        if ($this->owner !== null) {
            $this->owner->queue->enqueue(null);   // nobody can add any more: close, and let what runs finish
            \phasync::raiseFlag($this->owner->queue);
        }
    }

    /** The world this object belongs to (overridden by objects that know it more directly). */
    protected function world(): \Phame\World
    {
        return \Phame\World::current();
    }

    public function addEventListener(string $type, callable $listener): void
    {
        $this->listeners[$type][] = $listener;
    }

    public function removeEventListener(string $type, ?callable $listener = null): void
    {
        if ($listener === null) {
            unset($this->listeners[$type]);
            return;
        }
        $list = $this->listeners[$type] ?? [];
        $this->listeners[$type] = array_values(array_filter($list, static fn ($l) => $l != $listener));
    }

    public function hasEventListener(string $type): bool
    {
        return !empty($this->listeners[$type]);
    }

    /**
     * Calls the listeners with the event. A bubbling event continues to the parents of a DisplayObject until
     * a listener calls stopPropagation().
     */
    public function dispatchEvent(Event $event): void
    {
        $event->target ??= $this;
        $node = $this;
        do {
            $event->currentTarget = $node;
            foreach ($node->listeners[$event->type] ?? [] as $listener) {
                $listener($event);
            }
            $node = $event->bubbles && !$event->stopped && ($node instanceof DisplayObject || $node instanceof Node3D) ? $node->parent : null;
        } while ($node !== null);
    }
}
