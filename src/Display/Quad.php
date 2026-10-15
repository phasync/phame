<?php

namespace Phame\Display;

use Phame\Math\Rectangle;
use Phame\Rendering\Painter;
use Phame\Textures\Texture;

/**
 * A colored rectangle, or a textured one when a texture is set (see Image). The rectangle keeps the size given
 * to the constructor (meshWidth, meshHeight); setting width or height changes scaleX/scaleY so that the object
 * appears that size: `$q = new Quad(50, 50); $q->width = 100;` gives scaleX 2.
 */
class Quad extends DisplayObject
{
    /** Tint, 0xRRGGBB. Multiplied with the texture. */
    public int $color;
    public ?Texture $texture = null;

    public function __construct(public readonly float $meshWidth, public readonly float $meshHeight, int $color = 0xFFFFFF)
    {
        $this->color = $color;
    }

    public function getBounds(?DisplayObject $space): Rectangle
    {
        $m = $this->getTransformationMatrix($space);
        $w = $this->meshWidth;
        $h = $this->meshHeight;
        // corners (0,0), (w,0), (0,h), (w,h) relative to the transformed origin
        $ax = $m->a * $w;
        $ay = $m->b * $w;
        $cx = $m->c * $h;
        $cy = $m->d * $h;
        $minX = $m->tx + min(0.0, $ax) + min(0.0, $cx);
        $maxX = $m->tx + max(0.0, $ax) + max(0.0, $cx);
        $minY = $m->ty + min(0.0, $ay) + min(0.0, $cy);
        $maxY = $m->ty + max(0.0, $ay) + max(0.0, $cy);
        return new Rectangle($minX, $minY, $maxX - $minX, $maxY - $minY);
    }

    public function hitTest(float $x, float $y): ?DisplayObject
    {
        if (!$this->visible || !$this->touchable) {
            return null;
        }
        return $x >= 0.0 && $y >= 0.0 && $x < $this->meshWidth && $y < $this->meshHeight ? $this : null;
    }

    public function render(Painter $painter): void
    {
        $painter->drawQuad($this->texture, $this->meshWidth, $this->meshHeight, $this->color);
    }
}
