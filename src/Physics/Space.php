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
 */
final class Space
{
    public const PAIR = 0;
    public const WALL = 1;
    public const CELL = 2;

    /** @var array<int, Node3D> */
    private array $bodies = [];
    /** @var array<string, array<int, Node3D>> */
    private array $cells = [];
    /** @var array<int, Node3D> the bodies with a radius above half a cell */
    private array $large = [];
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
        if ($b->radius > $this->cellSize / 2) {
            $this->large[$id] = $b;
        }
        $this->predict($b);
    }

    public function remove(Node3D $b): void
    {
        $id = spl_object_id($b);
        unset($this->bodies[$id], $this->cells[$b->physicsCell][$id], $this->large[$id]);
        if (($this->cells[$b->physicsCell] ?? null) === []) {
            unset($this->cells[$b->physicsCell]);
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
        ++$b->physicsCounter;
        if ($b->radius > $this->cellSize / 2) {   // the radius may have changed too
            $this->large[spl_object_id($b)] = $b;
        } else {
            unset($this->large[spl_object_id($b)]);
        }
        $this->moveToCell($b);
        $this->predict($b);
    }

    /** Handles every event up to game time $until, in order, then leaves the clock at $until. */
    public function process(float $until): void
    {
        $clock = $this->world->clock;
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
            if ($kind === self::PAIR && ($b->physicsSpace !== $this || $b->physicsCounter !== $cb)) {
                $this->predict($a);   // b changed since: a's prediction no longer holds
                continue;
            }
            if ($kind === self::CELL) {
                $this->moveToCell($a);
                $this->predict($a);
                continue;
            }
            $this->world->collide($this->contact($t, $a, $kind === self::PAIR ? $b : Wall::side(intdiv($b, 2), $b % 2 === 1 ? 1 : -1)));
            if ($a->physicsSpace === $this) {
                $this->changed($a);
            }
            if ($kind === self::PAIR && $b->physicsSpace === $this) {
                $this->changed($b);
            }
        }
        $clock->travelTo($until);
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
        $x = $a->x;
        $y = $a->y;
        $z = $a->z;
        $vx = $a->velocityX;
        $vy = $a->velocityY;
        $vz = $a->velocityZ;
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
        [$cx, $cy, $cz] = explode(',', $a->physicsCell);
        $reach = $r > $this->cellSize / 2 ? (int) ceil(($r + $this->cellSize / 2) / $this->cellSize) : 1;
        for ($i = -$reach; $i <= $reach; ++$i) {
            for ($j = -$reach; $j <= $reach; ++$j) {
                for ($k = -$reach; $k <= $reach; ++$k) {
                    foreach ($this->cells[($cx + $i) . ',' . ($cy + $j) . ',' . ($cz + $k)] ?? [] as $b) {
                        if ($b === $a || ($b->radius > $this->cellSize / 2)) {   // large ones: below
                            continue;
                        }
                        $t = self::sphereImpact($b->x - $x, $b->y - $y, $b->z - $z, $b->velocityX - $vx, $b->velocityY - $vy, $b->velocityZ - $vz, $r + $b->radius);
                        if ($t < $best) {
                            [$best, $bestKind, $bestOther] = [$t, self::PAIR, $b];
                        }
                    }
                }
            }
        }

        foreach ($this->large as $b) {
            if ($b !== $a) {
                $t = self::sphereImpact($b->x - $x, $b->y - $y, $b->z - $z, $b->velocityX - $vx, $b->velocityY - $vy, $b->velocityZ - $vz, $r + $b->radius);
                if ($t < $best) {
                    [$best, $bestKind, $bestOther] = [$t, self::PAIR, $b];
                }
            }
        }

        // leaving the cell: the neighbourhood changes
        $s = $this->cellSize;
        foreach ([[$x, $vx, (int) $cx], [$y, $vy, (int) $cy], [$z, $vz, (int) $cz]] as [$p, $v, $c]) {
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

    private function cellOf(float $x, float $y, float $z): string
    {
        $s = $this->cellSize;
        return (int) floor($x / $s) . ',' . (int) floor($y / $s) . ',' . (int) floor($z / $s);
    }

    private function moveToCell(Node3D $b): void
    {
        $cell = $this->cellOf($b->x + $b->velocityX * 1e-6, $b->y + $b->velocityY * 1e-6, $b->z + $b->velocityZ * 1e-6);
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
