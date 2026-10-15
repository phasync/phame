<?php

namespace Phame;

use phasync;
use Phame\Animation\Juggler;
use Phame\Display\DisplayObject;
use Phame\Display\DisplayObjectContainer;
use Phame\Display\Stage;
use Phame\Events\EnterFrameEvent;
use Phame\Events\Event;
use Phame\Events\KeyEvent;
use Phame\Events\Owner;

/**
 * One game: its stage, clock, juggler and coroutines, and the world manager that moves objects and wakes coroutines
 * when what they wait for happens. A world draws nothing itself; an Engine shows one in a window, and a server can
 * run many in one process, each driven by its own ticks.
 *
 * A world's coroutines run in its phasync scope, so code that does not know its world finds it with
 * World::current(). Objects on a world's stage know it directly ($object->stage->world).
 */
final class World
{
    /** @var \WeakMap<\Scope, World>|null */
    private static ?\WeakMap $byScope = null;
    /** The only world, while there is exactly one: World::current() needs no scope lookup then. */
    private static ?World $single = null;
    private static int $alive = 0;

    public readonly Stage $stage;
    public readonly Clock $clock;
    public readonly Juggler $juggler;
    public readonly \Scope $scope;
    /** Raised once per tick: what Async::awaitFrame() waits for. */
    public readonly \stdClass $frameFlag;

    /** Raised when something makes the next event earlier than the evented driver is sleeping for. */
    public readonly \stdClass $wakeFlag;
    /** @internal Coroutines in Async::awaitFrame(): while there are any, an evented world ticks every frame. */
    public int $frameWaiters = 0;
    /** The tick rate an evented world falls back to while something wants every frame. */
    public float $frameRate = 60.0;
    /** What the evented driver sleeps until; -INF while it is awake (it looks again before it sleeps). */
    private float $sleepingUntil = -INF;
    /** When (hrtime) runEvented() last brought game time up to real time. */
    private int|float $eventedAt = 0;

    /** The exception that escaped a coroutine and stopped the world, if any. */
    public private(set) ?\Throwable $failure = null;
    /** Set by stop() and by a failure; the driver of the world ends its loop. */
    public private(set) bool $stopped = false;

    /** @var array<int, true> keys held by the local player */
    private array $keysDown = [];
    /** @var \WeakMap<DisplayObjectContainer, \Phame\Collision\AabbTree> */
    private \WeakMap $collisionTrees;
    /** @var list<?Watch> conditions of Async::until(); the first $watchCount entries are live */
    private array $watches = [];
    private int $watchCount = 0;
    /** @var list<?\phasync\Frame> frames of owners that removed themselves; cancelled at the start of the next tick */
    private array $cancelLater = [];
    private int $cancelLaterCount = 0;
    /** Owners whose spawner is to be started, by the root spawner in the world's own frame. */
    private \SplQueue $spawns;
    /** @var list<?Wake\Waiter> new waiters, predicted together in the next reconciliation */
    private array $fresh = [];
    private int $freshCount = 0;
    /** @var list<?Kinetic\Body> bodies whose motion changed since the last repair; the first $changedCount are live */
    private array $changed = [];
    private int $changedCount = 0;
    /** @var \WeakMap<\Phame\Scene\Scene3D, \Phame\Physics\Space> */
    private \WeakMap $spaces;
    /** @var list<array{string, string, \Closure, bool}> collision handlers: class a, class b, handler, wants the world */
    private array $handlers = [];
    /** @var array<string, array{?\Closure, bool, bool}> resolved per class pair: handler, swapped, wants the world */
    private array $handlerCache = [];
    /** @var list<?\Phame\Scene\Node3D> published nodes whose description changed, for viewers, one per change */
    private array $published = [];
    /** @var list<?list<float>> the description each change gave its node, as describe() returned it then */
    private array $publishedDescriptions = [];
    private int $publishedCount = 0;
    /** @var \WeakMap<Kinetic\Group, array<int, Wake\Waiter>> waiters whose conditions watch a group */
    private \WeakMap $dependents;
    /** A binary min-heap of event times (deadlines and certificates) in game time: parallel arrays, so that it allocates nothing once warm. */
    private array $heapTime = [];
    /** @var list<?Wake\Waiter> */
    private array $heapWaiter = [];
    private array $heapGeneration = [];
    private int $heapCount = 0;
    private \Closure $guarded;
    private \Closure $ownerLoop;
    private bool $started = false;

    public function __construct(float $width = 800, float $height = 600)
    {
        $this->clock = new Clock();
        $this->stage = new Stage((int) $width, (int) $height);
        $this->stage->world = $this;
        $this->stage->attachClock($this->clock);
        $this->juggler = new Juggler();
        $this->scope = new \Scope();
        $this->frameFlag = new \stdClass();
        $this->wakeFlag = new \stdClass();
        $this->spawns = new \SplQueue();
        $this->collisionTrees = new \WeakMap();
        $this->dependents = new \WeakMap();
        $this->spaces = new \WeakMap();
        $guarded = $this->guarded = $this->runGuarded(...);
        $this->ownerLoop = static function (Owner $owner) use ($guarded): void {
            phasync::run(static function (Owner $owner) use ($guarded): void {
                $owner->frame = phasync::frame();
                $queue = $owner->queue;
                try {
                    while (true) {
                        while (!$queue->isEmpty()) {
                            $coroutine = $queue->dequeue();
                            if ($coroutine === null) {
                                return;   // closed: run() waits for what it started
                            }
                            phasync::go($guarded, $coroutine);
                        }
                        phasync::awaitFlag($queue);
                    }
                } finally {
                    $owner->frame = null;   // the frame holds this coroutine, which holds $owner: no cycle left behind
                }
            }, $owner);
        };
        self::$byScope ??= new \WeakMap();
        self::$byScope[$this->scope] = $this;
        self::$single = ++self::$alive === 1 ? $this : null;
    }

    /** Removes the world from World::current()'s registry. Call it when the world is done with. */
    public function dispose(): void
    {
        if (!isset(self::$byScope[$this->scope])) {
            return;
        }
        unset(self::$byScope[$this->scope]);
        --self::$alive;
        self::$single = null;
        if (self::$alive === 1) {
            foreach (self::$byScope as $world) {
                self::$single = $world;
            }
        }
    }

    /** The world of the running coroutine (its scope), or the only world there is. */
    public static function current(): self
    {
        return self::$single ?? self::$byScope[\Scope::getCurrent()] ?? throw new \LogicException(
            self::$alive === 0 ? 'No world has been created' : 'Not running in a world: use $world->run() or an object of the world'
        );
    }

    /**
     * Runs $driver in the world: in its scope, with its root spawner running. Call it inside phasync::run(). The
     * driver ticks the world (an Engine's frame loop, or a server's timer) until it returns; then every coroutine
     * of the world is cancelled.
     */
    public function run(\Closure $driver): mixed
    {
        return $this->scope->run(function () use ($driver) {
            $result = null;
            $ending = false;
            try {
                phasync::run(function () use ($driver, &$result, &$ending) {
                    $this->stopped = false;
                    $this->started = true;
                    $spawns = $this->spawns;
                    $ownerLoop = $this->ownerLoop;
                    phasync::go(static function () use ($spawns, $ownerLoop) {
                        while (true) {
                            while (!$spawns->isEmpty()) {
                                phasync::go($ownerLoop, $spawns->dequeue());
                            }
                            phasync::awaitFlag($spawns);
                        }
                    });
                    try {
                        $result = $driver($this);
                    } finally {
                        $this->started = false;
                        $ending = true;
                        phasync::frame()->cancel();   // every coroutine of the world, this one included
                    }
                });
            } catch (\phasync\CancelledException $e) {
                if (!$ending) {
                    throw $e;   // cancelled from outside, not by the world's own ending
                }
            }
            return $result;
        });
    }

    /**
     * The game time of the next thing the world has to do: the earliest contact, certificate or deadline. While
     * something wants every frame (Async::awaitFrame(), Async::until(), the juggler), at most one frame from now.
     * INF if nothing is pending: the world can sleep until woken.
     */
    public function nextEventTime(): float
    {
        $now = $this->clock->time;
        if ($this->freshCount > 0 || $this->changedCount > 0 || $this->cancelLaterCount > 0) {
            return $now;
        }
        $next = $this->heapCount > 0 ? $this->heapTime[0] : INF;
        foreach ($this->spaces as $space) {
            $next = min($next, $space->nextTime());
        }
        if ($this->frameWaiters > 0 || $this->watchCount > 0 || !$this->juggler->isIdle()) {
            $next = min($next, $now + 1.0 / $this->frameRate);
        }
        return $next;
    }

    /** @internal Something will happen at game time $time: wake the evented driver if it sleeps past it. */
    public function notifyEvent(float $time): void
    {
        if ($time < $this->sleepingUntil) {
            $this->sleepingUntil = $time;
            phasync::raiseFlag($this->wakeFlag);
        }
    }

    /**
     * Drives the world without ticks: sleeps until its next event (or until woken), advances game time to the real
     * time, handles what is due, and calls $afterStep. Game time follows real time. Call it inside run().
     */
    public function runEvented(?\Closure $afterStep = null): void
    {
        $this->eventedAt = hrtime(true);
        while (!$this->stopped) {
            $wait = $this->nextEventTime() - $this->clock->time - ((hrtime(true) - $this->eventedAt) / 1e9);
            if ($wait > 0.0) {
                $this->sleepingUntil = $this->clock->time + $wait;
                try {
                    phasync::awaitFlag($this->wakeFlag, min($wait, 3600.0));
                } catch (\phasync\TimeoutException) {
                    // the event is due
                }
                $this->sleepingUntil = -INF;
            }
            $now = hrtime(true);
            $seconds = ($now - $this->eventedAt) / 1e9;
            $this->eventedAt = $now;
            $this->tick($seconds);
            if ($afterStep !== null) {
                $afterStep($this);
            }
        }
    }

    /**
     * Brings game time up to real time while runEvented() sleeps, handling what fell due on the way. Between events
     * the clock stands at the last one, so code acting on something from outside the world (a message from a player,
     * a phasync timer of its own) calls this first: what it changes then starts now, as browsers see it, not at the
     * world's last event. Does nothing while the world is awake (a tick, a handler) or under other drivers.
     */
    public function sync(): void
    {
        if ($this->sleepingUntil === -INF) {
            return;
        }
        $this->sleepingUntil = -INF;   // awake for this catch-up: a sync inside it does nothing
        $now = hrtime(true);
        $seconds = ($now - $this->eventedAt) / 1e9;
        $this->eventedAt = $now;   // first: this time is counted once, whoever ticks next
        $this->advance($seconds);   // not tick(): it lets other coroutines run, the driver among them, mid-catch-up
        phasync::raiseFlag($this->wakeFlag);   // the driver works out its next event again
    }

    /** Ends the world's run: its driver stops at the end of the current tick (a sleeping evented driver wakes for it). */
    public function stop(): void
    {
        $this->stopped = true;
        phasync::raiseFlag($this->wakeFlag);
    }

    /** Takes the failure that stopped the world, if any, so that it can be rethrown once. */
    public function takeFailure(): ?\Throwable
    {
        $failure = $this->failure;
        $this->failure = null;
        return $failure;
    }

    /**
     * Starts a coroutine in the caller's frame: inside an object's coroutine it belongs to that object (see
     * EventDispatcher::go()), elsewhere it lasts as long as the world. It runs at once, up to its first wait.
     */
    public function go(\Closure $coroutine): \Fiber
    {
        return phasync::go($this->guarded, $coroutine);
    }

    /** Runs a coroutine so that an exception in it stops the world instead of vanishing in its fiber. */
    private function runGuarded(\Closure $coroutine): mixed
    {
        try {
            return $coroutine();
        } catch (\phasync\CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->failure ??= $e;
            $this->stopped = true;
            return null;
        }
    }

    /**
     * One step of the world: deferred cancellations, game time, the juggler, motion, enter-frame and Async::until()
     * conditions; then the coroutines that wait for the tick run, and what they start, until nothing is runnable.
     */
    public function tick(float $seconds): void
    {
        for ($i = 0; $i < $this->cancelLaterCount; ++$i) {
            $frame = $this->cancelLater[$i];
            $this->cancelLater[$i] = null;   // the array keeps its size
            if (!$frame->ended) {
                $frame->cancel();
            }
        }
        $this->cancelLaterCount = 0;
        $this->advance($seconds);
        // work started by input and by the juggler (spawners included) settles first, so that it sees this tick
        phasync::idle();
        phasync::raiseFlag($this->frameFlag);
        phasync::idle();
        $this->repairCertificates();   // what the coroutines changed: once per object, however many calls they made
    }

    /** Advances game time: the juggler, motion, the enter-frame event, then the Async::until() conditions. */
    public function advance(float $seconds): void
    {
        $before = $this->clock->time;
        $this->clock->tick($seconds);
        $now = $this->clock->time;
        foreach ($this->spaces as $space) {   // contacts since the last tick, each at its exact time
            $this->clock->travelTo($before);
            $space->process($now);
        }
        $delta = $this->clock->delta;
        $this->juggler->advanceTime($delta);
        DisplayObjectContainer::broadcast($this->stage, new EnterFrameEvent($delta));
        if ($this->watchCount > 0) {
            // conditions are checked here, so their coroutines sleep until there is something to do; nothing moves
            // meanwhile, so screen boxes for collision tests are computed once per object
            DisplayObject::$stableFrame = ++self::$checkPhase;
            for ($i = 0; $i < $this->watchCount; ++$i) {
                $watch = $this->watches[$i];
                if ($watch->done) {
                    continue;
                }
                $value = ($watch->condition)();
                if ($value !== null && $value !== false) {
                    $watch->value = $value;
                    $watch->done = true;
                    phasync::raiseFlag($watch);
                }
            }
            DisplayObject::$stableFrame = -1;
        }
        $this->repairCertificates();
        $now = $this->clock->time + 1e-9;
        while ($this->heapCount > 0 && $this->heapTime[0] <= $now) {
            $waiter = $this->heapWaiter[0];
            $ticket = $this->heapGeneration[0];
            $this->heapPop();
            if ($waiter->active && $waiter->ticket === $ticket) {
                if ($waiter->predictedWake !== null && $waiter->predictedAt <= $waiter->deadline && $waiter->predictedAt <= $waiter->expiresAt) {
                    $waiter->result = $waiter->predictedWake;
                    $waiter->contact = $waiter->predictedContact;
                    $this->wakeWaiter($waiter);
                } elseif ($waiter->deadline <= $waiter->expiresAt) {
                    $this->wakeWaiter($waiter);   // the deadline: wait() returns null
                } else {
                    $this->predict($waiter);      // the prediction expired (arcs): look again from here
                    $this->schedule($waiter);
                }
            }
        }
    }

    /*
     * Kinetic certificates (in the sense of kinetic data structures): every waiter holds the time of its next event,
     * predicted from the objects' current boxes and velocities: hitting or coming near a member of a group, leaving
     * the world, or its deadline. The event sits in the heap. Velocities alone never invalidate it; any other change
     * of motion queues the object, and only the certificates that involve it are repaired, once per tick.
     */

    /** @internal Queues an object whose motion changed (see DisplayObject::motionChanged()). */
    public function motionChanged(Kinetic\Body $object): void
    {
        $this->changed[$this->changedCount++] = $object;
        $this->notifyEvent($this->clock->time);
    }

    /** @internal A member left a group that waiters watch: waiters that predicted hitting it predict again. */
    public function memberRemoved(Kinetic\Group $group, Kinetic\Body $child): void
    {
        foreach ($this->dependents[$group] ?? [] as $waiter) {
            if ($waiter->predictedContact === $child) {
                $this->predict($waiter);
                $this->schedule($waiter);
            }
        }
    }

    private function repairCertificates(): void
    {
        if ($this->freshCount === 0 && $this->changedCount === 0) {
            return;
        }
        Kinetic\Kinetic::beginPass();
        for ($i = 0; $i < $this->freshCount; ++$i) {
            $waiter = $this->fresh[$i];
            $this->fresh[$i] = null;   // the array keeps its size
            if ($waiter->active) {
                $this->predict($waiter);
                $this->schedule($waiter);
            }
        }
        $this->freshCount = 0;
        for ($i = 0; $i < $this->changedCount; ++$i) {
            $object = $this->changed[$i];
            $this->changed[$i] = null;   // the array keeps its size
            $object->motionQueued = false;
            foreach ($object->waiting ?? [] as $waiter) {   // its own waits: predict them again
                $this->predict($waiter);
                $this->schedule($waiter);
            }
            $group = $object->kineticGroup();
            if ($group === null || $group->dependents === 0) {
                continue;
            }
            foreach ($this->dependents[$group] as $waiter) {   // waits that watch its group: only this pair changed
                if ($waiter->subject === $object) {
                    continue;
                }
                if ($waiter->predictedContact === $object) {
                    $this->predict($waiter);
                } else {
                    $wake = self::proximityFor($waiter->wake, $group);
                    if ($wake === null) {
                        continue;
                    }
                    $t = $this->timeOfImpact($waiter->subject, $object, $wake->radius);
                    $expires = min($waiter->expiresAt, $this->clock->time + $this->horizon);
                    if ($t >= $waiter->predictedAt && $expires === $waiter->expiresAt) {
                        continue;
                    }
                    $waiter->expiresAt = $expires;
                    if ($t >= $waiter->predictedAt) {
                        $this->schedule($waiter);
                        continue;
                    }
                    $waiter->predictedAt = $t;
                    $waiter->predictedWake = $wake;
                    $waiter->predictedContact = $object;
                }
                $this->schedule($waiter);
            }
        }
        $this->changedCount = 0;
        Kinetic\Kinetic::endPass();
    }

    /** Predicts the waiter's next event from scratch. */
    private function predict(Wake\Waiter $waiter): void
    {
        $waiter->predictedAt = INF;
        $waiter->predictedWake = null;
        $waiter->predictedContact = null;
        $waiter->expiresAt = INF;
        if ($waiter->wake !== null) {
            $this->predictFor($waiter, $waiter->wake);
        }
    }

    private function predictFor(Wake\Waiter $waiter, Wake\Wake $wake): void
    {
        switch ($wake->kind) {
            case Wake\Wake::PROXIMITY:
                foreach ($wake->group->kineticMembers() as $member) {
                    if ($member === $waiter->subject || !$member->visible) {
                        continue;
                    }
                    $t = $this->timeOfImpact($waiter->subject, $member, $wake->radius);
                    $waiter->expiresAt = min($waiter->expiresAt, $this->clock->time + $this->horizon);
                    if ($t < $waiter->predictedAt) {
                        $waiter->predictedAt = $t;
                        $waiter->predictedWake = $wake;
                        $waiter->predictedContact = $member;
                    }
                }
                return;
            case Wake\Wake::LEAVE_WORLD:
                $t = $this->timeOfLeaving($waiter->subject);
                $waiter->expiresAt = min($waiter->expiresAt, $this->clock->time + $this->horizon);
                if ($t < $waiter->predictedAt) {
                    $waiter->predictedAt = $t;
                    $waiter->predictedWake = $wake;
                    $waiter->predictedContact = null;
                }
                return;
            default:
                foreach ($wake->members as $member) {
                    $this->predictFor($waiter, $member);
                }
        }
    }

    private static function proximityFor(?Wake\Wake $wake, Kinetic\Group $group): ?Wake\Wake
    {
        if ($wake === null) {
            return null;
        }
        if ($wake->kind === Wake\Wake::PROXIMITY) {
            return $wake->group === $group ? $wake : null;
        }
        foreach ($wake->members as $member) {
            if (($found = self::proximityFor($member, $group)) !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * The game time at which $a (its box grown by $radius) starts to overlap $b, both moving at their current screen
     * velocities, or INF. Swept boxes: a fast object cannot pass through a thin one between ticks unnoticed.
     */
    private function timeOfImpact(Kinetic\Body $a, Kinetic\Body $b, float $radius): float
    {
        Kinetic\Kinetic::state($a);
        $horizon = Kinetic\Kinetic::$horizon;
        $ax0 = Kinetic\Kinetic::$x0; $ay0 = Kinetic\Kinetic::$y0; $az0 = Kinetic\Kinetic::$z0;
        $ax1 = Kinetic\Kinetic::$x1; $ay1 = Kinetic\Kinetic::$y1; $az1 = Kinetic\Kinetic::$z1;
        $avx = Kinetic\Kinetic::$vx; $avy = Kinetic\Kinetic::$vy; $avz = Kinetic\Kinetic::$vz;
        Kinetic\Kinetic::state($b);
        $this->horizon = min($horizon, Kinetic\Kinetic::$horizon);
        $t = Kinetic\Kinetic::timeOfImpact(
            $ax0, $ay0, $az0, $ax1, $ay1, $az1, $avx, $avy, $avz,
            Kinetic\Kinetic::$x0, Kinetic\Kinetic::$y0, Kinetic\Kinetic::$z0, Kinetic\Kinetic::$x1, Kinetic\Kinetic::$y1, Kinetic\Kinetic::$z1,
            Kinetic\Kinetic::$vx, Kinetic\Kinetic::$vy, Kinetic\Kinetic::$vz,
            $radius,
        );
        return $t === INF ? INF : $this->clock->time + $t;
    }

    /** The game time at which the body's box is entirely outside its world at its current velocity, or INF. */
    private function timeOfLeaving(Kinetic\Body $body): float
    {
        Kinetic\Kinetic::state($body);
        $this->horizon = Kinetic\Kinetic::$horizon;
        $body->kineticBounds();
        $t = Kinetic\Kinetic::timeOfLeaving();
        return $t === INF ? INF : $this->clock->time + $t;
    }

    private function schedule(Wake\Waiter $waiter): void
    {
        $this->heapPush(min($waiter->deadline, $waiter->predictedAt, $waiter->expiresAt), $waiter, ++$waiter->ticket);
    }

    /** How long the last computed prediction stays exact (Kinetic::$horizon of the bodies involved). */
    private float $horizon = INF;

    /** @internal See DisplayObject::wait(). */
    public function addWaiter(Wake\Waiter $waiter): void
    {
        $subject = $waiter->subject;
        $id = spl_object_id($waiter);
        $wasWatched = $subject->isWatched();
        $subject->waiting ??= [];
        $subject->waiting[$id] = $waiter;
        if (!$wasWatched) {
            Kinetic\Kinetic::countWatched($subject, 1);
        }
        if ($waiter->wake !== null) {
            $this->addDependent($waiter, $waiter->wake, $id);
        }
        $waiter->active = true;
        $this->fresh[$this->freshCount++] = $waiter;   // predicted in the next reconciliation, with the others
        $this->notifyEvent($this->clock->time);
    }

    private function addDependent(Wake\Waiter $waiter, Wake\Wake $wake, int $id): void
    {
        if ($wake->kind === Wake\Wake::PROXIMITY) {
            $list = $this->dependents[$wake->group] ?? [];
            $list[$id] = $waiter;
            $this->dependents[$wake->group] = $list;
            if ($wake->group->dependents++ === 0) {
                foreach ($wake->group->kineticMembers() as $member) {   // members are watched now
                    if ($member->waiting === null) {
                        Kinetic\Kinetic::countWatched($member, 1);
                    }
                }
            }
        }
        foreach ($wake->members as $member) {
            $this->addDependent($waiter, $member, $id);
        }
    }

    private function removeDependent(Wake\Wake $wake, int $id): void
    {
        if ($wake->kind === Wake\Wake::PROXIMITY && isset($this->dependents[$wake->group])) {
            $list = $this->dependents[$wake->group];
            unset($list[$id]);
            $this->dependents[$wake->group] = $list;
            if (--$wake->group->dependents === 0) {
                foreach ($wake->group->kineticMembers() as $member) {
                    if ($member->waiting === null) {
                        Kinetic\Kinetic::countWatched($member, -1);
                    }
                }
            }
        }
        foreach ($wake->members as $member) {
            $this->removeDependent($member, $id);
        }
    }

    /** @internal Takes the waiter out of the world (after it woke, or when its coroutine was cancelled). */
    public function removeWaiter(Wake\Waiter $waiter): void
    {
        if (!$waiter->active) {
            return;
        }
        $waiter->active = false;
        ++$waiter->ticket;   // its heap entry is stale now
        $id = spl_object_id($waiter);
        $subject = $waiter->subject;
        unset($subject->waiting[$id]);
        if ($subject->waiting === []) {
            $subject->waiting = null;
            if (!$subject->isWatched()) {
                Kinetic\Kinetic::countWatched($subject, -1);
            }
        }
        if ($waiter->wake !== null) {
            $this->removeDependent($waiter->wake, $id);
        }
    }

    private function wakeWaiter(Wake\Waiter $waiter): void
    {
        $this->removeWaiter($waiter);
        phasync::getLoop()->unpark($waiter->slot);
    }

    private function heapPush(float $time, Wake\Waiter $waiter, int $generation): void
    {
        $this->notifyEvent($time);
        $i = $this->heapCount++;
        while ($i > 0) {
            $parent = ($i - 1) >> 1;
            if ($this->heapTime[$parent] <= $time) {
                break;
            }
            $this->heapTime[$i] = $this->heapTime[$parent];
            $this->heapWaiter[$i] = $this->heapWaiter[$parent];
            $this->heapGeneration[$i] = $this->heapGeneration[$parent];
            $i = $parent;
        }
        $this->heapTime[$i] = $time;
        $this->heapWaiter[$i] = $waiter;
        $this->heapGeneration[$i] = $generation;
    }

    private function heapPop(): void
    {
        $n = --$this->heapCount;
        $time = $this->heapTime[$n];
        $waiter = $this->heapWaiter[$n];
        $generation = $this->heapGeneration[$n];
        $this->heapWaiter[$n] = null;
        if ($n === 0) {
            return;
        }
        $i = 0;
        while (true) {
            $child = 2 * $i + 1;
            if ($child >= $n) {
                break;
            }
            if ($child + 1 < $n && $this->heapTime[$child + 1] < $this->heapTime[$child]) {
                ++$child;
            }
            if ($this->heapTime[$child] >= $time) {
                break;
            }
            $this->heapTime[$i] = $this->heapTime[$child];
            $this->heapWaiter[$i] = $this->heapWaiter[$child];
            $this->heapGeneration[$i] = $this->heapGeneration[$child];
            $i = $child;
        }
        $this->heapTime[$i] = $time;
        $this->heapWaiter[$i] = $waiter;
        $this->heapGeneration[$i] = $generation;
    }

    /** A process-wide counter, so that cached boxes of different worlds never share a stamp. */
    private static int $checkPhase = 0;

    /** Whether the local player holds the key down. $keyCode is a Key constant. */
    public function isKeyDown(int $keyCode): bool
    {
        return isset($this->keysDown[$keyCode]);
    }

    /** Presses or releases a key as if the player did: for tests, demos, replays and bots. */
    public function simulateKey(int $keyCode, bool $down, bool $repeat = false): void
    {
        if ($down) {
            $this->keysDown[$keyCode] = true;
        } else {
            unset($this->keysDown[$keyCode]);
        }
        $this->stage->dispatchEvent(new KeyEvent($down ? Event::KEY_DOWN : Event::KEY_UP, $keyCode, $repeat));
    }

    /** The collision space of a scene (made when its first sphere joins). */
    public function physics(\Phame\Scene\Scene3D $scene): \Phame\Physics\Space
    {
        return $this->spaces[$scene] ??= new \Phame\Physics\Space($this, $scene);
    }

    /**
     * Registers what happens when two kinds of body touch. The kinds are the types of the first two parameters; the
     * third receives the Contact. Order does not matter: a (Rock, Ship) contact calls a (Ship $ship, Rock $rock)
     * handler with the ship first. A handler written outside a class is bound to the world ($this->wake($ship)).
     *
     * ```php
     * $world->onCollision(function (Ship $ship, Rock $rock, Contact $contact) {
     *     $rock->wasHitByShip($contact, $ship);
     *     $ship->collidedWithRock($contact, $rock);
     *     $this->wake($rock);
     *     $this->wake($ship);
     * });
     * ```
     *
     * Without a handler for a pair nothing happens: they pass through each other. The box's walls are Physics\Wall.
     * Handlers run inside the world's event processing at the time of the contact: they must not wait.
     */
    public function onCollision(\Closure $handler): void
    {
        $r = new \ReflectionFunction($handler);
        $params = $r->getParameters();
        $type = static fn (int $i) => ($t = $params[$i]?->getType()) instanceof \ReflectionNamedType ? $t->getName() : throw new \InvalidArgumentException('A collision handler takes two typed bodies and the Contact');
        if (count($params) < 2) {
            throw new \InvalidArgumentException('A collision handler takes two typed bodies and the Contact');
        }
        if ($r->getClosureThis() === null && !$r->isStatic()) {
            $handler = \Closure::bind($handler, $this, self::class);
        }
        $this->handlers[] = [$type(0), $type(1), $handler, count($params) >= 4];
        $this->handlerCache = [];
    }

    /** @internal Calls the handler for the contact's pair, if any. */
    public function collide(\Phame\Physics\Contact $contact): void
    {
        $a = $contact->a;
        $b = $contact->b;
        $key = $a::class . '|' . $b::class;
        if (!isset($this->handlerCache[$key])) {
            $this->handlerCache[$key] = [null, false, false];
            foreach ($this->handlers as [$ta, $tb, $handler, $wantsWorld]) {
                if ($a instanceof $ta && $b instanceof $tb) {
                    $this->handlerCache[$key] = [$handler, false, $wantsWorld];
                    break;
                }
                if ($b instanceof $ta && $a instanceof $tb) {
                    $this->handlerCache[$key] = [$handler, true, $wantsWorld];
                    break;
                }
            }
        }
        [$handler, $swapped, $wantsWorld] = $this->handlerCache[$key];
        if ($handler === null) {
            return;
        }
        $a->impact = $contact;
        if ($b instanceof \Phame\Scene\Node3D) {
            $b->impact = $contact;
        }
        $swapped ? ($wantsWorld ? $handler($b, $a, $contact, $this) : $handler($b, $a, $contact)) : ($wantsWorld ? $handler($a, $b, $contact, $this) : $handler($a, $b, $contact));
    }

    /** Ends every current wait() of the body now: it returns Wake::woken(), and the body's $impact tells what happened. */
    public function wake(Kinetic\Body $body): void
    {
        foreach ($body->waiting ?? [] as $waiter) {
            $waiter->result = Wake\Wake::woken();
            $this->wakeWaiter($waiter);
        }
    }

    /**
     * @internal A published node's description changed: it goes to viewers with the next batch. Every change keeps
     * its own description, since a viewer switches to each at its moment; changes at the same moment (moveTo, then
     * setVelocity) make one.
     */
    public function descriptionChanged(\Phame\Scene\Node3D $node): void
    {
        $description = $node->describe();
        $slot = $node->publishSlot;
        if ($slot >= 0 && $this->publishedDescriptions[$slot][0] === $description[0]) {
            $this->publishedDescriptions[$slot] = $description;
            return;
        }
        $node->publishSlot = $this->publishedCount;
        $this->published[$this->publishedCount] = $node;
        $this->publishedDescriptions[$this->publishedCount++] = $description;
    }

    /**
     * Takes the description changes of published nodes since the last call, in the order they happened, for a viewer
     * stream: a node that changed twice comes twice, each with the description it had from that moment.
     *
     * @return list<array{\Phame\Scene\Node3D, list<float>}>
     */
    public function takeChangedDescriptions(): array
    {
        $out = [];
        for ($i = 0; $i < $this->publishedCount; ++$i) {
            $node = $this->published[$i];
            $out[] = [$node, $this->publishedDescriptions[$i]];
            $node->publishSlot = -1;
            $this->published[$i] = $this->publishedDescriptions[$i] = null;
        }
        $this->publishedCount = 0;
        return $out;
    }

    /** @internal See EventDispatcher::go(). */
    public function startOwner(Owner $owner): void
    {
        $this->spawns->enqueue($owner);
        phasync::raiseFlag($this->spawns);
    }

    /** @internal See EventDispatcher::cancelCoroutines(). */
    public function cancelLater(\phasync\Frame $frame): void
    {
        $this->cancelLater[$this->cancelLaterCount++] = $frame;
    }

    /** @internal See DisplayObject::firstOverlap(). */
    public function collisionTree(DisplayObjectContainer $group): \Phame\Collision\AabbTree
    {
        return $this->collisionTrees[$group] ??= new \Phame\Collision\AabbTree();
    }

    /** @internal See Async::until(). */
    public function watch(Watch $watch): void
    {
        $watch->index = $this->watchCount;
        $this->watches[$this->watchCount++] = $watch;
    }

    /** @internal */
    public function unwatch(Watch $watch): void
    {
        $i = $watch->index;
        if ($i < 0 || $i >= $this->watchCount || $this->watches[$i] !== $watch) {
            return;
        }
        $last = $this->watches[--$this->watchCount];
        $this->watches[$i] = $last;
        $last->index = $i;
        $this->watches[$this->watchCount] = null;   // the array keeps its size
        $watch->index = -1;
    }
}
