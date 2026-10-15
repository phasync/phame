<?php

namespace Phame\Rendering;

use Phame\GL\GL;

/** Blend modes for premultiplied alpha. AUTO takes the mode of the parent. */
final class BlendMode
{
    public const AUTO = -1;
    public const NORMAL = 0;
    public const ADD = 1;
    public const MULTIPLY = 2;
    public const SCREEN = 3;
    public const NONE = 4;

    /** @return array{int, int} source and destination factors */
    public static function sourceFactor(int $mode): int
    {
        return match ($mode) {
            self::MULTIPLY => GL::DST_COLOR,
            default => GL::ONE,
        };
    }

    public static function destinationFactor(int $mode): int
    {
        return match ($mode) {
            self::ADD => GL::ONE,
            self::SCREEN => GL::ONE_MINUS_SRC_COLOR,
            self::NONE => GL::ZERO,
            default => GL::ONE_MINUS_SRC_ALPHA,
        };
    }
}
