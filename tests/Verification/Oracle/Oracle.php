<?php

namespace Phame\Tests\Verification\Oracle;

/**
 * The reference oracle (see docs/PHYSICS-PLAN.md, "Phase 0"): a dense fixed-step simulation of a {@see SceneSpec},
 * sharing no code with the engine (its own 3x3 matrices, not Phame\Math\Matrix4 or quaternions; its own impulse
 * formulas, not Phame\Physics\Contact). Spheres, axis-aligned walls, tethers and turning compound trees, all parts
 * against all parts, no grid.
 *
 * Positions and orientations are always exact closed-form functions of time (straight lines between velocity
 * changes, exact rotation matrices from a spin schedule): the step size $dt only controls how finely new contacts
 * are searched for, by sampling the gap between every pair at each step's ends and bisecting into any step where it
 * crossed from positive to non-positive. A compound's parts are kinematic: a contact can change a free body's
 * velocity, never a part's, so the tree's own motion needs no re-derivation after a hit (see docs/VERIFICATION.md).
 *
 * @phpstan-type Event array{time: float, kind: string, a: string, b: string}
 */
final class Oracle
{
    /** How much separation at a step's start counts as "not already touching" (avoids re-triggering the event just handled). */
    private const GAP_EPS = 1e-9;
    /** Bisection stops refining a crossing once the bracket is this narrow. */
    private const BISECT_EPS = 1e-13;

    /** @var list<array{time: float, kind: string, a: string, b: string}> every contact found, in order */
    public array $events = [];

    /** @var list<list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}>> per body: [t0, x, y, z, vx, vy, vz] segments, ascending */
    private array $history = [];
    /** @var list<float> per node: the sum of offset lengths from its compound body down to it - a safe over-estimate of any ancestor's own distance to it, see speedBound() */
    private array $leverSum = [];

    public function __construct(private readonly SceneSpec $spec)
    {
        $n = count($spec->bodyX);
        for ($i = 0; $i < $n; ++$i) {
            $this->history[$i] = [[0.0, $spec->bodyX[$i], $spec->bodyY[$i], $spec->bodyZ[$i], $spec->bodyVX[$i], $spec->bodyVY[$i], $spec->bodyVZ[$i]]];
        }
        foreach ($spec->nodeParentIsBody as $j => $isBody) {
            $len = sqrt($spec->nodeOffsetX[$j] ** 2 + $spec->nodeOffsetY[$j] ** 2 + $spec->nodeOffsetZ[$j] ** 2);
            $this->leverSum[$j] = $len + ($isBody ? 0.0 : $this->leverSum[$spec->nodeParentIndex[$j]]);
        }
    }

    /** Runs the scene from t = 0 to $until, sampling every $dt and bisecting crossings into exact contact times. */
    public function run(float $dt, float $until): void
    {
        $t = 0.0;
        while ($t < $until - 1e-12) {
            $tEnd = min($t + $dt, $until);
            $resolved = [];   // pairs/walls already handled this window, so a second one at the very same instant is not missed
            $cursor = $t;
            while (true) {
                $found = $this->earliestCrossing($t, $cursor, $tEnd, $resolved);
                if ($found === null) {
                    break;
                }
                [$at, $kind, $a, $b] = $found;
                $this->apply($at, $kind, $a, $b);
                $this->events[] = ['time' => $at, 'kind' => $kind, 'a' => $a, 'b' => $b];
                $resolved[$kind . ':' . $a . ',' . $b] = true;
                $cursor = $at;
            }
            $t = $tEnd;
        }
    }

    /** World position (x, y, z) of body "B{i}" or part "N{j}" at time $t, from its own exact segment, no stepping. */
    public function positionAt(string $id, float $t): array
    {
        $i = (int) substr($id, 1);
        $s = $id[0] === 'B' ? $this->bodyState($i, $t) : $this->nodeState($i, $t);
        return [$s[0], $s[1], $s[2]];
    }

    /**
     * The earliest crossing starting after the point each pair is allowed to look from, up to $t1, or null.
     * $windowStart0/$windowStart (fixed for the whole dt window, see run()) is what a pair not yet resolved this
     * window looks from: using the live cursor there would miss a second, unrelated event that happens to fall at
     * (or past) the exact instant the first one did, because by then that pair's own gap already reads ~0 too (not a
     * graze, just two independent events at the same time: a round-number coincidence in a generated scene, or truly
     * simultaneous contacts). A pair already resolved this window (in $resolved) is the exception: it must look from
     * the live cursor, or the event just handled would be found again.
     *
     * Each candidate is tracked with march() rather than just sampling the two ends, so a fast body cannot tunnel
     * through a thin one between samples (see march()'s docblock).
     *
     * @return array{0: float, 1: string, 2: string, 3: string}|null [time, kind, idA, idB]
     */
    private function earliestCrossing(float $windowStart0, float $cursor, float $t1, array $resolved): ?array
    {
        $spec = $this->spec;
        $best = null;
        $bestAt = INF;

        // walls: each confined body against the box it is in
        foreach ($spec->bodyX as $i => $_) {
            if (!$spec->bodyConfined[$i]) {
                continue;
            }
            $r = $spec->bodyRadius[$i];
            // side = +1 for the low (min) wall, -1 for the high (max) wall: gap = side * (pos - bound) - r
            foreach ([[0, $spec->minX, 1], [0, $spec->maxX, -1], [1, $spec->minY, 1], [1, $spec->maxY, -1], [2, $spec->minZ, 1], [2, $spec->maxZ, -1]] as [$axis, $bound, $side]) {
                if (!is_finite($bound)) {
                    continue;
                }
                $wallId = "wall{$axis}" . ($side > 0 ? '-' : '+');
                $fresh = !isset($resolved['wall:' . "B{$i}" . ',' . $wallId]);
                $from = $fresh ? $windowStart0 : $cursor;
                $gapAt = fn (float $t) => $side * ($this->bodyState($i, $t)[$axis] - $bound) - $r;
                $speed = abs($this->bodyState($i, $from)[3 + $axis]);   // exact: a free body's velocity is constant over this bracket
                $at = $this->findCrossing($from, $t1, $gapAt, $speed);
                if ($at !== null && $at < $bestAt) {
                    [$bestAt, $best] = [$at, [$at, 'wall', "B{$i}", $wallId]];
                }
            }
        }

        // free-free, free-part and tethered pairs: all collidable entities against each other, see pairs()
        foreach ($this->pairs() as [$kind, $idA, $idB, $extra]) {
            $fresh = !isset($resolved[$kind . ':' . $idA . ',' . $idB]);
            $from = $fresh ? $windowStart0 : $cursor;
            $gapAt = fn (float $t) => $this->gap($kind, $idA, $idB, $extra, $t);
            $speed = $this->speedBound($idA, $from) + $this->speedBound($idB, $from);
            $at = $this->findCrossing($from, $t1, $gapAt, $speed);
            if ($at !== null) {
                if ($kind === 'tether' && !$this->separating($idA, $idB, $at)) {
                    continue;   // closing in past the length (rounding): never a real tether event, see Space::tetherTaut
                }
                if ($at < $bestAt) {
                    [$bestAt, $best] = [$at, [$at, $kind, $idA, $idB]];
                }
            }
        }
        return $best;
    }

    /**
     * Finds the earliest zero of $gapAt in (from, t1], or null if there is none - without ever trusting a stretch of
     * time the gap could not be proven to stay positive across, given $speed (a conservative bound, constant over
     * this whole bracket, on how fast the gap can change). Null if $gapAt starts at or below zero (not fresh: the
     * caller decides what "fresh" means).
     *
     * This is conservative (interval) root isolation, not a step-by-speed march: a window is only trusted whole when
     * the smaller of its two end gaps minus speed times its width is still positive - the gap cannot have reached
     * zero anywhere inside it then, however it got there. Otherwise the window is halved and each half checked the
     * same way, down to BISECT_EPS. This is what keeps a fast body from tunnelling through a thin one between two
     * plain samples dt apart (the usual failure of fixed-step sampling) while still finding a graze whose true dip is
     * far narrower than dt and whose closing rate near the dip is far below $speed (most of it tangential motion) -
     * exactly the case a simple "step by gap / speed" march gets stuck on, taking ever-smaller steps that approach
     * but never reach the dip. Each halving that cannot be ruled out still costs only one more gapAt() call per side.
     */
    private function findCrossing(float $from, float $t1, \Closure $gapAt, float $speed): ?float
    {
        $g0 = $gapAt($from);
        if ($g0 <= self::GAP_EPS) {
            return null;
        }
        return $this->isolate($from, $t1, $gapAt, $speed, $g0, $gapAt($t1), 0);
    }

    private function isolate(float $a, float $b, \Closure $gapAt, float $speed, float $ga, float $gb, int $depth): ?float
    {
        if ($ga > 0.0 && $gb <= 0.0) {
            return $this->bisect($a, $b, $gapAt);   // a clean bracket: finish with ordinary bisection
        }
        if ($b - $a <= self::BISECT_EPS) {
            return $gb <= 0.0 ? $b : null;
        }
        if (min($ga, $gb) - $speed * ($b - $a) > 0.0 || $depth > 200) {
            return null;   // provably could not have reached zero anywhere in [a, b] at this speed (or gave up: see below)
        }
        $mid = ($a + $b) / 2;
        $gm = $gapAt($mid);
        return $this->isolate($a, $mid, $gapAt, $speed, $ga, $gm, $depth + 1)
            ?? $this->isolate($mid, $b, $gapAt, $speed, $gm, $gb, $depth + 1);
    }

    /** A conservative bound on how fast an entity's world position can move, for march(): exact for a free body (its velocity is constant over the bracket in question), an over-estimate for a part (its own translation plus every ancestor's angular speed times a lever long enough to cover any of them, so it is always safe, if sometimes loose). */
    private function speedBound(string $id, float $t): float
    {
        if ($id[0] === 'B') {
            $b = $this->bodyState((int) substr($id, 1), $t);
            return sqrt($b[3] ** 2 + $b[4] ** 2 + $b[5] ** 2);
        }
        $spec = $this->spec;
        $j = (int) substr($id, 1);
        $angular = 0.0;
        for ($n = $j;; $n = $spec->nodeParentIndex[$n]) {
            $w = $this->activeSpin($spec->nodeSpinStart[$n], $spec->nodeSpinX[$n], $spec->nodeSpinY[$n], $spec->nodeSpinZ[$n], $t);
            $angular += sqrt($w[0] ** 2 + $w[1] ** 2 + $w[2] ** 2);
            if ($spec->nodeParentIsBody[$n]) {
                $body = $spec->nodeParentIndex[$n];
                $bw = $this->activeSpin($spec->bodySpinStart[$body], $spec->bodySpinX[$body], $spec->bodySpinY[$body], $spec->bodySpinZ[$body], $t);
                $angular += sqrt($bw[0] ** 2 + $bw[1] ** 2 + $bw[2] ** 2);
                $b = $this->bodyState($body, $t);
                return sqrt($b[3] ** 2 + $b[4] ** 2 + $b[5] ** 2) + $this->leverSum[$j] * $angular;
            }
        }
    }

    /**
     * The pairs of entities that can collide, as [kind, idA, idB, extra] (extra: a tether's length, else null): free
     * bodies and parts, excluding two parts of the same compound (or of different compounds: compounds pass through
     * each other, matching Space). Tethers add their own pairs, by length rather than a gap of radii.
     */
    private function pairs(): \Generator
    {
        $spec = $this->spec;
        $free = [];
        foreach ($spec->bodyX as $i => $_) {
            if (!$spec->bodyCompound[$i]) {
                $free[] = "B{$i}";
            }
        }
        $parts = [];
        foreach ($spec->nodeRadius as $j => $r) {
            if ($r > 0.0) {
                $parts[] = 'N' . $j;
            }
        }
        for ($i = 0, $n = count($free); $i < $n; ++$i) {
            for ($k = $i + 1; $k < $n; ++$k) {
                yield ['pair', $free[$i], $free[$k], null];
            }
            foreach ($parts as $partId) {
                yield ['pair', $free[$i], $partId, null];
            }
        }
        foreach ($spec->tetherA as $k => $a) {
            yield ['tether', "B{$a}", "B{$spec->tetherB[$k]}", $spec->tetherLength[$k]];
        }
    }

    /** The gap between two entities (positive: apart) at $t: radii summed, or a tether's slack. */
    private function gap(string $kind, string $idA, string $idB, ?float $length, float $t): float
    {
        [$ax, $ay, $az, $ar] = $this->entity($idA, $t);
        [$bx, $by, $bz, $br] = $this->entity($idB, $t);
        $d = sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2 + ($bz - $az) ** 2);
        return $kind === 'tether' ? $length - $d : $d - $ar - $br;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} position and radius, at $t */
    private function entity(string $id, float $t): array
    {
        $i = (int) substr($id, 1);
        if ($id[0] === 'B') {
            $s = $this->bodyState($i, $t);
            return [$s[0], $s[1], $s[2], $this->spec->bodyRadius[$i]];
        }
        $s = $this->nodeState($i, $t);
        return [$s[0], $s[1], $s[2], $this->spec->nodeRadius[$i]];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: Mat3, 7: float, 8: float, 9: float} x, y, z, vx, vy, vz, world rotation, world angular velocity */
    private function bodyState(int $i, float $t): array
    {
        $segments = $this->history[$i];
        $seg = $segments[0];
        for ($k = count($segments) - 1; $k >= 0; --$k) {
            if ($segments[$k][0] <= $t + 1e-12) {
                $seg = $segments[$k];
                break;
            }
        }
        [$t0, $x, $y, $z, $vx, $vy, $vz] = $seg;
        $age = $t - $t0;
        $rot = $this->scheduleRotation($this->spec->bodySpinStart[$i], $this->spec->bodySpinX[$i], $this->spec->bodySpinY[$i], $this->spec->bodySpinZ[$i], $t);
        $w = $this->activeSpin($this->spec->bodySpinStart[$i], $this->spec->bodySpinX[$i], $this->spec->bodySpinY[$i], $this->spec->bodySpinZ[$i], $t);
        return [$x + $vx * $age, $y + $vy * $age, $z + $vz * $age, $vx, $vy, $vz, $rot, $w[0], $w[1], $w[2]];
    }

    /**
     * Forward kinematics of node $j at $t, walking up to its compound body (a short chain, not every node in the
     * scene - march() calls this often, for one pair at a time, so staying off the O(every body/node) bulk methods
     * above matters): world position, world velocity (v = v_parent + ω_parent × r), the node's own world rotation
     * and world angular velocity (for a further child of its own, recursively).
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: Mat3, 7: float, 8: float, 9: float}
     */
    private function nodeState(int $j, float $t): array
    {
        $spec = $this->spec;
        [$px, $py, $pz, $pvx, $pvy, $pvz, $prot, $pwx, $pwy, $pwz] = $spec->nodeParentIsBody[$j] ? $this->bodyState($spec->nodeParentIndex[$j], $t) : $this->nodeState($spec->nodeParentIndex[$j], $t);
        $local = $this->scheduleRotation($spec->nodeSpinStart[$j], $spec->nodeSpinX[$j], $spec->nodeSpinY[$j], $spec->nodeSpinZ[$j], $t);
        $rot = $prot->multiply($local);
        [$ox, $oy, $oz] = $prot->apply($spec->nodeOffsetX[$j], $spec->nodeOffsetY[$j], $spec->nodeOffsetZ[$j]);
        [$x, $y, $z] = [$px + $ox, $py + $oy, $pz + $oz];
        $ownSpin = $this->activeSpin($spec->nodeSpinStart[$j], $spec->nodeSpinX[$j], $spec->nodeSpinY[$j], $spec->nodeSpinZ[$j], $t);
        [$wx, $wy, $wz] = $prot->apply($ownSpin[0], $ownSpin[1], $ownSpin[2]);
        [$rx, $ry, $rz] = [$x - $px, $y - $py, $z - $pz];
        $vx = $pvx + $pwy * $rz - $pwz * $ry;
        $vy = $pvy + $pwz * $rx - $pwx * $rz;
        $vz = $pvz + $pwx * $ry - $pwy * $rx;
        return [$x, $y, $z, $vx, $vy, $vz, $rot, $wx, $wy, $wz];
    }

    /** The angular velocity (x, y, z) active in a spin schedule at $t. */
    private function activeSpin(array $starts, array $xs, array $ys, array $zs, float $t): array
    {
        $k = 0;
        for ($i = 0, $n = count($starts); $i < $n; ++$i) {
            if ($starts[$i] <= $t + 1e-12) {
                $k = $i;
            }
        }
        return [$xs[$k], $ys[$k], $zs[$k]];
    }

    /** The cumulative rotation of a spin schedule at $t, composing each segment's exact turn in order (see Mat3). */
    private function scheduleRotation(array $starts, array $xs, array $ys, array $zs, float $t): Mat3
    {
        $rot = new Mat3();
        $n = count($starts);
        for ($i = 0; $i < $n; ++$i) {
            $end = $i + 1 < $n ? $starts[$i + 1] : INF;
            $dt = min($t, $end) - $starts[$i];
            if ($dt <= 0.0) {
                break;
            }
            $rot = Mat3::fromSpin($xs[$i], $ys[$i], $zs[$i], $dt)->multiply($rot);
        }
        return $rot;
    }

    /** Refines a bracket (gap positive at $t0, non-positive at $t1) to BISECT_EPS by bisection on the exact, not stepped, gap. */
    private function bisect(float $t0, float $t1, \Closure $gapAt): float
    {
        while ($t1 - $t0 > self::BISECT_EPS) {
            $mid = ($t0 + $t1) / 2;
            if ($gapAt($mid) > 0.0) {
                $t0 = $mid;
            } else {
                $t1 = $mid;
            }
        }
        return $t1;
    }

    private function separating(string $idA, string $idB, float $t): bool
    {
        $a = $this->bodyState((int) substr($idA, 1), $t);
        $b = $this->bodyState((int) substr($idB, 1), $t);
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $dz = $b[2] - $a[2];
        return $dx * ($b[3] - $a[3]) + $dy * ($b[4] - $a[4]) + $dz * ($b[5] - $a[5]) > 0.0;
    }

    /** Applies the response rule for the contact kind and appends the resulting segments to the bodies' history. */
    private function apply(float $t, string $kind, string $idA, string $idB): void
    {
        if ($kind === 'wall') {
            $i = (int) substr($idA, 1);
            $axis = (int) substr($idB, 4, 1);
            $p = $this->bodyState($i, $t);
            $v = [$p[3], $p[4], $p[5]];
            $v[$axis] = -$v[$axis];
            $this->history[$i][] = [$t, $p[0], $p[1], $p[2], $v[0], $v[1], $v[2]];
            return;
        }
        if ($kind === 'pair' && $idB[0] === 'N') {
            $i = (int) substr($idA, 1);
            $a = $this->bodyState($i, $t);
            $part = $this->nodeState((int) substr($idB, 1), $t);
            $dx = $part[0] - $a[0];
            $dy = $part[1] - $a[1];
            $dz = $part[2] - $a[2];
            $d = sqrt($dx * $dx + $dy * $dy + $dz * $dz) ?: 1.0;
            [$nx, $ny, $nz] = [$dx / $d, $dy / $d, $dz / $d];
            $rel = ($part[3] - $a[3]) * $nx + ($part[4] - $a[4]) * $ny + ($part[5] - $a[5]) * $nz;   // part - body, along n
            $k = 2.0 * $rel;   // elastic bounce off a kinematic (infinite-mass) surface: v' = v + 2*(v_part - v)·n * n
            $this->history[$i][] = [$t, $a[0], $a[1], $a[2], $a[3] + $k * $nx, $a[4] + $k * $ny, $a[5] + $k * $nz];
            return;
        }
        // free-free pair, or a tether gone taut: an elastic exchange between two finite masses
        $i = (int) substr($idA, 1);
        $j = (int) substr($idB, 1);
        $a = $this->bodyState($i, $t);
        $b = $this->bodyState($j, $t);
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $dz = $b[2] - $a[2];
        $d = sqrt($dx * $dx + $dy * $dy + $dz * $dz) ?: 1.0;
        [$nx, $ny, $nz] = $kind === 'tether' ? [-$dx / $d, -$dy / $d, -$dz / $d] : [$dx / $d, $dy / $d, $dz / $d];
        $normalSpeed = ($b[3] - $a[3]) * $nx + ($b[4] - $a[4]) * $ny + ($b[5] - $a[5]) * $nz;
        $ma = $this->spec->bodyMass[$i];
        $mb = $this->spec->bodyMass[$j];
        $impulse = -2.0 * $normalSpeed / (1.0 / $ma + 1.0 / $mb);
        $ka = -$impulse / $ma;
        $kb = $impulse / $mb;
        $this->history[$i][] = [$t, $a[0], $a[1], $a[2], $a[3] + $ka * $nx, $a[4] + $ka * $ny, $a[5] + $ka * $nz];
        $this->history[$j][] = [$t, $b[0], $b[1], $b[2], $b[3] + $kb * $nx, $b[4] + $kb * $ny, $b[5] + $kb * $nz];
    }
}
