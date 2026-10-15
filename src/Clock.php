<?php

namespace Phame;

/**
 * A world's clock, at `$world->clock` (and `$engine->clock` for the world an engine shows). Game time is scaled and can be paused; real time
 * always runs. The juggler, tweens and Async::wait() follow game time.
 */
final class Clock
{
    /** Seconds of game time since the engine started. */
    public private(set) float $time = 0.0;
    /** Seconds of wall-clock time since the engine started, unaffected by timeScale and pause. */
    public private(set) float $realTime = 0.0;
    /** Game seconds advanced by the current frame. */
    public private(set) float $delta = 0.0;
    /** Real seconds of the current frame. */
    public private(set) float $realDelta = 0.0;
    /** Frames advanced since the engine started. */
    public private(set) int $frame = 0;

    /** 1.0 is normal speed, 0.5 is slow motion. */
    public float $timeScale = 1.0;
    /** While paused, game time stands still but frames, input and rendering carry on. */
    public bool $paused = false;
    /** If set, every frame lasts exactly this long instead of its measured duration: for replays and tests. */
    public ?float $forcedDelta = null;

    /**
     * @internal The world handles an event at its exact time (it may fall between two ticks): while it does, the clock
     * reads that time, so positions are evaluated and motion descriptions rebased at the instant of the event.
     */
    public function travelTo(float $time): void
    {
        $this->time = $time;
    }

    public function tick(float $measuredSeconds): void
    {
        $this->realDelta = $this->forcedDelta ?? $measuredSeconds;
        $this->realTime += $this->realDelta;
        $this->delta = $this->paused ? 0.0 : $this->realDelta * $this->timeScale;
        $this->time += $this->delta;
        ++$this->frame;
    }
}
