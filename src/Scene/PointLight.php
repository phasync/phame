<?php

namespace Phame\Scene;

/**
 * A light that shines in all directions from the node's position, fading to nothing at $range. A scene lights
 * its surfaces with the first MeshShader::MAX_POINT_LIGHTS (4) visible point lights it finds. Unlit materials ignore it.
 */
final class PointLight extends Node3D
{
    public function __construct(public int $color = 0xFFFFFF, public float $intensity = 1.0, public float $range = 10.0)
    {
    }
}
