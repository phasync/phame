<?php

namespace Phame\Filters;

use Phame\GL\Shader;

/** Bright parts of the picture bleed light into their surroundings. */
final class BloomFilter extends BlurFilter
{
    public function __construct(public float $strength = 1.0, float $radius = 8.0, float $threshold = 0.6)
    {
        parent::__construct($radius, <<<'GLSL'
            uniform float uStrength;
            vec4 compose(vec4 blurred, vec4 original) {
                // added light: the color grows, alpha grows with it so that it shows over transparent areas
                float a = clamp(original.a + max(blurred.r, max(blurred.g, blurred.b)) * uStrength, 0.0, 1.0);
                return vec4(min(original.rgb + blurred.rgb * uStrength, vec3(a)), a);
            }
            GLSL, $threshold);
    }

    protected function setUniforms(Shader $shader, int $pass): void
    {
        parent::setUniforms($shader, $pass);
        $shader->set('uStrength', $this->strength);
    }
}
