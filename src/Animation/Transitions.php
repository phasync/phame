<?php

namespace Phame\Animation;

/** Easing functions: they map progress 0..1 to a ratio 0..1 (some overshoot). */
final class Transitions
{
    public const LINEAR = 'linear';
    public const EASE_IN = 'easeIn';
    public const EASE_OUT = 'easeOut';
    public const EASE_IN_OUT = 'easeInOut';
    public const EASE_OUT_BACK = 'easeOutBack';
    public const EASE_OUT_BOUNCE = 'easeOutBounce';

    public static function get(string|\Closure $transition): \Closure
    {
        if ($transition instanceof \Closure) {
            return $transition;
        }
        return match ($transition) {
            self::LINEAR => static fn (float $t): float => $t,
            self::EASE_IN => static fn (float $t): float => $t * $t * $t,
            self::EASE_OUT => static fn (float $t): float => 1.0 - (1.0 - $t) ** 3,
            self::EASE_IN_OUT => static fn (float $t): float => $t < 0.5 ? 4.0 * $t ** 3 : 1.0 - (-2.0 * $t + 2.0) ** 3 / 2.0,
            self::EASE_OUT_BACK => static fn (float $t): float => 1.0 + 2.70158 * ($t - 1.0) ** 3 + 1.70158 * ($t - 1.0) ** 2,
            self::EASE_OUT_BOUNCE => self::bounce(...),
            default => throw new \InvalidArgumentException("Unknown transition $transition"),
        };
    }

    private static function bounce(float $t): float
    {
        if ($t < 1 / 2.75) {
            return 7.5625 * $t * $t;
        }
        if ($t < 2 / 2.75) {
            $t -= 1.5 / 2.75;
            return 7.5625 * $t * $t + 0.75;
        }
        if ($t < 2.5 / 2.75) {
            $t -= 2.25 / 2.75;
            return 7.5625 * $t * $t + 0.9375;
        }
        $t -= 2.625 / 2.75;
        return 7.5625 * $t * $t + 0.984375;
    }
}
