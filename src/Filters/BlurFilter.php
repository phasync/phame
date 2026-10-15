<?php

namespace Phame\Filters;

use Phame\GL\Shader;
use Phame\Rendering\Filter;

/** A gaussian blur, done as a horizontal and a vertical pass. The radius is in points. */
class BlurFilter extends Filter
{
    /**
     * @param string $compose GLSL for `vec4 compose(vec4 blurred, vec4 original)` run as a third pass, or '' for none
     * @param float  $threshold if above 0, only parts brighter than this (0..1) are blurred
     */
    public function __construct(public float $radius = 4.0, string $compose = '', public float $threshold = 0.0)
    {
        $glsl = <<<'GLSL'
            uniform vec2 uDirection;
            uniform float uStep;
            uniform float uThreshold;
            vec4 tap(vec2 uv) {
                vec4 c = texture(uTexture, uv);
                if (uPass == 0 && uThreshold > 0.0) {
                    c *= smoothstep(uThreshold, uThreshold + 0.15, max(c.r, max(c.g, c.b)));
                }
                return c;
            }
            vec4 blur(vec2 uv) {
                // nine taps of a gaussian, spaced so that the whole radius is covered
                const float w[5] = float[](0.2270, 0.1946, 0.1216, 0.0541, 0.0162);
                vec2 d = uDirection * uStep * uTexelSize;
                vec4 sum = tap(uv) * w[0];
                for (int i = 1; i < 5; i++) {
                    sum += tap(uv + d * float(i)) * w[i];
                    sum += tap(uv - d * float(i)) * w[i];
                }
                return sum;
            }
            GLSL;
        $glsl .= $compose === '' ? "\nvec4 filterPixel(vec2 uv) { return blur(uv); }\n"
            : "\n$compose\nvec4 filterPixel(vec2 uv) { return uPass < 2 ? blur(uv) : compose(texture(uTexture, uv), texture(uOriginal, uv)); }\n";
        parent::__construct($glsl, $compose === '' ? 2 : 3);
        $this->margin = ceil($radius * 1.5);
    }

    protected function setUniforms(Shader $shader, int $pass): void
    {
        $shader->set('uDirection', $pass === 1 ? 0.0 : 1.0, $pass === 1 ? 1.0 : 0.0);
        $shader->set('uStep', max(0.0, $this->radius) / 4.0);
        $shader->set('uThreshold', $this->threshold);
    }
}
