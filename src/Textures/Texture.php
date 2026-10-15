<?php

namespace Phame\Textures;

use Phame\Engine;
use Phame\GL\Texture2D;

/**
 * A rectangular region of a GPU texture, sized in points. Regions share the GPU texture of the one they were
 * cut from (see region()), so many images can draw from one atlas without switching textures.
 *
 * Premultiplied textures (as produced by rendering into a texture) are drawn as they are; others are
 * premultiplied by the shader.
 */
final class Texture
{
    public function __construct(
        public readonly Texture2D $gpu,
        /** Size in points: pixels divided by scale. */
        public readonly float $width,
        public readonly float $height,
        public readonly float $u0 = 0.0,
        public readonly float $v0 = 0.0,
        public readonly float $u1 = 1.0,
        public readonly float $v1 = 1.0,
        public readonly bool $premultiplied = false,
        public readonly float $scale = 1.0,
    ) {
    }

    /** @param string $rgba width*height*4 bytes, straight alpha, top row first */
    public static function fromRgba(int $width, int $height, string $rgba, float $scale = 1.0, bool $smooth = true): self
    {
        $gpu = new Texture2D(Engine::current()->gl, $width, $height, $rgba, $smooth);
        return new self($gpu, $width / $scale, $height / $scale, scale: $scale);
    }

    public static function fromPng(string $path, float $scale = 1.0, bool $smooth = true): self
    {
        $data = @file_get_contents($path) ?: throw new \RuntimeException("Cannot read $path");
        ['width' => $w, 'height' => $h, 'rgba' => $rgba] = Png::decode($data);
        return self::fromRgba($w, $h, $rgba, $scale, $smooth);
    }

    /** A solid texture. $argb is 0xAARRGGBB. */
    public static function fromColor(int $width, int $height, int $argb): self
    {
        $px = chr(($argb >> 16) & 255) . chr(($argb >> 8) & 255) . chr($argb & 255) . chr(($argb >> 24) & 255);
        return self::fromRgba($width, $height, str_repeat($px, $width * $height), smooth: false);
    }

    /** A region of this texture; the arguments are in points, relative to this texture's own top left corner. */
    public function region(float $x, float $y, float $width, float $height): self
    {
        $gw = $this->gpu->width;
        $gh = $this->gpu->height;
        // A flipped texture (render targets are stored upside down) has v1 < v0: its y axis runs the other way.
        $dir = $this->v1 < $this->v0 ? -1.0 : 1.0;
        $originX = $this->u0 * $gw + $x * $this->scale;
        $originY = $this->v0 * $gh + $dir * $y * $this->scale;
        return new self(
            $this->gpu, $width, $height,
            $originX / $gw, $originY / $gh,
            ($originX + $width * $this->scale) / $gw, ($originY + $dir * $height * $this->scale) / $gh,
            $this->premultiplied, $this->scale,
        );
    }

    /** Frees the GPU texture, which regions of this texture share. */
    public function dispose(): void
    {
        $this->gpu->dispose();
    }
}
