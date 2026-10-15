<?php

namespace Phame\Animation;

/**
 * Advances animations by the time that has passed in the game. The engine owns one juggler and advances it
 * every frame. A juggler's time is game time: a paused game stops its juggler, and the tweens and delayed
 * calls in it stand still.
 */
final class Juggler
{
    public private(set) float $elapsedTime = 0.0;
    /** @var list<Animatable> */
    private array $objects = [];

    public function add(Animatable $object): Animatable
    {
        $this->objects[] = $object;
        return $object;
    }

    public function remove(Animatable $object): void
    {
        $i = array_search($object, $this->objects, true);
        if ($i !== false) {
            array_splice($this->objects, $i, 1);
        }
    }

    /** Removes the tweens of an object. */
    public function removeTweens(object $target): void
    {
        $this->objects = array_values(array_filter($this->objects, static fn ($o) => !($o instanceof Tween && $o->target === $target)));
    }

    public function delayCall(callable $call, float $seconds, mixed ...$args): DelayedCall
    {
        return $this->add(new DelayedCall($call(...), $seconds, $args));
    }

    /** @param array<string, float> $properties property => target value */
    public function tween(object $target, float $seconds, array $properties, string|\Closure $transition = Transitions::LINEAR, ?\Closure $onComplete = null): Tween
    {
        $tween = new Tween($target, $seconds, $transition);
        foreach ($properties as $name => $value) {
            $tween->animate($name, $value);
        }
        $tween->onComplete = $onComplete;
        $this->add($tween);
        return $tween;
    }

    public function advanceTime(float $seconds): void
    {
        $this->elapsedTime += $seconds;
        // Objects added while advancing wait for the next frame; the list may change under us.
        $anyComplete = false;
        foreach ($this->objects as $object) {
            $object->advanceTime($seconds);
            $anyComplete = $anyComplete || $object->isComplete;
        }
        if ($anyComplete) {
            $live = 0;
            for ($i = 0, $n = count($this->objects); $i < $n; ++$i) {
                $object = $this->objects[$i];
                if (!$object->isComplete) {
                    $this->objects[$live++] = $object;
                }
            }
            array_splice($this->objects, $live);
        }
    }

    /** Whether nothing is animating (an evented world can then sleep until its next event). */
    public function isIdle(): bool
    {
        return $this->objects === [];
    }

    public function purge(): void
    {
        $this->objects = [];
    }
}
