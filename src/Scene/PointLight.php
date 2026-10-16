<?php

namespace Phame\Scene;

/**
 * A light that shines in all directions from the node's position, fading to nothing at $range. A scene lights
 * its surfaces with the first MeshShader::MAX_POINT_LIGHTS (4) visible point lights it finds. Unlit materials ignore it.
 */
final class PointLight extends Node3D
{
    public function __construct(
        public int $color = 0xFFFFFF,
        public float $intensity = 1.0,
        public float $range = 10.0,
        /**
         * The light source's own physical size (the radius of the sphere it shines from), in world units. 0 (the
         * default): a true point, no angular size. A renderer needing the light's angular radius from a distance
         * (an eclipse shadow's penumbra, say) reads this - not `Node3D::$radius`, which is a collision sphere a
         * light never has a physics reason to carry.
         */
        public float $lightRadius = 0.0,
    ) {
    }
}
