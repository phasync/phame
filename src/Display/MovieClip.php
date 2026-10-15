<?php

namespace Phame\Display;

use Phame\Events\EnterFrameEvent;
use Phame\Events\Event;
use Phame\Textures\Texture;

/**
 * An image that steps through frames: sprite-sheet animation. It runs in game time while it is on the stage.
 *
 * ```php
 * $hero = new MovieClip($atlas->frames('run_'), 12);
 * $hero->frames = $atlas->frames('jump_');                 // switch animation; starts at its first frame
 * $hero->loop = false;
 * Async::awaitEvent($hero, Event::COMPLETE);               // wait for the last frame to finish
 * ```
 *
 * All frames are drawn at the size of the first.
 */
class MovieClip extends Image
{
    /** @var list<Texture> */
    public array $frames {
        set {
            if ($value === []) {
                throw new \InvalidArgumentException('A MovieClip needs at least one frame');
            }
            $this->frames = array_values($value);
            $this->time = 0.0;
            $this->currentFrame = 0;
        }
    }

    public int $currentFrame = 0 {
        set {
            $this->currentFrame = max(0, min(count($this->frames) - 1, $value));
            $this->texture = $this->frames[$this->currentFrame];
            $this->time = $this->currentFrame / $this->fps;
        }
    }

    public int $numFrames {
        get => count($this->frames);
    }

    /** Whether it starts over after the last frame. A clip that does not loop stops there and dispatches COMPLETE. */
    public bool $loop = true;
    public bool $playing = true;
    private float $time = 0.0;

    /** @param list<Texture> $frames */
    public function __construct(array $frames, public float $fps = 12.0)
    {
        if ($frames === []) {
            throw new \InvalidArgumentException('A MovieClip needs at least one frame');
        }
        parent::__construct($frames[0]);
        $this->frames = $frames;
        $this->addEventListener(Event::ENTER_FRAME, $this->advanceTime(...));
    }

    public function play(): void
    {
        if (!$this->loop && $this->currentFrame === count($this->frames) - 1) {
            $this->currentFrame = 0;
        }
        $this->playing = true;
    }

    public function pause(): void
    {
        $this->playing = false;
    }

    /** Pauses and goes back to the first frame. */
    public function stop(): void
    {
        $this->playing = false;
        $this->currentFrame = 0;
    }

    private function advanceTime(EnterFrameEvent $e): void
    {
        if (!$this->playing || $this->fps <= 0.0) {
            return;
        }
        $n = count($this->frames);
        $this->time += $e->passedTime;
        $frame = (int) floor($this->time * $this->fps + 1e-9);
        if ($frame >= $n) {
            if ($this->loop) {
                $this->time = fmod($this->time, $n / $this->fps);
                $frame = (int) floor($this->time * $this->fps + 1e-9) % $n;
            } else {
                $this->playing = false;
                $this->texture = $this->frames[$this->currentFrame = $n - 1];
                $this->dispatchEvent(new Event(Event::COMPLETE));
                return;
            }
        }
        if ($frame !== $this->currentFrame) {
            $this->currentFrame = $frame;
        }
    }
}
