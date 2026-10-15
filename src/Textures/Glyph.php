<?php

namespace Phame\Textures;

/** One character of a BitmapFont, in the font's native pixel size. */
final class Glyph
{
    public function __construct(
        public readonly int $id,
        /** Null for characters without ink, such as the space. */
        public readonly ?Texture $texture,
        public readonly float $width,
        public readonly float $height,
        public readonly float $xOffset,
        public readonly float $yOffset,
        public readonly float $xAdvance,
    ) {
    }
}
