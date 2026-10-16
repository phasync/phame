<?php

namespace Phame\Tests\Verification;

use Phame\Scene\Node3D;

/**
 * A hinge for verification scenes: turns about one axis of its parent's space at a constant rate between events,
 * within limits, bouncing back with $bounce of its speed at a limit. Like server/src/Joint.php, built independently
 * here so the verification tests do not depend on the server namespace.
 */
final class TestJoint extends Node3D
{
    private float $angle = 0.0;
    private float $since = 0.0;
    public private(set) float $rate = 0.0;

    public function __construct(
        public readonly float $ax,
        public readonly float $ay,
        public readonly float $az,
        // named limitMin/limitMax, not min/max: Node3D now has its own $max (the x of its acceleration, see setAcceleration())
        public readonly float $limitMin,
        public readonly float $limitMax,
        public readonly float $bounce = 0.0,
    ) {
    }

    public function angleNow(): float
    {
        return $this->angle + $this->rate * ($this->clock->time - $this->since);
    }

    /** Turns at this rate (rad/s) from now; at a limit it can only turn back. */
    public function setRate(float $rate): void
    {
        $angle = max($this->limitMin, min($this->limitMax, $this->angleNow()));
        if (($angle >= $this->limitMax && $rate > 0.0) || ($angle <= $this->limitMin && $rate < 0.0)) {
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
        return max(0.0, (($this->rate > 0.0 ? $this->limitMax : $this->limitMin) - $this->angleNow()) / $this->rate);
    }

    /** At a limit: turns back with $bounce of its speed, or stops when that is too slow to matter. */
    public function reachLimit(): void
    {
        $rate = -$this->rate * $this->bounce;
        $this->setRate(abs($rate) < 0.05 ? 0.0 : $rate);
    }

    /**
     * The schedule a joint like this one turns through from t = 0 to $until, worked out by the same arithmetic as
     * setRate()/untilLimit()/reachLimit() above but ahead of time, independently of the clock: a joint's motion
     * never depends on anything else, so the oracle can be given the whole schedule up front (see SceneSpec).
     *
     * @return array{0: list<float>, 1: list<float>, 2: list<float>, 3: list<float>} starts, and the spin from each
     */
    public static function schedule(float $ax, float $ay, float $az, float $min, float $max, float $bounce, float $rate, float $angle, float $until): array
    {
        [$starts, $xs, $ys, $zs] = [[0.0], [$ax * $rate], [$ay * $rate], [$az * $rate]];
        $t = 0.0;
        while ($rate !== 0.0) {
            $limit = $rate > 0.0 ? $max : $min;
            $t += ($limit - $angle) / $rate;
            if ($t >= $until) {
                break;
            }
            $angle = $limit;
            $rate = -$rate * $bounce;
            if (abs($rate) < 0.05) {
                $rate = 0.0;
            }
            $starts[] = $t;
            $xs[] = $ax * $rate;
            $ys[] = $ay * $rate;
            $zs[] = $az * $rate;
        }
        return [$starts, $xs, $ys, $zs];
    }
}
