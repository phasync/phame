<?php

namespace Phame\Physics;

use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;

/**
 * Event-driven collision detection for the spheres of one Scene3D (event-driven molecular dynamics). Bodies move in
 * straight lines between events, so the time of every contact is computed, not searched for: each body keeps only
 * its earliest event (touching another sphere, reaching a wall of the scene's box, or crossing into another cell of
 * the grid), and the events are handled in time order. Handling an event means calling the world's collision
 * handler at the exact time of the contact; the handler and the objects decide what happens (new motion
 * descriptions), and the bodies involved predict again. Nothing is checked between events.
 *
 * Bodies are the direct children of the scene's root with a radius above 0. Overlapping spheres do not collide
 * again until they have separated. A body wider than half a cell is large: every body checks the large ones wherever
 * they are, and a large body looks as many cells out as its reach needs, so a contact is never missed for size.
 * Bodies that are not confined ignore the walls.
 *
 * Two bodies can be tethered: free within a distance, held at it. A tether going taut is one more event, at its exact
 * time, reported as a contact with the normal reversed: the handler's bounce pulls them together. Chains of tethered
 * spheres make ropes and chains without a single force, in the same straight lines between events.
 *
 * A body can be compound (Node3D::$compound): its descendants with a radius above 0 are its parts, carried by the
 * tree (turning joints, a tumbling root), and its own radius bounds them all. Parts follow arcs, not lines, so there
 * is no closed form for their contacts: a body approaching a compound waits for its bounding sphere, then probes,
 * each step as long as the gap allows at the fastest the two could close in, until a part touches. Probes change no
 * motion, so viewers hear only of real contacts. Compounds pass through each other.
 */
final class Space
{
    public const PAIR = 0;
    public const WALL = 1;
    public const CELL = 2;
    public const TETHER = 3;
    public const PROBE = 4;
    /**
     * A part within twice this distance (and closing in) touches; probes aim at this distance, so they reach that
     * zone in a few steps. Finer would cost events and show nothing. Closer than this, they are already in contact:
     * nothing more happens until they separate.
     */
    public const PROBE_TOLERANCE = 0.02;
    /** How long a probe waits, in contact or moving apart. */
    private const PROBE_PAUSE = 0.002;

    /** @var array<int, Node3D> */
    private array $bodies = [];
    /** @var array<int, array<int, Node3D>> by cell: x, y and z as 21-bit fields, offset by 2^20 (see cellOf()) */
    private array $cells = [];
    /** @var array<int, Node3D> the bodies with a radius above half a cell */
    private array $large = [];
    /** @var array<int, array<int, array{Node3D, float}>> by body: the bodies it is tethered to, and the lengths */
    private array $tethers = [];
    /** @var array<int, list<Node3D>> by compound body: its parts */
    private array $parts = [];
    /**
     * @var array<int, list<array{Node3D, float}>> by part: the nodes above it that can turn it, each with the length
     * of the chain of offsets from it down to the part. A turning node moves the part at most its angular speed
     * times that: a bound that holds however the joints between them bend.
     */
    private array $levers = [];
    /** @var list<Node3D> the bodies inside a compound's bounding sphere, as its last prediction found them */
    private array $inside = [];
    /** @var array<int, Node3D> compounds that changed and predict again once the burst of changes is over */
    private array $unsettled = [];
    /** @var array<int, array{int, float}> by compound body: the body it touched last, and when */
    private array $lastTouch = [];
    /** @var list<float> where placeParts() found the parts' centres, in the order of $parts */
    private static array $partX = [];
    private static array $partY = [];
    private static array $partZ = [];
    private static int $placed = 0;
    // which compound they are, when, and at which of its counters: placing again at the same moment gives the same
    private static ?Node3D $placedFor = null;
    private static float $placedAt = NAN;
    private static int $placedCounter = -1;
    /** @var list<\Phame\Math\Matrix4> one world transform per depth of a tree, reused by placeParts() */
    private static array $levels = [];
    /**
     * @var array<int, array{int, ?list<float>}> by compound: at which of its counters, and the parts' offsets in the
     * root's space (x, y, z flat), or null while something below the root moves
     */
    private array $offsets = [];
    private static ?\Phame\Math\Matrix4 $identity = null;
    // the event heap, as parallel arrays: time, body, other body (or wall code), kind, and both bodies' counters
    private array $time = [];
    private array $body = [];
    private array $other = [];
    private array $kind = [];
    private array $countA = [];
    private array $countB = [];
    private int $count = 0;

    public function __construct(private readonly World $world, public readonly Scene3D $scene, public float $cellSize = 4.0)
    {
    }

    public int $bodyCount {
        get => count($this->bodies);
    }

    public function add(Node3D $b): void
    {
        $id = spl_object_id($b);
        $this->bodies[$id] = $b;
        $b->physicsSpace = $this;
        $b->physicsCell = $this->cellOf($b->x, $b->y, $b->z);
        $this->cells[$b->physicsCell][$id] = $b;
        if ($b->radius > $this->cellSize / 2 || $b->compound) {
            $this->large[$id] = $b;
        }
        if ($b->compound) {
            $this->parts[$id] = [];
            $this->collectParts($b, $b, $this->parts[$id]);
        }
        $this->predict($b);
    }

    /** @param list<Node3D> $parts */
    private function collectParts(Node3D $root, Node3D $node, array &$parts): void
    {
        foreach ($node->children as $child) {
            $child->physicsCompound = $root;
            if ($child->radius > 0.0) {
                $parts[] = $child;
                $levers = [];
                $chain = 0.0;
                for ($n = $child; $n !== $root; $n = $n->parent) {
                    $chain += sqrt($n->mx * $n->mx + $n->my * $n->my + $n->mz * $n->mz);
                    $levers[] = [$n->parent, $chain];
                }
                $this->levers[spl_object_id($child)] = $levers;
            }
            $this->collectParts($root, $child, $parts);
        }
    }

    /**
     * Ties two bodies of this space together: they move freely while closer than $length, and are held at it. Holding
     * is the collision handler's job, as for any contact: the contact it gets has $tether set.
     */
    public function tether(Node3D $a, Node3D $b, float $length): void
    {
        $this->tethers[spl_object_id($a)][spl_object_id($b)] = [$b, $length];
        $this->tethers[spl_object_id($b)][spl_object_id($a)] = [$a, $length];
        $this->changed($a);
        $this->changed($b);
    }

    public function remove(Node3D $b): void
    {
        $id = spl_object_id($b);
        foreach ($this->tethers[$id] ?? [] as $other => $_) {
            unset($this->tethers[$other][$id]);
        }
        unset($this->tethers[$id]);
        unset($this->bodies[$id], $this->cells[$b->physicsCell][$id], $this->large[$id]);
        if (($this->cells[$b->physicsCell] ?? null) === []) {
            unset($this->cells[$b->physicsCell]);
        }
        if (isset($this->parts[$id])) {
            foreach ($this->parts[$id] as $part) {
                $part->physicsCompound = null;
                unset($this->levers[spl_object_id($part)]);
            }
            unset($this->parts[$id], $this->lastTouch[$id], $this->offsets[$id]);
        }
        $b->physicsSpace = null;
        ++$b->physicsCounter;   // its pending events are stale now
    }

    /**
     * The body's motion description changed (game code, or a handler): its pending events no longer hold (its counter
     * moves on) and it predicts again.
     */
    public function changed(Node3D $b): void
    {
        if ($b->compound) {
            // a compound changes in bursts (a hit: its velocity, its spin, a joint): it predicts once, after the burst
            if (!isset($this->unsettled[spl_object_id($b)])) {
                $this->unsettled[spl_object_id($b)] = $b;
                $this->world->notifyEvent($this->world->clock->time);   // before the driver sleeps on old predictions
            }
            return;
        }
        ++$b->physicsCounter;
        if ($b->radius > $this->cellSize / 2 || $b->compound) {   // the radius may have changed too
            $this->large[spl_object_id($b)] = $b;
        } else {
            unset($this->large[spl_object_id($b)]);
        }
        $this->moveToCell($b);
        $this->predict($b);
    }

    /** The compounds that changed predict again; so do the bodies inside them, whose probes assumed the old motion. */
    private function settle(): void
    {
        foreach ($this->unsettled as $id => $b) {
            unset($this->unsettled[$id]);
            if ($b->physicsSpace !== $this) {
                continue;
            }
            ++$b->physicsCounter;
            $this->moveToCell($b);
            $this->predict($b);
            foreach ($this->inside as $body) {   // their probes assumed its old motion
                $this->predict($body);
            }
        }
    }

    /** Handles every event up to game time $until, in order, then leaves the clock at $until. */
    public function process(float $until): void
    {
        $clock = $this->world->clock;
        $this->settle();   // what game code changed since the last time
        while ($this->count > 0 && $this->time[0] <= $until + 1e-12) {
            $t = $this->time[0];
            $a = $this->body[0];
            $b = $this->other[0];
            $kind = $this->kind[0];
            $ca = $this->countA[0];
            $cb = $this->countB[0];
            $this->pop();
            if ($a->physicsSpace !== $this || $a->physicsCounter !== $ca) {
                continue;   // a has newer events
            }
            $clock->travelTo($t);
            if (($kind === self::PAIR || $kind === self::TETHER || $kind === self::PROBE) && ($b->physicsSpace !== $this || $b->physicsCounter !== $cb)) {
                $this->predict($a);   // b changed since: a's prediction no longer holds
                continue;
            }
            if ($kind === self::CELL) {
                $this->moveToCell($a);
                $this->predict($a);
                continue;
            }
            if ($kind === self::PROBE) {
                [$compound, $other] = $a->compound ? [$a, $b] : [$b, $a];
                $contact = $this->touching($t, $compound, $other);
                if ($contact === null) {
                    $this->predict($other);   // not yet: it probes on (or meets something sooner)
                    if ($a === $compound) {
                        $this->predict($compound);
                    }
                    continue;
                }
                $this->world->collide($contact);
                foreach ([$compound, $other] as $body) {
                    if ($body->physicsSpace === $this) {
                        $this->changed($body);
                    }
                }
                $this->settle();
                continue;
            }
            $contact = $this->contact($t, $a, $kind === self::WALL ? Wall::side(intdiv($b, 2), $b % 2 === 1 ? 1 : -1) : $b);
            if ($kind === self::TETHER) {
                $contact->tether = true;
                [$contact->nx, $contact->ny, $contact->nz, $contact->normalSpeed] = [-$contact->nx, -$contact->ny, -$contact->nz, -$contact->normalSpeed];
            }
            $this->world->collide($contact);
            if ($a->physicsSpace === $this) {
                $this->changed($a);
            }
            if ($kind !== self::WALL && $b->physicsSpace === $this) {
                $this->changed($b);
            }
            $this->settle();
        }
        $clock->travelTo($until);
        $this->settle();
    }

    private function contact(float $t, Node3D $a, Node3D|Wall $b): Contact
    {
        $c = new Contact($t, $a, $b);
        if ($b instanceof Wall) {
            $n = [0.0, 0.0, 0.0];
            $n[$b->axis] = (float) $b->side;
            [$c->nx, $c->ny, $c->nz] = $n;
            $c->normalSpeed = -($a->velocityX * $c->nx + $a->velocityY * $c->ny + $a->velocityZ * $c->nz);
            return $c;
        }
        $dx = $b->x - $a->x;
        $dy = $b->y - $a->y;
        $dz = $b->z - $a->z;
        $d = sqrt($dx * $dx + $dy * $dy + $dz * $dz) ?: 1.0;
        $c->nx = $dx / $d;
        $c->ny = $dy / $d;
        $c->nz = $dz / $d;
        $c->normalSpeed = ($b->velocityX - $a->velocityX) * $c->nx + ($b->velocityY - $a->velocityY) * $c->ny + ($b->velocityZ - $a->velocityZ) * $c->nz;
        return $c;
    }

    /**
     * Finds the body's earliest event and pushes it. Older events of an unchanged body stay valid: they are still
     * true predictions (the counters only move when motion changes), so re-predicting never invalidates a partner.
     */
    private function predict(Node3D $a): void
    {
        $counter = $a->physicsCounter;
        $now = $this->world->clock->time;
        if ($a->compound) {
            $this->inside = [];
        }
        // the motion descriptions, evaluated here: this loop is the engine's hottest, so no property hooks
        $vx = $a->mvx;
        $vy = $a->mvy;
        $vz = $a->mvz;
        $age = $now - $a->mt;
        $x = $a->mx + $vx * $age;
        $y = $a->my + $vy * $age;
        $z = $a->mz + $vz * $age;
        $r = $a->radius;
        $best = INF;
        $bestKind = -1;
        $bestOther = null;

        // walls of the scene's box
        $scene = $this->scene;
        foreach (!$a->confined ? [] : [[0, $x, $vx, $scene->worldMinX, $scene->worldMaxX], [1, $y, $vy, $scene->worldMinY, $scene->worldMaxY], [2, $z, $vz, $scene->worldMinZ, $scene->worldMaxZ]] as [$axis, $p, $v, $min, $max]) {
            if ($v > 0.0 && $max < INF && $p + $r <= $max + 1e-9) {
                $t = ($max - $r - $p) / $v;
                if ($t < $best) {
                    [$best, $bestKind, $bestOther] = [max(0.0, $t), self::WALL, $axis * 2 + 1];
                }
            } elseif ($v < 0.0 && $min > -INF && $p - $r >= $min - 1e-9) {
                $t = ($min + $r - $p) / $v;
                if ($t < $best) {
                    [$best, $bestKind, $bestOther] = [max(0.0, $t), self::WALL, $axis * 2];
                }
            }
        }

        // other spheres in the cells within reach: two small ones touch from neighbouring cells at most, a large one
        // reaches further (the small ones find it in the list of large ones below)
        $cell = $a->physicsCell;
        $half = $this->cellSize / 2;
        $reach = $r > $half ? (int) ceil(($r + $half) / $this->cellSize) : 1;
        for ($i = -$reach; $i <= $reach; ++$i) {
            for ($j = -$reach; $j <= $reach; ++$j) {
                $row = $cell + ($i << 42) + ($j << 21);
                for ($k = -$reach; $k <= $reach; ++$k) {
                    foreach ($this->cells[$row + $k] ?? [] as $b) {
                        if ($b === $a || $b->radius > $half) {   // large ones: below
                            continue;
                        }
                        if ($a->compound) {
                            $t = $this->entryTime($a, $b, $now);
                            if ($t < $best) {
                                [$best, $bestKind, $bestOther] = [$t, self::PROBE, $b];
                            }
                            continue;
                        }
                        $bAge = $now - $b->mt;
                        $t = self::sphereImpact($b->mx + $b->mvx * $bAge - $x, $b->my + $b->mvy * $bAge - $y, $b->mz + $b->mvz * $bAge - $z, $b->mvx - $vx, $b->mvy - $vy, $b->mvz - $vz, $r + $b->radius);
                        if ($t < $best) {
                            [$best, $bestKind, $bestOther] = [$t, self::PAIR, $b];
                        }
                    }
                }
            }
        }

        foreach ($this->large as $b) {
            if ($b !== $a && ($a->compound || $b->compound)) {
                if (!($a->compound && $b->compound)) {
                    $t = $a->compound ? $this->entryTime($a, $b, $now) : $this->compoundTime($b, $a, $now);
                    if ($t < $best) {
                        [$best, $bestKind, $bestOther] = [$t, self::PROBE, $b];
                    }
                }
            } elseif ($b !== $a) {
                $bAge = $now - $b->mt;
                $t = self::sphereImpact($b->mx + $b->mvx * $bAge - $x, $b->my + $b->mvy * $bAge - $y, $b->mz + $b->mvz * $bAge - $z, $b->mvx - $vx, $b->mvy - $vy, $b->mvz - $vz, $r + $b->radius);
                if ($t < $best) {
                    [$best, $bestKind, $bestOther] = [$t, self::PAIR, $b];
                }
            }
        }

        foreach ($this->tethers[spl_object_id($a)] ?? [] as [$b, $length]) {
            $bAge = $now - $b->mt;
            $t = self::tetherTaut($b->mx + $b->mvx * $bAge - $x, $b->my + $b->mvy * $bAge - $y, $b->mz + $b->mvz * $bAge - $z, $b->mvx - $vx, $b->mvy - $vy, $b->mvz - $vz, $length);
            if ($t < $best) {
                [$best, $bestKind, $bestOther] = [$t, self::TETHER, $b];
            }
        }

        // leaving the cell: the neighbourhood changes
        $s = $this->cellSize;
        foreach ([[$x, $vx, ($cell >> 42) - 0x100000], [$y, $vy, (($cell >> 21) & 0x1FFFFF) - 0x100000], [$z, $vz, ($cell & 0x1FFFFF) - 0x100000]] as [$p, $v, $c]) {
            if ($v > 0.0) {
                $t = (($c + 1) * $s - $p) / $v;
            } elseif ($v < 0.0) {
                $t = ($c * $s - $p) / $v;
            } else {
                continue;
            }
            if ($t < $best) {
                [$best, $bestKind, $bestOther] = [max(0.0, $t) + 1e-9, self::CELL, null];
            }
        }

        if ($bestKind >= 0) {
            $this->push($now + $best, $a, $bestOther, $bestKind, $counter, $bestOther instanceof Node3D ? $bestOther->physicsCounter : 0);
        }
    }

    /**
     * Seconds until two spheres touch: one at the origin, the other at offset d moving at relative velocity v, the
     * radii summing to $r. INF if they never do, or touch or overlap already (they first have to separate).
     */
    private static function sphereImpact(float $dx, float $dy, float $dz, float $vx, float $vy, float $vz, float $r): float
    {
        $b = $dx * $vx + $dy * $vy + $dz * $vz;
        if ($b >= 0.0) {
            return INF;   // not approaching
        }
        $c = $dx * $dx + $dy * $dy + $dz * $dz - $r * $r;
        if ($c <= 1e-9 * $r * $r) {
            return INF;   // overlapping, or touching: a contact just handled that left them approaching
        }
        $a = $vx * $vx + $vy * $vy + $vz * $vz;
        $disc = $b * $b - $a * $c;
        if ($disc < 0.0) {
            return INF;   // they pass each other
        }
        return ($c / (-$b + sqrt($disc)));   // = (-b - sqrt(disc)) / a, stable for small a
    }

    /**
     * For a compound's own prediction: seconds until a body reaches its bounding sphere. A body already inside is the
     * one that probes (see compoundTime()): it is noted in $inside, and this gives INF.
     */
    private function entryTime(Node3D $compound, Node3D $body, float $now): float
    {
        $cAge = $now - $compound->mt;
        $bAge = $now - $body->mt;
        $dx = $body->mx + $body->mvx * $bAge - $compound->mx - $compound->mvx * $cAge;
        $dy = $body->my + $body->mvy * $bAge - $compound->my - $compound->mvy * $cAge;
        $dz = $body->mz + $body->mvz * $bAge - $compound->mz - $compound->mvz * $cAge;
        $reach = $compound->radius + $body->radius;
        if ($dx * $dx + $dy * $dy + $dz * $dz - $reach * $reach > 1e-9 * $reach * $reach) {
            return self::sphereImpact($dx, $dy, $dz, $body->mvx - $compound->mvx, $body->mvy - $compound->mvy, $body->mvz - $compound->mvz, $reach);
        }
        $this->inside[] = $body;
        return INF;
    }

    /**
     * Seconds until a body must next look at a compound: until it reaches the compound's bounding sphere, or, inside
     * it, as long as the smallest gap to a part lasts at the fastest the two could close in (the relative speed of
     * their origins, plus every turning node of the tree sweeping the whole sphere). 0 when a part is within the
     * tolerance: look now.
     */
    private function compoundTime(Node3D $compound, Node3D $body, float $now): float
    {
        $cAge = $now - $compound->mt;
        $bAge = $now - $body->mt;
        $dx = $body->mx + $body->mvx * $bAge - $compound->mx - $compound->mvx * $cAge;
        $dy = $body->my + $body->mvy * $bAge - $compound->my - $compound->mvy * $cAge;
        $dz = $body->mz + $body->mvz * $bAge - $compound->mz - $compound->mvz * $cAge;
        $vx = $body->mvx - $compound->mvx;
        $vy = $body->mvy - $compound->mvy;
        $vz = $body->mvz - $compound->mvz;
        $reach = $compound->radius + $body->radius;
        if ($dx * $dx + $dy * $dy + $dz * $dz - $reach * $reach > 1e-9 * $reach * $reach) {
            return self::sphereImpact($dx, $dy, $dz, $vx, $vy, $vz, $reach);   // outside: until it comes in
        }
        // inside, or on the boundary (where it is when it comes in): probe the parts
        // each part: its gap, and the fastest it can close in (the origins' relative speed, plus every node above
        // it turning it on its lever); the next look is when the first could touch
        $relative = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
        $step = INF;
        $gap = INF;
        [$x, $y, $z] = [$body->x, $body->y, $body->z];
        $this->placeParts($compound);
        foreach ($this->parts[spl_object_id($compound)] ?? [] as $i => $part) {
            $g = sqrt(($x - self::$partX[$i]) ** 2 + ($y - self::$partY[$i]) ** 2 + ($z - self::$partZ[$i]) ** 2) - $part->radius - $body->radius;
            $gap = min($gap, $g);
            $speed = $relative;
            foreach ($this->levers[spl_object_id($part)] as [$node, $lever]) {
                $speed += sqrt($node->mwx * $node->mwx + $node->mwy * $node->mwy + $node->mwz * $node->mwz) * $lever;
            }
            if ($speed > 0.0) {
                $step = min($step, ($g - self::PROBE_TOLERANCE) / $speed);
            }
        }
        if ($gap <= 2.0 * self::PROBE_TOLERANCE) {
            // in the contact zone: a touch now if they close in (and did not just touch), else wait a little
            $this->nearestGap($compound, $body);
            $touched = $this->lastTouch[spl_object_id($compound)] ?? null;
            $again = $touched !== null && $touched[0] === spl_object_id($body) && $touched[1] === $now;
            return $gap > self::PROBE_TOLERANCE && !$again && $this->closing($body) ? 0.0 : self::PROBE_PAUSE;
        }
        return max($step, 0.0);
    }

    /**
     * The contact of a body with the nearest part of a compound, if that part is within the tolerance and they close
     * in: the part's world position and velocity (its tree's motion, turning included), the normal from part to body.
     */
    private function touching(float $t, Node3D $compound, Node3D $body): ?Contact
    {
        $gap = $this->nearestGap($compound, $body);
        if ($gap <= self::PROBE_TOLERANCE || $gap > 2.0 * self::PROBE_TOLERANCE || !$this->closing($body)) {
            return null;
        }
        $this->lastTouch[spl_object_id($compound)] = [spl_object_id($body), $t];
        $c = new Contact($t, self::$nearest, $body);
        [$c->nx, $c->ny, $c->nz, $c->normalSpeed] = [self::$nx, self::$ny, self::$nz, self::$normalSpeed];
        return $c;
    }

    // what nearestGap() and closing() found: the nearest part, the normal from it to the body, the closing speed
    private static ?Node3D $nearest = null;
    private static float $nx = 0.0;
    private static float $ny = 0.0;
    private static float $nz = 0.0;
    private static float $normalSpeed = 0.0;

    /** The gap between a body and the nearest part of a compound (negative: overlapping), now. */
    private function nearestGap(Node3D $compound, Node3D $body): float
    {
        $gap = INF;
        self::$nearest = null;
        $this->placeParts($compound);
        foreach ($this->parts[spl_object_id($compound)] ?? [] as $i => $part) {
            $dx = $body->x - self::$partX[$i];
            $dy = $body->y - self::$partY[$i];
            $dz = $body->z - self::$partZ[$i];
            $d = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($d - $part->radius - $body->radius < $gap) {
                $gap = $d - $part->radius - $body->radius;
                self::$nearest = $part;
                $d = $d ?: 1.0;
                [self::$nx, self::$ny, self::$nz] = [$dx / $d, $dy / $d, $dz / $d];
            }
        }
        return $gap;
    }

    /**
     * The centres of a compound's parts now, into $partX/Y/Z in the order of its parts: one walk down the tree, one
     * matrix product per node (Node3D::getWorldMatrix() would walk up to the root for every part).
     */
    private function placeParts(Node3D $compound): void
    {
        $now = $this->world->clock->time;
        if (self::$placedFor === $compound && self::$placedAt === $now && self::$placedCounter === $compound->physicsCounter) {
            return;
        }
        [self::$placedFor, self::$placedAt, self::$placedCounter] = [$compound, $now, $compound->physicsCounter];
        $id = spl_object_id($compound);
        if (($this->offsets[$id][0] ?? -1) !== $compound->physicsCounter) {
            // until it next changes: if nothing below the root moves, the parts sit still in the root's space
            $this->offsets[$id] = [$compound->physicsCounter, self::stillBelow($compound) ? $this->offsetsOf($compound) : null];
        }
        $offsets = $this->offsets[$id][1];
        if ($offsets === null) {
            self::$placed = 0;
            $this->placeBelow($compound, $compound->getWorldMatrix(), 0);
            return;
        }
        $m = $compound->getWorldMatrix();   // the root's transform now: one turn and shift per part
        for ($i = 0, $n = count($offsets); $i < $n; $i += 3) {
            [$x, $y, $z] = [$offsets[$i], $offsets[$i + 1], $offsets[$i + 2]];
            $k = intdiv($i, 3);
            self::$partX[$k] = $m->m0 * $x + $m->m4 * $y + $m->m8 * $z + $m->m12;
            self::$partY[$k] = $m->m1 * $x + $m->m5 * $y + $m->m9 * $z + $m->m13;
            self::$partZ[$k] = $m->m2 * $x + $m->m6 * $y + $m->m10 * $z + $m->m14;
        }
    }

    private static function stillBelow(Node3D $node): bool
    {
        foreach ($node->children as $child) {
            if ($child->mvx != 0.0 || $child->mvy != 0.0 || $child->mvz != 0.0 || $child->mwx != 0.0 || $child->mwy != 0.0 || $child->mwz != 0.0 || !self::stillBelow($child)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<float> the parts' centres in the root's space, flat */
    private function offsetsOf(Node3D $compound): array
    {
        self::$placed = 0;
        $this->placeBelow($compound, self::$identity ??= \Phame\Math\Matrix4::get()->identity(), 0);
        $out = [];
        for ($i = 0; $i < self::$placed; ++$i) {
            array_push($out, self::$partX[$i], self::$partY[$i], self::$partZ[$i]);
        }
        return $out;
    }

    private function placeBelow(Node3D $node, \Phame\Math\Matrix4 $world, int $depth): void
    {
        $m = self::$levels[$depth] ??= \Phame\Math\Matrix4::get();
        foreach ($node->children as $child) {
            $m->product($world, $child->getLocalMatrix());
            if ($child->radius > 0.0) {
                self::$partX[self::$placed] = $m->m12;
                self::$partY[self::$placed] = $m->m13;
                self::$partZ[self::$placed] = $m->m14;
                ++self::$placed;
            }
            if ($child->children !== []) {
                $this->placeBelow($child, $m, $depth + 1);
            }
        }
    }

    /** Whether the body and the nearest part (see nearestGap()) close in, by the part's world velocity. */
    private function closing(Node3D $body): bool
    {
        self::$nearest->kineticState();   // its world velocity: the tree's motion at its centre
        self::$normalSpeed = ($body->velocityX - \Phame\Kinetic\Kinetic::$vx) * self::$nx + ($body->velocityY - \Phame\Kinetic\Kinetic::$vy) * self::$ny + ($body->velocityZ - \Phame\Kinetic\Kinetic::$vz) * self::$nz;
        return self::$normalSpeed < 0.0;
    }

    /**
     * Seconds until two tethered bodies are $length apart and moving apart: the other at offset d, with relative
     * velocity v. 0 if they are there now; INF if they never get there.
     */
    private static function tetherTaut(float $dx, float $dy, float $dz, float $vx, float $vy, float $vz, float $length): float
    {
        $a = $vx * $vx + $vy * $vy + $vz * $vz;
        if ($a === 0.0) {
            return INF;
        }
        $b = $dx * $dx + $dy * $dy + $dz * $dz - $length * $length;   // below 0: slack
        $d = $dx * $vx + $dy * $vy + $dz * $vz;                          // above 0: moving apart
        if ($b >= 0.0 && $d > 0.0) {
            return 0.0;
        }
        $disc = $d * $d - $a * $b;
        if ($disc < 0.0) {
            return INF;   // beyond the length (rounding) and closing in, never to reach it again: a bounce would not help
        }
        return (-$d + sqrt($disc)) / $a;   // the later root: where the slack runs out
    }

    /** The grid cell of a point, packed in one integer: x, y and z cell numbers as 21-bit fields, offset by 2^20. */
    private function cellOf(float $x, float $y, float $z): int
    {
        $s = $this->cellSize;
        return (((int) floor($x / $s) + 0x100000) << 42) | (((int) floor($y / $s) + 0x100000) << 21) | ((int) floor($z / $s) + 0x100000);
    }

    private function moveToCell(Node3D $b): void
    {
        $age = $this->world->clock->time - $b->mt + 1e-6;   // just ahead: on a cell's edge, the cell it moves into
        $cell = $this->cellOf($b->mx + $b->mvx * $age, $b->my + $b->mvy * $age, $b->mz + $b->mvz * $age);
        if ($cell === $b->physicsCell) {
            return;
        }
        $id = spl_object_id($b);
        unset($this->cells[$b->physicsCell][$id]);
        if (($this->cells[$b->physicsCell] ?? null) === []) {
            unset($this->cells[$b->physicsCell]);
        }
        $b->physicsCell = $cell;
        $this->cells[$cell][$id] = $b;
    }

    /** The time of the earliest pending event (possibly stale), or INF. */
    public function nextTime(): float
    {
        return $this->count > 0 ? $this->time[0] : INF;
    }

    private function push(float $time, Node3D $a, mixed $other, int $kind, int $ca, int $cb): void
    {
        $this->world->notifyEvent($time);
        $i = $this->count++;
        while ($i > 0) {
            $p = ($i - 1) >> 1;
            if ($this->time[$p] <= $time) {
                break;
            }
            $this->time[$i] = $this->time[$p];
            $this->body[$i] = $this->body[$p];
            $this->other[$i] = $this->other[$p];
            $this->kind[$i] = $this->kind[$p];
            $this->countA[$i] = $this->countA[$p];
            $this->countB[$i] = $this->countB[$p];
            $i = $p;
        }
        $this->time[$i] = $time;
        $this->body[$i] = $a;
        $this->other[$i] = $other;
        $this->kind[$i] = $kind;
        $this->countA[$i] = $ca;
        $this->countB[$i] = $cb;
    }

    private function pop(): void
    {
        $n = --$this->count;
        [$time, $body, $other, $kind, $ca, $cb] = [$this->time[$n], $this->body[$n], $this->other[$n], $this->kind[$n], $this->countA[$n], $this->countB[$n]];
        $this->body[$n] = $this->other[$n] = null;
        if ($n === 0) {
            return;
        }
        $i = 0;
        while (true) {
            $c = 2 * $i + 1;
            if ($c >= $n) {
                break;
            }
            if ($c + 1 < $n && $this->time[$c + 1] < $this->time[$c]) {
                ++$c;
            }
            if ($this->time[$c] >= $time) {
                break;
            }
            $this->time[$i] = $this->time[$c];
            $this->body[$i] = $this->body[$c];
            $this->other[$i] = $this->other[$c];
            $this->kind[$i] = $this->kind[$c];
            $this->countA[$i] = $this->countA[$c];
            $this->countB[$i] = $this->countB[$c];
            $i = $c;
        }
        $this->time[$i] = $time;
        $this->body[$i] = $body;
        $this->other[$i] = $other;
        $this->kind[$i] = $kind;
        $this->countA[$i] = $ca;
        $this->countB[$i] = $cb;
    }
}
