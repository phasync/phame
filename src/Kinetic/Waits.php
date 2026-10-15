<?php

namespace Phame\Kinetic;

use Phame\Wake\Wake;
use Phame\Wake\Waiter;

/**
 * What 2D display objects and 3D nodes share as bodies of the world manager: waiting for what will happen to them,
 * and telling the world when their motion description changed. The class provides world(), kineticGroup() and the
 * Body methods.
 */
trait Waits
{
    /** @var ?array<int, Waiter> @internal coroutines waiting with this body as subject */
    public ?array $waiting = null;
    /** @internal Queued for the world's reconciliation. */
    public bool $motionQueued = false;
    /** The body a wake condition found in the last wait() (what it hit, or came near). */
    public ?Body $contact = null;
    /** @internal The world-space state cached for the reconciliation pass in $kineticPass (see Kinetic::state()). */
    public int $kineticPass = -1;
    /** @var array{float,float,float,float,float,float,float,float,float,float}|null */
    public ?array $kineticCache = null;
    /** @internal How many watched bodies (waiting, or members of a watched group) are in this subtree, itself included. */
    public int $watchedBelow = 0;

    /** @internal Whether the world needs to hear about this body's motion: it waits, or its group is watched. */
    public function isWatched(): bool
    {
        return $this->waiting !== null || ($this->kineticGroup()?->dependents ?? 0) > 0;
    }

    /**
     * @internal Tells the world, once per cycle, that the description changed. The body's watched descendants move
     * with it, so they are queued too; subtrees without watched bodies are not even visited.
     */
    public function motionChanged(): void
    {
        if ($this->watchedBelow > 0) {
            $this->queueWatched($this->world());
        }
    }

    private function queueWatched(\Phame\World $world): void
    {
        if (!$this->motionQueued && $this->isWatched()) {
            $this->motionQueued = true;
            $world->motionChanged($this);
        }
        if ($this instanceof Group) {
            foreach ($this->kineticMembers() as $child) {
                if ($child->watchedBelow > 0) {
                    $child->queueWatched($world);
                }
            }
        }
    }

    /** @internal The body left $group: its subtree no longer counts there, and it may no longer be watched itself. */
    public function leftGroup(Group $group): void
    {
        for ($n = $group; $n !== null; $n = $n->kineticGroup()) {
            $n->watchedBelow -= $this->watchedBelow;
        }
        if ($group->dependents > 0 && $this->waiting === null) {
            --$this->watchedBelow;   // it was watched only as a member
        }
    }

    /** @internal The body joined its (current) group: the reverse of leftGroup(). */
    public function joinedGroup(Group $group): void
    {
        if ($group->dependents > 0 && $this->waiting === null) {
            ++$this->watchedBelow;
        }
        for ($n = $group; $n !== null; $n = $n->kineticGroup()) {
            $n->watchedBelow += $this->watchedBelow;
        }
    }

    /**
     * Sleeps the calling coroutine for $seconds of game time, or until $wake fires, and returns the condition that
     * fired, or null when the time ran out. The world predicts when conditions will hold from the motion descriptions
     * and wakes the coroutine then: nothing runs for it in between.
     *
     * ```php
     * $bullet->setVelocity(0, -600);
     * if ($bullet->wait(2.0, $hitOrGone) !== Wake::leaveWorld()) { $enemy = $bullet->contact; ... }
     * ```
     */
    public function wait(float $seconds, ?Wake $wake = null): ?Wake
    {
        $world = $this->world();
        $waiter = Waiter::get($this, $wake, $world->clock->time + $seconds);
        $world->addWaiter($waiter);
        try {
            \phasync::getLoop()->park($waiter->slot);
            $this->contact = $waiter->contact;
            return $waiter->result;
        } finally {
            $world->removeWaiter($waiter);
            $waiter->free();
        }
    }
}
