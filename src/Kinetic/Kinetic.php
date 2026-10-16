<?php

namespace Phame\Kinetic;

/**
 * The arithmetic of the world manager, on plain numbers: where a moving box enters another, and when one leaves the
 * world. Pure functions of start boxes and velocities, so the same code can run over many pairs at once: this is the
 * part a GPU backend would replace with a compute kernel.
 *
 * Body::kineticState() writes a body's world-space box and velocity into the static fields here; the functions read
 * them. 2D bodies use z = 0 and a unit thickness, so the z axis never separates them.
 */
final class Kinetic
{
    public static float $x0 = 0.0;
    public static float $y0 = 0.0;
    public static float $z0 = 0.0;
    public static float $x1 = 0.0;
    public static float $y1 = 0.0;
    public static float $z1 = 0.0;
    public static float $vx = 0.0;
    public static float $vy = 0.0;
    public static float $vz = 0.0;
    /** World acceleration of the reference point (the body's own, plus any Node3D::$gravity it sits under). */
    public static float $ax = 0.0;
    public static float $ay = 0.0;
    public static float $az = 0.0;
    /**
     * How long the straight line in the fields stays within the box: INF for exact linear motion, a short window for
     * bodies on rotating parents (their paths are arcs: the box is grown to hold the arc for that long).
     */
    public static float $horizon = INF;
    /** The window used for arcs, in seconds of game time. Shorter is tighter but predicts more often. */
    public static float $arcHorizon = 0.1;

    /** World bounds for leaving, written by Body::kineticBounds(); INF where the world has no edge. */
    public static float $minX = -INF;
    public static float $minY = -INF;
    public static float $minZ = -INF;
    public static float $maxX = INF;
    public static float $maxY = INF;
    public static float $maxZ = INF;

    /** The current reconciliation pass: within one, nothing moves, so each body's state is computed once. -1: none. */
    public static int $pass = -1;
    private static int $passes = 0;

    /** Starts a reconciliation pass (states cached from here until endPass()). */
    public static function beginPass(): void
    {
        self::$pass = ++self::$passes;
    }

    public static function endPass(): void
    {
        self::$pass = -1;
    }

    /** Writes the body's state into the fields, from the pass cache when it has been computed in this pass already. */
    public static function state(Body $body): void
    {
        if (self::$pass >= 0 && $body->kineticPass === self::$pass) {
            [self::$x0, self::$y0, self::$z0, self::$x1, self::$y1, self::$z1, self::$vx, self::$vy, self::$vz, self::$horizon, self::$ax, self::$ay, self::$az] = $body->kineticCache;
            return;
        }
        $body->kineticState();
        if (self::$pass >= 0) {
            $body->kineticPass = self::$pass;
            $body->kineticCache ??= [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];   // written in place from here on
            $body->kineticCache[0] = self::$x0;
            $body->kineticCache[1] = self::$y0;
            $body->kineticCache[2] = self::$z0;
            $body->kineticCache[3] = self::$x1;
            $body->kineticCache[4] = self::$y1;
            $body->kineticCache[5] = self::$z1;
            $body->kineticCache[6] = self::$vx;
            $body->kineticCache[7] = self::$vy;
            $body->kineticCache[8] = self::$vz;
            $body->kineticCache[9] = self::$horizon;
            $body->kineticCache[10] = self::$ax;
            $body->kineticCache[11] = self::$ay;
            $body->kineticCache[12] = self::$az;
        }
    }

    /** How far a point at $radius turning at $omega rad/s strays from its tangent line within $seconds. */
    public static function arcDeviation(float $radius, float $omega, float $seconds): float
    {
        $a = abs($omega) * $seconds;
        return $radius * sqrt((1.0 - cos($a)) ** 2 + ($a - sin($a)) ** 2);
    }

    /**
     * Seconds from now until box a (grown by $radius) starts to overlap box b, each moving at its velocity, or INF.
     * Swept along the whole path, so a fast box cannot pass through a thin one between two looks.
     */
    public static function timeOfImpact(
        float $ax0, float $ay0, float $az0, float $ax1, float $ay1, float $az1, float $avx, float $avy, float $avz,
        float $bx0, float $by0, float $bz0, float $bx1, float $by1, float $bz1, float $bvx, float $bvy, float $bvz,
        float $radius,
    ): float {
        $enter = 0.0;
        $exit = INF;
        if (!self::sweep($ax0 - $radius, $ax1 + $radius, $bx0, $bx1, $avx - $bvx, $enter, $exit)
            || !self::sweep($ay0 - $radius, $ay1 + $radius, $by0, $by1, $avy - $bvy, $enter, $exit)
            || !self::sweep($az0 - $radius, $az1 + $radius, $bz0, $bz1, $avz - $bvz, $enter, $exit)) {
            return INF;
        }
        return $enter < $exit ? $enter : INF;
    }

    /** Narrows [enter, exit] to when two intervals overlap along one axis, a moving at $v relative to b. */
    private static function sweep(float $a0, float $a1, float $b0, float $b1, float $v, float &$enter, float &$exit): bool
    {
        if ($v == 0.0) {
            return $a0 < $b1 && $b0 < $a1;
        }
        $t0 = ($b0 - $a1) / $v;
        $t1 = ($b1 - $a0) / $v;
        if ($t0 > $t1) {
            [$t0, $t1] = [$t1, $t0];
        }
        $enter = max($enter, $t0);
        $exit = min($exit, $t1);
        return $exit > 0.0;
    }

    /** Seconds from now until the box in the static fields is entirely outside the static world bounds, or INF. */
    public static function timeOfLeaving(): float
    {
        return min(
            self::leaveAxis(self::$x0, self::$x1, self::$minX, self::$maxX, self::$vx),
            self::leaveAxis(self::$y0, self::$y1, self::$minY, self::$maxY, self::$vy),
            self::leaveAxis(self::$z0, self::$z1, self::$minZ, self::$maxZ, self::$vz),
        );
    }

    private static function leaveAxis(float $lo, float $hi, float $min, float $max, float $v): float
    {
        if ($hi <= $min || $lo >= $max) {
            return 0.0;
        }
        if ($v > 0.0) {
            return $max === INF ? INF : ($max - $lo) / $v;
        }
        if ($v < 0.0) {
            return $min === -INF ? INF : ($min - $hi) / $v;
        }
        return INF;
    }

    /** @internal Adds $delta to the watched counts of the body and its ancestors. */
    public static function countWatched(Body $body, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        for ($n = $body; $n !== null; $n = $n->kineticGroup()) {
            $n->watchedBelow += $delta;
        }
    }

}
