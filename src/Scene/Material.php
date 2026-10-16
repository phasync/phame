<?php

namespace Phame\Scene;

use Phame\Rendering\MeshShader;
use Phame\Textures\Texture;

/** How a mesh's surface looks: a color, optionally a texture, lit by the scene's light unless `unlit`. */
final class Material
{
    public function __construct(
        /** 0xRRGGBB, multiplied with the texture. */
        public int $color = 0xFFFFFF,
        public ?Texture $texture = null,
        /** Below 1.0 the mesh is blended with what is behind it. */
        public float $alpha = 1.0 { set => $this->alpha = max(0.0, min(1.0, $value)); },
        /** Skips lighting: the surface shows its color and texture as they are. */
        public bool $unlit = false,
        /** Draws back faces too. */
        public bool $doubleSided = false,
        /** Shiny surfaces show the lights as highlights: 0 is matte, 1 a strong highlight. */
        public float $specular = 0.0,
        /** How tight the highlight is: 8 is broad, 128 is a pinpoint. */
        public float $shininess = 32.0,
        /** 0xRRGGBB light the surface gives off by itself, not affected by lighting. */
        public int $emissive = 0x000000,
        /** Draws the mesh with your own GLSL instead of the standard lighting. */
        public ?MeshShader $shader = null,
    ) {
    }

    /**
     * Where this came from, if a loader (glTF, later) built it from an asset: "url#sub-id". Null (the default)
     * means Net\View sends this material's fields inline; set, it sends the reference instead. See
     * docs/REMOTE-VIEW.md.
     */
    public ?string $source = null;
}
