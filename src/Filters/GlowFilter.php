<?php

namespace Phame\Filters;

use Phame\GL\Shader;

/** A blurred copy of the object's silhouette behind it: a glow, or with an offset a drop shadow. */
final class GlowFilter extends BlurFilter
{
    public function __construct(public int $color = 0xFFFFFF, public float $strength = 1.0, float $radius = 6.0, public float $offsetX = 0.0, public float $offsetY = 0.0)
    {
        parent::__construct($radius, <<<'GLSL'
            uniform vec4 uGlow;
            uniform vec2 uOffset;
            vec4 compose(vec4 blurred, vec4 original) {
                // The blur pass has smeared the shape; the offset moves where the smear is read from.
                vec4 shifted = texture(uTexture, vUV - uOffset * uTexelSize);
                float a = clamp(shifted.a * uGlow.a, 0.0, 1.0);
                vec4 glow = vec4(uGlow.rgb * a, a);
                return original + glow * (1.0 - original.a);
            }
            GLSL);
        $this->margin = ceil($radius * 1.5 + max(abs($offsetX), abs($offsetY)));
    }

    protected function setUniforms(Shader $shader, int $pass): void
    {
        parent::setUniforms($shader, $pass);
        if ($pass === 2) {
            $c = $this->color;
            $shader->set('uGlow', (($c >> 16) & 255) / 255, (($c >> 8) & 255) / 255, ($c & 255) / 255, $this->strength);
            $shader->set('uOffset', $this->offsetX, -$this->offsetY);
        }
    }
}
