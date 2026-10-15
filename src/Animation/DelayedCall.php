<?php

namespace Phame\Animation;

/** Calls a function once, after a delay in juggler time. */
final class DelayedCall implements Animatable
{
    public bool $isComplete = false;
    private float $elapsed = 0.0;
    private array $args;

    public function __construct(private \Closure $call, private readonly float $delay, array $args = [])
    {
        $this->args = $args;
    }

    // the tolerance: ten frames of 0.05 add up to 0.49999999999999994, and must still reach 0.5
    public function advanceTime(float $seconds): void
    {
        $this->elapsed += $seconds;
        if (!$this->isComplete && $this->elapsed + 1e-9 >= $this->delay) {
            $this->isComplete = true;
            ($this->call)(...$this->args);
        }
    }
}
