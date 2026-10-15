<?php

namespace Phame\Display;

use Phame\Textures\Texture;

/** A quad showing a texture, at the texture's own size. */
class Image extends Quad
{
    public function __construct(Texture $texture, int $color = 0xFFFFFF)
    {
        parent::__construct($texture->width, $texture->height, $color);
        $this->texture = $texture;
    }
}
