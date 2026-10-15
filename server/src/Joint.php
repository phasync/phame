<?php

namespace PhameServer;

use Phame\Scene\Node3D;

/**
 * A hinge in a ragdoll: a node that turns about one axis of its parent's space, at a constant rate between events,
 * within limits. Its angle is a function of time like any motion description, so the time it reaches a limit is
 * known exactly; there it turns back with $bounce of its speed (a limb: none, it stops; a rope: some), and a swing
 * too slow to matter stops. What hangs on it turns with it.
 */
final class Joint extends Node3D
{
    private float $angle = 0.0;
    private float $since = 0.0;
    public private(set) float $rate = 0.0;

    public function __construct(
        /** The hinge axis, a unit vector in the parent's space. */
        public readonly float $ax,
        public readonly float $ay,
        public readonly float $az,
        public readonly float $min,
        public readonly float $max,
        /** How much of its speed it keeps, turning back, at a limit (0: it stops there). */
        public readonly float $bounce = 0.0,
        /** How fast it may be flailing when thrown (rad/s, either way). */
        public readonly float $flail = 3.0,
    ) {
    }

    /** At a limit: turns back with $bounce of its speed, or stops when that is too slow to matter. */
    public function reachLimit(): void
    {
        $rate = -$this->rate * $this->bounce;
        $this->setRate(abs($rate) < 0.3 ? 0.0 : $rate);
    }

    public function angleNow(): float
    {
        return $this->angle + $this->rate * ($this->clock->time - $this->since);
    }

    /** Turns at this rate (rad/s) from now; at a limit it can only turn back. */
    public function setRate(float $rate): void
    {
        $angle = max($this->min, min($this->max, $this->angleNow()));
        if (($angle >= $this->max && $rate > 0.0) || ($angle <= $this->min && $rate < 0.0)) {
            $rate = 0.0;
        }
        [$this->angle, $this->since, $this->rate] = [$angle, $this->clock->time, $rate];
        $s = sin($angle / 2);
        $this->setOrientation($this->ax * $s, $this->ay * $s, $this->az * $s, cos($angle / 2));
        $this->spin($this->ax * $rate, $this->ay * $rate, $this->az * $rate);
    }

    /** Seconds until it reaches the limit it turns towards; INF while still. */
    public function untilLimit(): float
    {
        if ($this->rate === 0.0) {
            return INF;
        }
        return max(0.0, (($this->rate > 0.0 ? $this->max : $this->min) - $this->angleNow()) / $this->rate);
    }
}
