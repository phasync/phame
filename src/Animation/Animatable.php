<?php

namespace Phame\Animation;

/** Something a Juggler advances. The juggler drops it once isComplete. */
interface Animatable
{
    public bool $isComplete { get; }

    public function advanceTime(float $seconds): void;
}
