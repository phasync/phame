<?php

namespace Phame\Physics;

use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;

/**
 * Event-driven collision detection for the spheres of one Scene3D (event-driven molecular dynamics). Bodies move in
 * straight lines between events, so the time of every contact is computed, not searched for: each body keeps its
 * earliest contact, its next wall and its next crossing into another cell of the grid, and waits in a heap under the
 * earliest of the three (one place per body, updated in place: nothing stale piles up). Events are handled in time
 * order. Crossing into a cell looks only at the cells that just came within reach: the earliest contact with the
 * others is already known. Handling an event means calling the world's collision
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
    /** @var array<int, int> by compound body: the body it is currently touching, until the gap reopens past 2 * PROBE_TOLERANCE */
    private array $lastTouch = [];
    /** @var list<float> where placeParts() found the parts' centres, in the order of $parts */
    private static array $partX = [];
    private static array $partY = [];
    private static array $partZ = [];
    // which compound they are, when, and at which of its counters: placing again at the same moment gives the same
    private static ?Node3D $placedFor = null;
    private static float $placedAt = NAN;
    private static int $placedCounter = -1;
    /**
     * @var array<int, array{int, list<?array{float, float, float}>, array<int, true>}> by compound: at which of its
     * counters; each part's centre in the root's space while the joints above it are still (null while one turns);
     * and the nodes on the way down to the moving parts, the only ones a placement walks through
     */
    private array $offsets = [];
    /** @var array<int, int> by part: its index among its compound's parts */
    private array $partIndex = [];
    /** @var list<\Phame\Math\Matrix4> one transform per depth of a tree, for placement walks */
    private static array $levels = [];
    private static ?\Phame\Math\Matrix4 $scratch = null;
    /** @var list<?Node3D> the bodies, each once, as a heap by the time of their next event (Node3D::$physicsAt) */
    private array $heap = [];
    private int $count = 0;

    public function __construct(private readonly World $world, public readonly Scene3D $scene, public float $cellSize = 4.0)
    {
    }

    public int $bodyCount {
        get => count($this->bodies);
    }

    /** The scene root's Node3D::$gravity changed: every body's prediction assumed the old field, so all predict again. */
    public function refresh(): void
    {
        foreach ($this->bodies as $b) {
            $this->changed($b);
        }
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
                $this->partIndex[spl_object_id($child)] = count($parts);
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
                unset($this->levers[spl_object_id($part)], $this->partIndex[spl_object_id($part)]);
            }
            unset($this->parts[$id], $this->lastTouch[$id], $this->offsets[$id]);
        }
        $this->unschedule($b);
        $b->physicsSpace = null;
        $b->physicsPair = null;
        ++$b->physicsCounter;   // what others predicted with it is stale now
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
        while ($this->count > 0 && $this->heap[0]->physicsAt <= $until + 1e-12) {
            $a = $this->heap[0];
            $t = $a->physicsAt;
            $clock->travelTo($t);
            if ($a->physicsCellAt <= $a->physicsWallAt && $a->physicsCellAt <= $a->physicsPairAt) {
                $this->crossCell($a);
                continue;
            }
            if ($a->physicsWallAt <= $a->physicsPairAt) {
                $code = $a->physicsWall;
                $this->world->collide($this->contact($t, $a, Wall::side(intdiv($code, 2), $code % 2 === 1 ? 1 : -1)));
                if ($a->physicsSpace === $this) {
                    $this->changed($a);
                }
                $this->settle();
                continue;
            }
            $b = $a->physicsPair;
            if ($b->physicsSpace !== $this || $b->physicsCounter !== $a->physicsPairSeen) {
                $this->predict($a);   // b changed since: a's prediction no longer holds
                continue;
            }
            if ($a->physicsPairKind === self::PROBE) {
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
            $contact = $this->contact($t, $a, $b);
            if ($a->physicsPairKind === self::TETHER) {
                $contact->tether = true;
                [$contact->nx, $contact->ny, $contact->nz, $contact->normalSpeed] = [-$contact->nx, -$contact->ny, -$contact->nz, -$contact->normalSpeed];
            }
            $this->world->collide($contact);
            foreach ([$a, $b] as $body) {
                if ($body->physicsSpace === $this) {
                    $this->changed($body);
                }
            }
            $this->settle();
        }
        $clock->travelTo($until);
        $this->settle();
    }

    /**
     * The body crossed into another cell: only the cells that just came within reach are new (it can meet nothing
     * else without crossing again, and its earliest contact with the rest is known). A full look when that contact
     * no longer holds, at corners, and for large bodies, whose reach is wider.
     */
    private function crossCell(Node3D $a): void
    {
        $old = $a->physicsCell;
        $this->moveToCell($a);
        $new = $a->physicsCell;
        $d = [($new >> 42) - ($old >> 42), (($new >> 21) & 0x1FFFFF) - (($old >> 21) & 0x1FFFFF), ($new & 0x1FFFFF) - ($old & 0x1FFFFF)];
        $pair = $a->physicsPair;
        if ($a->compound || $a->radius > $this->cellSize / 2 || abs($d[0]) + abs($d[1]) + abs($d[2]) !== 1
            || ($pair !== null && ($pair->physicsSpace !== $this || $pair->physicsCounter !== $a->physicsPairSeen))) {
            $this->predict($a);
            return;
        }
        $now = $this->world->clock->time;
        $root = $this->scene->root;
        $gx = $root->gravityX;
        $gy = $root->gravityY;
        $gz = $root->gravityZ;
        $ax = $a->accX + $gx;
        $ay = $a->accY + $gy;
        $az = $a->accZ + $gz;
        $age = $now - $a->mt;
        $x = $a->mx + ($a->mvx + 0.5 * $ax * $age) * $age;
        $y = $a->my + ($a->mvy + 0.5 * $ay * $age) * $age;
        $z = $a->mz + ($a->mvz + 0.5 * $az * $age) * $age;
        $vx = $a->mvx + $ax * $age;
        $vy = $a->mvy + $ay * $age;
        $vz = $a->mvz + $az * $age;
        $r = $a->radius;
        $half = $this->cellSize / 2;
        $axis = $d[0] !== 0 ? 0 : ($d[1] !== 0 ? 1 : 2);
        for ($u = -1; $u <= 1; ++$u) {
            for ($w = -1; $w <= 1; ++$w) {
                // the far face of the new neighbourhood, one cell on along the axis it crossed
                $o = [0, 0, 0];
                $o[$axis] = $d[$axis];
                $o[($axis + 1) % 3] = $u;
                $o[($axis + 2) % 3] = $w;
                foreach ($this->cells[$new + ($o[0] << 42) + ($o[1] << 21) + $o[2]] ?? [] as $b) {
                    if ($b === $a || $b->radius > $half) {
                        continue;
                    }
                    $bax = $b->accX + $gx;
                    $bay = $b->accY + $gy;
                    $baz = $b->accZ + $gz;
                    $bAge = $now - $b->mt;
                    $bvx = $b->mvx + $bax * $bAge;
                    $bvy = $b->mvy + $bay * $bAge;
                    $bvz = $b->mvz + $baz * $bAge;
                    $t = $now + self::sphereImpact($b->mx + ($b->mvx + 0.5 * $bax * $bAge) * $bAge - $x, $b->my + ($b->mvy + 0.5 * $bay * $bAge) * $bAge - $y, $b->mz + ($b->mvz + 0.5 * $baz * $bAge) * $bAge - $z, $bvx - $vx, $bvy - $vy, $bvz - $vz, $r + $b->radius, $bax - $ax, $bay - $ay, $baz - $az);
                    if ($t < $a->physicsPairAt) {
                        [$a->physicsPairAt, $a->physicsPair, $a->physicsPairKind, $a->physicsPairSeen] = [$t, $b, self::PAIR, $b->physicsCounter];
                    }
                }
            }
        }
        $a->physicsCellAt = $now + $this->cellExit($a->physicsCell, $x, $y, $z, $vx, $vy, $vz, $ax, $ay, $az);
        $this->schedule($a);
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
     * Works out the body's earliest contact, wall and cell crossing, and puts it in the heap under the earliest. What
     * others predicted with it stays valid while its counter does (the counters only move when motion changes).
     */
    private function predict(Node3D $a): void
    {
        $now = $this->world->clock->time;
        if ($a->compound) {
            $this->inside = [];
        }
        // the motion descriptions, evaluated here: this loop is the engine's hottest, so no property hooks. Gravity
        // (Node3D::$gravity on the scene's root) is a frame field: every body here is its direct child, so it is
        // simply added to each body's own acceleration, no ancestor walk needed (see Node3D::kineticState() for the
        // general case, used elsewhere).
        $scene = $this->scene;
        $gx = $scene->root->gravityX;
        $gy = $scene->root->gravityY;
        $gz = $scene->root->gravityZ;
        $ax = $a->accX + $gx;
        $ay = $a->accY + $gy;
        $az = $a->accZ + $gz;
        $age = $now - $a->mt;
        $x = $a->mx + ($a->mvx + 0.5 * $ax * $age) * $age;
        $y = $a->my + ($a->mvy + 0.5 * $ay * $age) * $age;
        $z = $a->mz + ($a->mvz + 0.5 * $az * $age) * $age;
        $vx = $a->mvx + $ax * $age;
        $vy = $a->mvy + $ay * $age;
        $vz = $a->mvz + $az * $age;
        $r = $a->radius;
        $best = INF;
        $bestKind = self::PAIR;
        $bestOther = null;

        // walls of the scene's box: both faces of each axis, since acceleration can turn an approach into a
        // retreat and back (a throw that falls back down), unlike a body on a straight line
        $wall = INF;
        $wallCode = 0;
        foreach (!$a->confined ? [] : [[0, $x, $vx, $ax, $scene->worldMinX, $scene->worldMaxX], [1, $y, $vy, $ay, $scene->worldMinY, $scene->worldMaxY], [2, $z, $vz, $az, $scene->worldMinZ, $scene->worldMaxZ]] as [$axis, $p, $v, $acc, $min, $max]) {
            if ($max < INF && $p + $r <= $max + 1e-9) {
                $t = self::axisCrossing($p, $v, $acc, $max - $r, 1.0);
                if ($t < $wall) {
                    [$wall, $wallCode] = [$t, $axis * 2 + 1];
                }
            }
            if ($min > -INF && $p - $r >= $min - 1e-9) {
                $t = self::axisCrossing($p, $v, $acc, $min + $r, -1.0);
                if ($t < $wall) {
                    [$wall, $wallCode] = [$t, $axis * 2];
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
                        $bax = $b->accX + $gx;
                        $bay = $b->accY + $gy;
                        $baz = $b->accZ + $gz;
                        $bAge = $now - $b->mt;
                        $bvx = $b->mvx + $bax * $bAge;
                        $bvy = $b->mvy + $bay * $bAge;
                        $bvz = $b->mvz + $baz * $bAge;
                        $t = self::sphereImpact($b->mx + ($b->mvx + 0.5 * $bax * $bAge) * $bAge - $x, $b->my + ($b->mvy + 0.5 * $bay * $bAge) * $bAge - $y, $b->mz + ($b->mvz + 0.5 * $baz * $bAge) * $bAge - $z, $bvx - $vx, $bvy - $vy, $bvz - $vz, $r + $b->radius, $bax - $ax, $bay - $ay, $baz - $az);
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
                $bax = $b->accX + $gx;
                $bay = $b->accY + $gy;
                $baz = $b->accZ + $gz;
                $bAge = $now - $b->mt;
                $bvx = $b->mvx + $bax * $bAge;
                $bvy = $b->mvy + $bay * $bAge;
                $bvz = $b->mvz + $baz * $bAge;
                $t = self::sphereImpact($b->mx + ($b->mvx + 0.5 * $bax * $bAge) * $bAge - $x, $b->my + ($b->mvy + 0.5 * $bay * $bAge) * $bAge - $y, $b->mz + ($b->mvz + 0.5 * $baz * $bAge) * $bAge - $z, $bvx - $vx, $bvy - $vy, $bvz - $vz, $r + $b->radius, $bax - $ax, $bay - $ay, $baz - $az);
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

        $a->physicsWallAt = $now + $wall;
        $a->physicsWall = $wallCode;
        [$a->physicsPairAt, $a->physicsPair, $a->physicsPairKind, $a->physicsPairSeen] = [$now + $best, $bestOther, $bestKind, $bestOther?->physicsCounter ?? 0];
        $a->physicsCellAt = $now + $this->cellExit($cell, $x, $y, $z, $vx, $vy, $vz, $ax, $ay, $az);
        $this->schedule($a);
    }

    /** Seconds until a point moving at v and accelerating at a leaves the cell (just past whichever edge, either way). */
    private function cellExit(int $cell, float $x, float $y, float $z, float $vx, float $vy, float $vz, float $ax = 0.0, float $ay = 0.0, float $az = 0.0): float
    {
        $s = $this->cellSize;
        $exit = INF;
        foreach ([[$x, $vx, $ax, ($cell >> 42) - 0x100000], [$y, $vy, $ay, (($cell >> 21) & 0x1FFFFF) - 0x100000], [$z, $vz, $az, ($cell & 0x1FFFFF) - 0x100000]] as [$p, $v, $acc, $c]) {
            $exit = min($exit, self::axisCrossing($p, $v, $acc, ($c + 1) * $s, 1.0), self::axisCrossing($p, $v, $acc, $c * $s, -1.0));
        }
        return $exit === INF ? INF : $exit + 1e-9;
    }

    /**
     * Seconds until two spheres touch: one at the origin, the other at offset d moving at relative velocity v, the
     * radii summing to $r. INF if they never do, or touch or overlap already (they first have to separate). With no
     * relative acceleration (both share the world's gravity, the common case, or neither accelerates) the gap is the
     * exact quadratic below; a relative acceleration (ax, ay, az) makes it a quartic, solved by quarticRoot().
     */
    private static function sphereImpact(float $dx, float $dy, float $dz, float $vx, float $vy, float $vz, float $r, float $ax = 0.0, float $ay = 0.0, float $az = 0.0): float
    {
        if ($ax == 0.0 && $ay == 0.0 && $az == 0.0) {
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
        $c0 = $dx * $dx + $dy * $dy + $dz * $dz - $r * $r;
        if ($c0 <= 1e-9 * $r * $r) {
            return INF;   // as above: already touching or overlapping, wait for separation first
        }
        // |d + v*t + 0.5*a*t^2|^2 - r^2, a quartic in t (c4 > 0: at least one of ax, ay, az is not 0 here)
        $Ax = 0.5 * $ax;
        $Ay = 0.5 * $ay;
        $Az = 0.5 * $az;
        $c1 = 2.0 * ($dx * $vx + $dy * $vy + $dz * $vz);
        $c2 = 2.0 * ($dx * $Ax + $dy * $Ay + $dz * $Az) + ($vx * $vx + $vy * $vy + $vz * $vz);
        $c3 = 2.0 * ($vx * $Ax + $vy * $Ay + $vz * $Az);
        $c4 = $Ax * $Ax + $Ay * $Ay + $Az * $Az;
        return self::quarticRoot($c4, $c3, $c2, $c1, $c0);
    }

    /**
     * The smallest t >= 0 where c4*t^4 + c3*t^3 + c2*t^2 + c1*t + c0 first reaches 0 or below (an approach), or INF
     * if it never does. The caller has already ruled out c0 <= 0 (touching or overlapping at t = 0). f's critical
     * points are the real roots of its derivative, a cubic solved in closed form (cubicRoots()); f is monotone
     * between them, and strictly increasing past the last one since c4 > 0, so bisecting (to about 1e-12) the first
     * of those intervals that turns non-positive gives the earliest root.
     */
    private static function quarticRoot(float $c4, float $c3, float $c2, float $c1, float $c0): float
    {
        $f = static fn (float $t): float => ((($c4 * $t + $c3) * $t + $c2) * $t + $c1) * $t + $c0;
        $fp = static fn (float $t): float => ((4.0 * $c4 * $t + 3.0 * $c3) * $t + 2.0 * $c2) * $t + $c1;
        $points = [0.0];
        foreach (self::cubicRoots(4.0 * $c4, 3.0 * $c3, 2.0 * $c2, $c1) as $t) {
            if ($t > 0.0) {
                $points[] = $t;
            }
        }
        sort($points);
        $tail = end($points) + 1.0;
        while ($f($tail) < 0.0) {   // c4 > 0: f -> +INF eventually, so this always terminates
            $tail *= 2.0;
        }
        $points[] = $tail;
        for ($i = 0, $n = count($points) - 1; $i < $n; ++$i) {
            [$lo, $hi] = [$points[$i], $points[$i + 1]];
            if ($f($hi) > 0.0) {
                continue;   // monotone and still positive throughout: no root in this interval
            }
            for ($k = 0; $k < 100 && $hi - $lo > 1e-12; ++$k) {
                $mid = ($lo + $hi) / 2.0;
                if ($f($mid) > 0.0) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }
            if ($fp($hi) > 1e-9) {
                continue;   // the gap is not actually closing here (floating residue at a boundary): not a contact
            }
            return $hi;
        }
        return INF;
    }

    /** Real roots of a*t^3 + b*t^2 + c*t + d = 0 (a may be 0: falls back to quadraticRoots()), in no particular order. */
    private static function cubicRoots(float $a, float $b, float $c, float $d): array
    {
        if (abs($a) < 1e-14) {
            return self::quadraticRoots($b, $c, $d);
        }
        $b /= $a;
        $c /= $a;
        $d /= $a;
        $p = $c - $b * $b / 3.0;
        $q = 2.0 * $b * $b * $b / 27.0 - $b * $c / 3.0 + $d;
        $shift = -$b / 3.0;
        $disc = $q * $q / 4.0 + $p * $p * $p / 27.0;
        if ($disc > 0.0) {
            $sq = sqrt($disc);
            return [self::cbrt(-$q / 2.0 + $sq) + self::cbrt(-$q / 2.0 - $sq) + $shift];
        }
        if ($disc == 0.0) {
            $u = self::cbrt(-$q / 2.0);
            return [2.0 * $u + $shift, -$u + $shift];
        }
        $r = sqrt(-$p * $p * $p / 27.0);
        $phi = acos(max(-1.0, min(1.0, -$q / (2.0 * $r))));
        $m = 2.0 * sqrt(-$p / 3.0);
        return [
            $m * cos($phi / 3.0) + $shift,
            $m * cos(($phi + 2.0 * M_PI) / 3.0) + $shift,
            $m * cos(($phi + 4.0 * M_PI) / 3.0) + $shift,
        ];
    }

    /** Real roots of a*t^2 + b*t + c = 0 (a may be 0: falls back to the linear root), in no particular order. */
    private static function quadraticRoots(float $a, float $b, float $c): array
    {
        if (abs($a) < 1e-14) {
            return abs($b) < 1e-14 ? [] : [-$c / $b];
        }
        $disc = $b * $b - 4.0 * $a * $c;
        if ($disc < 0.0) {
            return [];
        }
        $sq = sqrt($disc);
        return [(-$b - $sq) / (2.0 * $a), (-$b + $sq) / (2.0 * $a)];
    }

    private static function cbrt(float $x): float
    {
        return $x >= 0.0 ? $x ** (1.0 / 3.0) : -((-$x) ** (1.0 / 3.0));
    }

    /**
     * The smallest t >= 0 where p, moving at v and accelerating at a, reaches target while actually arriving there:
     * $direction is +1 if target lies above (arriving while increasing) or -1 if below (arriving while decreasing).
     * The quadratic's other root, where p merely touches target while already heading away, is not a crossing --
     * without this, a body resting exactly on a boundary (after a bounce left it there, say) finds a spurious t = 0
     * "contact" there forever, the wall equivalent of sphereImpact()'s "already touching" guard.
     */
    private static function axisCrossing(float $p, float $v, float $a, float $target, float $direction): float
    {
        $best = INF;
        foreach (self::quadraticRoots(0.5 * $a, $v, $p - $target) as $t) {
            if ($t < -1e-9 || $t >= $best || ($v + $a * $t) * $direction < -1e-9) {
                continue;   // in the past, not the earliest found so far, or touching while heading away
            }
            $best = max($t, 0.0);
        }
        return $best;
    }

    /** The smaller non-negative root of a*t^2 + b*t + c = 0 (a may be 0: the linear root), or INF. */
    private static function smallestRoot(float $a, float $b, float $c): float
    {
        if ($a == 0.0) {
            if ($b == 0.0) {
                return INF;
            }
            $t = -$c / $b;
            return $t >= -1e-9 ? max($t, 0.0) : INF;
        }
        $disc = $b * $b - 4.0 * $a * $c;
        if ($disc < 0.0) {
            return INF;
        }
        $sq = sqrt($disc);
        $t1 = (-$b - $sq) / (2.0 * $a);
        $t2 = (-$b + $sq) / (2.0 * $a);
        if ($t1 > $t2) {
            [$t1, $t2] = [$t2, $t1];
        }
        if ($t1 >= -1e-9) {
            return max($t1, 0.0);
        }
        return $t2 >= -1e-9 ? max($t2, 0.0) : INF;
    }

    /**
     * For a compound's own prediction: seconds until a body reaches its bounding sphere. A body already inside is the
     * one that probes (see compoundTime()): it is noted in $inside, and this gives INF.
     */
    private function entryTime(Node3D $compound, Node3D $body, float $now): float
    {
        // both are direct children of the scene's root (Space's invariant): gravity is the same for each and
        // cancels out of the relative acceleration below, leaving just their own (body->accX - compound->accX)
        $cAge = $now - $compound->mt;
        $bAge = $now - $body->mt;
        $dx = $body->mx + ($body->mvx + 0.5 * $body->accX * $bAge) * $bAge - $compound->mx - ($compound->mvx + 0.5 * $compound->accX * $cAge) * $cAge;
        $dy = $body->my + ($body->mvy + 0.5 * $body->accY * $bAge) * $bAge - $compound->my - ($compound->mvy + 0.5 * $compound->accY * $cAge) * $cAge;
        $dz = $body->mz + ($body->mvz + 0.5 * $body->accZ * $bAge) * $bAge - $compound->mz - ($compound->mvz + 0.5 * $compound->accZ * $cAge) * $cAge;
        $vx = $body->mvx + $body->accX * $bAge - $compound->mvx - $compound->accX * $cAge;
        $vy = $body->mvy + $body->accY * $bAge - $compound->mvy - $compound->accY * $cAge;
        $vz = $body->mvz + $body->accZ * $bAge - $compound->mvz - $compound->accZ * $cAge;
        $reach = $compound->radius + $body->radius;
        if ($dx * $dx + $dy * $dy + $dz * $dz - $reach * $reach > 1e-9 * $reach * $reach) {
            return self::sphereImpact($dx, $dy, $dz, $vx, $vy, $vz, $reach, $body->accX - $compound->accX, $body->accY - $compound->accY, $body->accZ - $compound->accZ);
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
        $dx = $body->mx + ($body->mvx + 0.5 * $body->accX * $bAge) * $bAge - $compound->mx - ($compound->mvx + 0.5 * $compound->accX * $cAge) * $cAge;
        $dy = $body->my + ($body->mvy + 0.5 * $body->accY * $bAge) * $bAge - $compound->my - ($compound->mvy + 0.5 * $compound->accY * $cAge) * $cAge;
        $dz = $body->mz + ($body->mvz + 0.5 * $body->accZ * $bAge) * $bAge - $compound->mz - ($compound->mvz + 0.5 * $compound->accZ * $cAge) * $cAge;
        $vx = $body->mvx + $body->accX * $bAge - $compound->mvx - $compound->accX * $cAge;
        $vy = $body->mvy + $body->accY * $bAge - $compound->mvy - $compound->accY * $cAge;
        $vz = $body->mvz + $body->accZ * $bAge - $compound->mvz - $compound->accZ * $cAge;
        $relAx = $body->accX - $compound->accX;   // gravity is the same for both (direct children of the root): cancels
        $relAy = $body->accY - $compound->accY;
        $relAz = $body->accZ - $compound->accZ;
        $reach = $compound->radius + $body->radius;
        if ($dx * $dx + $dy * $dy + $dz * $dz - $reach * $reach > 1e-9 * $reach * $reach) {
            return self::sphereImpact($dx, $dy, $dz, $vx, $vy, $vz, $reach, $relAx, $relAy, $relAz);   // outside: until it comes in
        }
        // inside, or on the boundary (where it is when it comes in): probe the parts
        // each part: its gap, and the fastest it can close in (the origins' relative speed and acceleration, plus
        // every node above it turning it on its lever); the next look is when the first could touch
        $relative = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
        $relAccel = sqrt($relAx * $relAx + $relAy * $relAy + $relAz * $relAz);
        $step = INF;
        $gap = INF;
        $gapSpeed = 0.0;   // the bound for whichever part turned out nearest (see $gap below)
        [$x, $y, $z] = [$body->x, $body->y, $body->z];
        $this->placeParts($compound);
        foreach ($this->parts[spl_object_id($compound)] ?? [] as $i => $part) {
            $g = sqrt(($x - self::$partX[$i]) ** 2 + ($y - self::$partY[$i]) ** 2 + ($z - self::$partZ[$i]) ** 2) - $part->radius - $body->radius;
            $speed = $relative;
            foreach ($this->levers[spl_object_id($part)] as [$node, $lever]) {
                $speed += sqrt($node->mwx * $node->mwx + $node->mwy * $node->mwy + $node->mwz * $node->mwz) * $lever;
            }
            if ($g < $gap) {
                [$gap, $gapSpeed] = [$g, $speed];
            }
            // aims for the middle of the contact zone, not its near edge: an exact speed bound (no rotation, no
            // overestimate at all) would otherwise land this step exactly on PROBE_TOLERANCE, which touching()
            // excludes, and PROBE_PAUSE stepping from there never recovers (see EdgeCaseTest's former
            // baseline-failure). The acceleration bound (|a| over the step) is folded in the same way a lever is: it
            // can only close the gap faster than $speed alone predicts, never slower.
            $t = self::smallestRoot(0.5 * $relAccel, $speed, 1.5 * self::PROBE_TOLERANCE - $g);
            if ($t < $step) {
                $step = $t;
            }
        }
        if ($gap > 2.0 * self::PROBE_TOLERANCE) {
            if (($this->lastTouch[spl_object_id($compound)] ?? null) === spl_object_id($body)) {
                unset($this->lastTouch[spl_object_id($compound)]);   // separated: a later approach may touch again
            }
            return max($step, 0.0);
        }
        // in the contact zone: a touch now if they close in and are not already touching, else wait -- capped so
        // that even a fast closing speed cannot cross the open window (tolerance, 2 * tolerance] in one pause
        $this->nearestGap($compound, $body);
        $again = ($this->lastTouch[spl_object_id($compound)] ?? null) === spl_object_id($body);
        if ($gap > self::PROBE_TOLERANCE && !$again && $this->closing($body)) {
            return 0.0;
        }
        $cap = self::smallestRoot(0.5 * $relAccel, $gapSpeed, -self::PROBE_TOLERANCE / 2.0);
        return $cap === INF ? self::PROBE_PAUSE : min(self::PROBE_PAUSE, $cap);
    }

    /**
     * The contact of a body with the nearest part of a compound, if that part is within the tolerance and they close
     * in: the part's world position and velocity (its tree's motion, turning included), the normal from part to body.
     */
    private function touching(float $t, Node3D $compound, Node3D $body): ?Contact
    {
        $gap = $this->nearestGap($compound, $body);
        if ($gap <= self::PROBE_TOLERANCE || $gap > 2.0 * self::PROBE_TOLERANCE || !$this->closing($body)
            || ($this->lastTouch[spl_object_id($compound)] ?? null) === spl_object_id($body)) {
            return null;   // already touching: no new contact until the gap reopens (see compoundTime())
        }
        $this->lastTouch[spl_object_id($compound)] = spl_object_id($body);
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
            $this->offsets[$id] = [$compound->physicsCounter, ...$this->offsetsOf($compound)];   // until it next changes
        }
        $m = $compound->getWorldMatrix();   // the root's transform now: a still part is one turn and shift
        [, $offsets, $walk] = $this->offsets[$id];
        if ($walk !== []) {
            $this->placeMoving($compound, $m, $walk, 0);   // the moving parts: one walk down their branches
        }
        foreach ($offsets as $i => $offset) {
            if ($offset === null) {
                continue;
            }
            [$x, $y, $z] = $offset;
            self::$partX[$i] = $m->m0 * $x + $m->m4 * $y + $m->m8 * $z + $m->m12;
            self::$partY[$i] = $m->m1 * $x + $m->m5 * $y + $m->m9 * $z + $m->m13;
            self::$partZ[$i] = $m->m2 * $x + $m->m6 * $y + $m->m10 * $z + $m->m14;
        }
    }

    /** Places the parts below moving nodes: down through the nodes in $walk only, one product per node. */
    private function placeMoving(Node3D $node, \Phame\Math\Matrix4 $world, array $walk, int $depth): void
    {
        $m = self::$levels[$depth] ??= \Phame\Math\Matrix4::get();
        foreach ($node->children as $child) {
            if (!isset($walk[spl_object_id($child)])) {
                continue;
            }
            $m->product($world, $child->getLocalMatrix());
            if ($child->radius > 0.0) {
                $i = $this->partIndex[spl_object_id($child)];
                [self::$partX[$i], self::$partY[$i], self::$partZ[$i]] = [$m->m12, $m->m13, $m->m14];
            }
            if ($child->children !== []) {
                $this->placeMoving($child, $m, $walk, $depth + 1);
            }
        }
    }

    /**
     * Each part's centre in the root's space, where every node between them is still (it then stays there until the
     * compound next changes); null where one moves. And the nodes from the root down to the moving parts.
     *
     * @return array{list<?array{float, float, float}>, array<int, true>}
     */
    private function offsetsOf(Node3D $compound): array
    {
        $out = [];
        $walk = [];
        $m = self::$scratch ??= \Phame\Math\Matrix4::get();
        foreach ($this->parts[spl_object_id($compound)] as $part) {
            $still = true;
            for ($n = $part; $n !== $compound; $n = $n->parent) {
                if ($n->mvx != 0.0 || $n->mvy != 0.0 || $n->mvz != 0.0 || $n->mwx != 0.0 || $n->mwy != 0.0 || $n->mwz != 0.0) {
                    $still = false;
                    break;
                }
            }
            if (!$still) {
                $out[] = null;
                for ($n = $part; $n !== $compound; $n = $n->parent) {
                    $walk[spl_object_id($n)] = true;
                }
                continue;
            }
            $m->copyFrom($part->getLocalMatrix());
            for ($n = $part->parent; $n !== $compound; $n = $n->parent) {
                $m->product($n->getLocalMatrix(), $m);
            }
            $out[] = [$m->m12, $m->m13, $m->m14];
        }
        return [$out, $walk];
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

    /** The time of the earliest event, or INF. */
    public function nextTime(): float
    {
        return $this->count > 0 ? $this->heap[0]->physicsAt : INF;
    }

    /** Puts the body in the heap, or moves it, by the earliest of its contact, wall and cell crossing. */
    private function schedule(Node3D $a): void
    {
        $at = min($a->physicsPairAt, $a->physicsWallAt, $a->physicsCellAt);
        $a->physicsAt = $at;
        if ($at < INF) {
            $this->world->notifyEvent($at);
        }
        if ($a->physicsSlot < 0) {
            $a->physicsSlot = $this->count;
            $this->heap[$this->count++] = $a;
        }
        $this->siftUp($a->physicsSlot);
        $this->siftDown($a->physicsSlot);
    }

    private function unschedule(Node3D $a): void
    {
        $i = $a->physicsSlot;
        if ($i < 0) {
            return;
        }
        $a->physicsSlot = -1;
        $last = $this->heap[--$this->count];
        $this->heap[$this->count] = null;
        if ($last !== $a) {
            $this->heap[$i] = $last;
            $last->physicsSlot = $i;
            $this->siftUp($i);
            $this->siftDown($last->physicsSlot);
        }
    }

    private function siftUp(int $i): void
    {
        $a = $this->heap[$i];
        $at = $a->physicsAt;
        while ($i > 0) {
            $p = ($i - 1) >> 1;
            $q = $this->heap[$p];
            if ($q->physicsAt <= $at) {
                break;
            }
            $this->heap[$i] = $q;
            $q->physicsSlot = $i;
            $i = $p;
        }
        $this->heap[$i] = $a;
        $a->physicsSlot = $i;
    }

    private function siftDown(int $i): void
    {
        $a = $this->heap[$i];
        $at = $a->physicsAt;
        $n = $this->count;
        while (true) {
            $c = 2 * $i + 1;
            if ($c >= $n) {
                break;
            }
            if ($c + 1 < $n && $this->heap[$c + 1]->physicsAt < $this->heap[$c]->physicsAt) {
                ++$c;
            }
            $q = $this->heap[$c];
            if ($q->physicsAt >= $at) {
                break;
            }
            $this->heap[$i] = $q;
            $q->physicsSlot = $i;
            $i = $c;
        }
        $this->heap[$i] = $a;
        $a->physicsSlot = $i;
    }
}
