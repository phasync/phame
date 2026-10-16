<?php

namespace Phame\Scene;

/**
 * A piece of text at this node's position, not a mesh: a name tag over a player's avatar, a HUD callout anchored to
 * a world position. Carries no geometry or material of its own - a renderer (native, or Net\View's remote viewer)
 * draws it its own way, typically as a screen-space billboard.
 */
final class Label extends Node3D
{
    public function __construct(public string $text, public int $color = 0xFFFFFF)
    {
    }
}
