<?php

namespace Phame\Physics;

/**
 * One side of a physics space's box, as the other party of a Contact. Handlers decide what a wall does:
 *
 * ```php
 * $world->onCollision(function (Rock $rock, Wall $wall, Contact $c) {
 *     $rock->bounceOff($c);   // the game's method: mirror the velocity along $c's normal
 * });
 * ```
 */
final class Wall
{
    /** @var array<int, self> */
    private static array $sides = [];

    /** @param int $axis 0, 1, 2 for x, y, z; $side -1 for the low wall, +1 for the high one */
    private function __construct(public readonly int $axis, public readonly int $side)
    {
    }

    public static function side(int $axis, int $side): self
    {
        return self::$sides[$axis * 2 + ($side > 0 ? 1 : 0)] ??= new self($axis, $side);
    }
}
