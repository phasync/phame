<?php

namespace Phame\Filters;

use Phame\GL\Shader;
use Phame\Rendering\Filter;

/**
 * Colors transformed by a 4x5 matrix, row by row: r' = m0*r + m1*g + m2*b + m3*a + m4, and likewise for g, b, a.
 * Channels are 0..1. The static constructors cover the common cases.
 */
final class ColorMatrixFilter extends Filter
{
    /** @param list<float> $matrix 20 values */
    public function __construct(private array $matrix)
    {
        if (count($matrix) !== 20) {
            throw new \InvalidArgumentException('A color matrix has 20 values, got ' . count($matrix));
        }
        parent::__construct(<<<'GLSL'
            uniform vec4 uRow0, uRow1, uRow2, uRow3, uOffset;
            vec4 filterPixel(vec2 uv) {
                vec4 c = texture(uTexture, uv);
                if (c.a > 0.0) c.rgb /= c.a;
                vec4 r = vec4(dot(uRow0, c), dot(uRow1, c), dot(uRow2, c), dot(uRow3, c)) + uOffset;
                r = clamp(r, 0.0, 1.0);
                return vec4(r.rgb * r.a, r.a);
            }
            GLSL);
    }

    protected function setUniforms(Shader $shader, int $pass): void
    {
        $m = $this->matrix;
        $shader->set('uRow0', $m[0], $m[1], $m[2], $m[3]);
        $shader->set('uRow1', $m[5], $m[6], $m[7], $m[8]);
        $shader->set('uRow2', $m[10], $m[11], $m[12], $m[13]);
        $shader->set('uRow3', $m[15], $m[16], $m[17], $m[18]);
        $shader->set('uOffset', $m[4], $m[9], $m[14], $m[19]);
    }

    /** 1 leaves the colors alone, 0 is black and white. */
    public static function saturation(float $amount): self
    {
        [$r, $g, $b] = [0.2126, 0.7152, 0.0722];
        $s = $amount;
        $i = 1 - $s;
        return new self([
            $i * $r + $s, $i * $g, $i * $b, 0, 0,
            $i * $r, $i * $g + $s, $i * $b, 0, 0,
            $i * $r, $i * $g, $i * $b + $s, 0, 0,
            0, 0, 0, 1, 0,
        ]);
    }

    public static function grayscale(): self
    {
        return self::saturation(0.0);
    }

    /** Adds $amount (-1..1) to every color channel. */
    public static function brightness(float $amount): self
    {
        return new self([1, 0, 0, 0, $amount, 0, 1, 0, 0, $amount, 0, 0, 1, 0, $amount, 0, 0, 0, 1, 0]);
    }

    /** Moves each channel $amount of the way towards $rgb (0xRRGGBB): a tint. */
    public static function tint(int $rgb, float $amount = 1.0): self
    {
        [$r, $g, $b] = [(($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255];
        $k = 1 - $amount;
        return new self([$k, 0, 0, 0, $r * $amount, 0, $k, 0, 0, $g * $amount, 0, 0, $k, 0, $b * $amount, 0, 0, 0, 1, 0]);
    }

    public static function invert(): self
    {
        return new self([-1, 0, 0, 0, 1, 0, -1, 0, 0, 1, 0, 0, -1, 0, 1, 0, 0, 0, 1, 0]);
    }
}
