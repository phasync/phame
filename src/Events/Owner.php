<?php

namespace Phame\Events;

/** @internal The spawner state of an object that has coroutines: what to start, and the frame they run in. */
final class Owner
{
    /** @var \SplQueue<?\Closure> raised as a flag after adding; null closes the spawner */
    public readonly \SplQueue $queue;
    public ?\phasync\Frame $frame = null;

    public function __construct()
    {
        $this->queue = new \SplQueue();
    }
}
