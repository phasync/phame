<?php

namespace Phame\Kinetic;

/** A set of bodies that waiters can watch (Wake::collision($group)): a display container or a 3D node's children. */
interface Group
{
    /** @internal How many waiting coroutines watch this group: its members then report their motion changes. */
    public int $dependents { get; set; }

    /** @return list<Body> */
    public function kineticMembers(): array;
}
