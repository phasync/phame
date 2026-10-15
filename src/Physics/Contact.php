<?php

namespace Phame\Physics;

use Phame\Scene\Node3D;

/**
 * What happened when two bodies touched (or a body reached a wall): the facts, for the handler and the objects to
 * decide what follows. The engine applies nothing itself. The normal points from $a to $b (into the wall for walls).
 */
final class Contact
{
    public float $nx = 0.0;
    public float $ny = 0.0;
    public float $nz = 0.0;
    /** The velocity of $b relative to $a along the normal (negative: they approach). */
    public float $normalSpeed = 0.0;
    /**
     * A tether between $a and $b went taut (see Space::tether()): the normal points from $b to $a, so a bounce, as for
     * any contact, pulls them back together.
     */
    public bool $tether = false;

    public function __construct(
        /** Game time of the contact. */
        public readonly float $time,
        public readonly Node3D $a,
        public readonly Node3D|Wall $b,
    ) {
    }

    /**
     * The size of the impulse (mass times velocity change) a bounce with this restitution exchanges along the
     * normal: 1 is perfectly elastic, 0 sticks together. $a receives -impulse * normal, $b +impulse * normal. A wall
     * counts as infinitely heavy. A helper for the objects: nothing is applied.
     */
    public function impulse(float $restitution = 1.0): float
    {
        if ($this->normalSpeed >= 0.0) {
            return 0.0;   // already separating
        }
        $inverse = 1.0 / $this->a->mass + ($this->b instanceof Node3D ? 1.0 / $this->b->mass : 0.0);
        return -(1.0 + $restitution) * $this->normalSpeed / $inverse;
    }

    /** The other party, seen from $body. */
    public function other(Node3D $body): Node3D|Wall
    {
        return $body === $this->a ? $this->b : $this->a;
    }

    /** The normal pointing away from $body (+1) or towards it (-1): multiply an impulse for $body with this. */
    public function sign(Node3D $body): float
    {
        return $body === $this->a ? -1.0 : 1.0;
    }
}
