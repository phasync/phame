<?php

namespace Phame\Net;

use Phame\Scene\Node3D;

/**
 * One connected viewer of a {@see View}: its own outbound channel (for a snapshot on join, and any node shown to it
 * alone - see `View::addTo()`), and which of the viewed scene's nodes it cares about.
 *
 * A game that wants interest management ("nearby only") subclasses this and overrides `interestedIn()` - not a
 * closure stored per viewer, which would mean one extra allocation (and, capturing `$this`, a reference cycle) per
 * connection, the pattern this engine avoids everywhere else (see `Physics\Space`'s struct-of-arrays, `Pool\Poolable`).
 */
class Viewer
{
    public function __construct(
        /** Where this viewer's own messages (its join snapshot, anything shown to it alone) are published. */
        public readonly string $channel,
    ) {
    }

    /** Whether this viewer's batch should include $node's description. True for everyone, by default. */
    public function interestedIn(Node3D $node): bool
    {
        return true;
    }
}
