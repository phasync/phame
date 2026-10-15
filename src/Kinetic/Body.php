<?php

namespace Phame\Kinetic;

/**
 * Something the world manager can predict: a 2D display object or a 3D node. Its motion is a description (position
 * and rotation at a start time, plus their rates), so it moves without updates, and coroutines can wait() for what
 * will happen to it.
 */
interface Body
{
    /** @internal Writes the body's world-space box and velocity, at the current game time, into Kinetic's fields. */
    public function kineticState(): void;

    /** @internal Writes the bounds of the body's world (for leaving it) into Kinetic's fields. */
    public function kineticBounds(): void;

    /** @internal The group the body is a member of, for waiters that watch the group. */
    public function kineticGroup(): ?Group;
}
