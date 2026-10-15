<?php

namespace Phame\Animation;

/**
 * Animates numeric properties of an object from their value when the tween starts to a target value.
 *
 * ```php
 * $juggler->add((new Tween($sprite, 0.5, Transitions::EASE_OUT))->animate('x', 300)->animate('alpha', 0.0));
 * ```
 */
final class Tween implements Animatable
{
    public bool $isComplete = false;
    public float $delay = 0.0;
    /** How many times the animation runs; 0 repeats forever. */
    public int $repeatCount = 1;
    /** Yoyo: every other repetition runs backwards, from the end value to the start value. */
    public bool $reverse = false;
    /** Called once, when the tween first advances after its delay and before it moves anything. */
    public ?\Closure $onStart = null;
    /** @var ?\Closure(): void Called once, after the final repetition. */
    public ?\Closure $onComplete = null;
    /** @var ?\Closure(): void Called after every advance. */
    public ?\Closure $onUpdate = null;

    /** True from the first advance after the delay until the tween completes. */
    public bool $isPlaying {
        get => $this->started && !$this->isComplete;
    }

    /** @var array<string, float> property => end value */
    private array $properties = [];
    /** @var array<string, float> property => value when the tween started */
    private array $starts = [];
    private bool $started = false;
    /** Repetitions finished so far. */
    private int $cycle = 0;
    /** Seconds into the current repetition. */
    private float $elapsed = 0.0;
    private readonly \Closure $transition;

    public function __construct(public readonly object $target, private readonly float $time, string|\Closure $transition = Transitions::LINEAR)
    {
        $this->transition = Transitions::get($transition);
    }

    public function animate(string $property, float $endValue): self
    {
        $this->properties[$property] = $endValue;
        return $this;
    }

    public function advanceTime(float $seconds): void
    {
        if ($this->isComplete) {
            return;
        }
        if ($this->delay > 0.0) {
            $this->delay -= $seconds;
            if ($this->delay > 0.0) {
                return;
            }
            $seconds = -$this->delay;
            $this->delay = 0.0;
        }
        if (!$this->started) {
            $this->started = true;
            $this->onStart?->__invoke();
        }
        // Properties added after the tween began start from their value at this moment.
        foreach ($this->properties as $name => $_) {
            $this->starts[$name] ??= (float) $this->target->$name;
        }
        $elapsed = $this->elapsed + $seconds;
        $index = $this->cycle;
        $progress = 0.0;
        $final = false;
        if ($elapsed + 1e-9 >= $this->time) {
            $crossed = $this->time > 0.0 ? (int) floor(($elapsed + 1e-9) / $this->time) : 1;
            if ($this->repeatCount !== 0 && $crossed >= $this->repeatCount - $this->cycle) {
                $final = true;
                $index = $this->repeatCount - 1;
                $progress = 1.0;
            } else {
                $elapsed = $this->time > 0.0 ? max(0.0, $elapsed - $crossed * $this->time) : 0.0;
                $this->cycle += $crossed;
                if ($elapsed <= 1e-9) {
                    // exactly on a repetition boundary: show the end of the repetition that just finished
                    $elapsed = 0.0;
                    $index = $this->cycle - 1;
                    $progress = 1.0;
                } else {
                    $index = $this->cycle;
                    $progress = $elapsed / $this->time;
                }
            }
        } else {
            $progress = $elapsed / $this->time;
        }
        $this->elapsed = $elapsed;
        $backwards = $this->reverse && $index % 2 === 1;
        $ratio = $progress >= 1.0 ? 1.0 : ($this->transition)($backwards ? 1.0 - $progress : $progress);
        foreach ($this->properties as $name => $end) {
            $start = $this->starts[$name];
            $this->target->$name = $progress >= 1.0 ? ($backwards ? $start : $end) : $start + ($end - $start) * $ratio;
        }
        $this->onUpdate?->__invoke();
        if ($final) {
            $this->isComplete = true;
            $this->onComplete?->__invoke();
        }
    }
}
