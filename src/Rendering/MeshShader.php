<?php

namespace Phame\Rendering;

use Phame\GL\GL;
use Phame\GL\Shader;
use Phame\Scene\Mesh;

/**
 * A GLSL program for drawing meshes: `$material->shader = new MeshShader($glsl)`. Your GLSL defines
 * `vec4 shade()` and returns the surface's straight (not premultiplied) RGBA. Optionally it defines
 * `vec3 displace(vec3 pos, vec3 normal, vec2 uv)` to move vertices (waves, wobble): it runs in model space.
 *
 * Available to both stages: uTime (game seconds). Fragment inputs: vWorldPos, vNormal (not normalized), vUV
 * (the material texture's region applied). Fragment uniforms: uTexture, uColor (rgb tint, alpha), uEmissive,
 * uUnlit, uPremultiplied, uShininess, uSpecular, uEye (camera position), plus these functions:
 *   vec3 sceneLight(vec3 n)     ambient + sun + point lights for a unit normal
 *   vec3 sceneSpecular(vec3 n)  the highlights of the same lights
 *   vec3 flatNormal()           the face's normal, from screen derivatives (for displaced vertices)
 * Fog and premultiplication are applied to what shade() returns. The standard material is itself such a shader.
 */
final class MeshShader
{
    public const MAX_POINT_LIGHTS = 4;

    private const VERTEX_HEADER = <<<'GLSL'
        #version 330 core
        layout(location = 0) in vec3 aPos;
        layout(location = 1) in vec3 aNormal;
        layout(location = 2) in vec2 aUV;
        uniform mat4 uMVP;
        uniform mat4 uModel;
        uniform mat3 uNormalMatrix;
        uniform vec4 uUV; // texture region: offset u, offset v, scale u, scale v
        uniform float uTime;
        out vec3 vNormal;
        out vec2 vUV;
        out vec3 vWorldPos;

        GLSL;

    private const VERTEX_FOOTER = <<<'GLSL'

        void main() {
            vec3 p = displace(aPos, aNormal, aUV);
            gl_Position = uMVP * vec4(p, 1.0);
            vWorldPos = (uModel * vec4(p, 1.0)).xyz;
            vNormal = uNormalMatrix * aNormal;
            vUV = uUV.xy + aUV * uUV.zw;
        }
        GLSL;

    private const NO_DISPLACE = "vec3 displace(vec3 pos, vec3 normal, vec2 uv) { return pos; }\n";

    private const FRAGMENT_HEADER = <<<'GLSL'
        #version 330 core
        in vec3 vNormal;
        in vec2 vUV;
        in vec3 vWorldPos;
        uniform sampler2D uTexture;
        uniform vec4 uColor;
        uniform vec3 uEmissive;
        uniform float uUnlit;
        uniform float uPremultiplied;
        uniform float uShininess;
        uniform float uSpecular;
        uniform float uTime;
        uniform vec3 uEye;
        uniform vec3 uLightDir;     // the direction the sun's light travels
        uniform vec3 uLightColor;
        uniform vec3 uAmbient;
        uniform int uPointCount;
        uniform vec3 uPointPos[4];
        uniform vec4 uPointColor[4]; // rgb premultiplied by intensity, range in w
        uniform vec3 uFogColor;
        uniform vec2 uFogRange;      // distances where the fog starts and where it is total
        out vec4 fragColor;

        vec3 sceneLight(vec3 n) {
            vec3 sum = uAmbient + uLightColor * max(dot(n, -uLightDir), 0.0);
            for (int i = 0; i < uPointCount; i++) {
                vec3 d = uPointPos[i] - vWorldPos;
                float dist = max(length(d), 0.0001);
                float att = clamp(1.0 - dist / uPointColor[i].w, 0.0, 1.0);
                sum += uPointColor[i].rgb * (att * att) * max(dot(n, d / dist), 0.0);
            }
            return sum;
        }

        vec3 sceneSpecular(vec3 n) {
            vec3 v = normalize(uEye - vWorldPos);
            vec3 sum = uLightColor * pow(max(dot(n, normalize(v - uLightDir)), 0.0), uShininess);
            for (int i = 0; i < uPointCount; i++) {
                vec3 d = uPointPos[i] - vWorldPos;
                float dist = max(length(d), 0.0001);
                float att = clamp(1.0 - dist / uPointColor[i].w, 0.0, 1.0);
                sum += uPointColor[i].rgb * (att * att) * pow(max(dot(n, normalize(v + d / dist)), 0.0), uShininess);
            }
            return sum;
        }

        vec3 flatNormal() {
            vec3 n = normalize(cross(dFdx(vWorldPos), dFdy(vWorldPos)));
            return gl_FrontFacing ? n : -n;
        }

        GLSL;

    private const FRAGMENT_FOOTER = <<<'GLSL'

        void main() {
            vec4 c = shade();
            float fog = clamp((distance(uEye, vWorldPos) - uFogRange.x) / max(uFogRange.y - uFogRange.x, 0.0001), 0.0, 1.0);
            c.rgb = mix(c.rgb, uFogColor, fog);
            fragColor = vec4(c.rgb * c.a, c.a);
        }
        GLSL;

    /** What the standard material does: texture * color, lit by the scene, plus highlights and emission. */
    public const STANDARD = <<<'GLSL'
        vec4 shade() {
            vec4 t = texture(uTexture, vUV);
            vec3 base = mix(t.rgb, t.rgb / max(t.a, 0.0001), uPremultiplied) * uColor.rgb;
            vec3 n = normalize(gl_FrontFacing ? vNormal : -vNormal);
            vec3 lit = mix(sceneLight(n), vec3(1.0), uUnlit);
            vec3 color = base * lit + uEmissive;
            if (uSpecular > 0.0 && uUnlit < 0.5) color += sceneSpecular(n) * uSpecular;
            return vec4(color, t.a * uColor.a);
        }
        GLSL;

    private ?Shader $program = null;
    private ?GL $gl = null;

    /**
     * @param string $fragment GLSL defining shade()
     * @param string $displace GLSL defining displace(), or '' to leave the vertices alone
     * @param ?\Closure(Shader, Mesh): void $uniforms called before each draw, with the shader in use, to set your own uniforms
     */
    public function __construct(
        public readonly string $fragment,
        public readonly string $displace = '',
        public readonly ?\Closure $uniforms = null,
    ) {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** Compiles on first use. */
    public function program(GL $gl): Shader
    {
        if ($this->program === null) {
            $this->gl = $gl;
            $this->program = new Shader(
                $gl,
                self::VERTEX_HEADER . ($this->displace !== '' ? $this->displace : self::NO_DISPLACE) . self::VERTEX_FOOTER,
                self::FRAGMENT_HEADER . $this->fragment . self::FRAGMENT_FOOTER,
            );
        }
        return $this->program;
    }

    public function dispose(): void
    {
        $this->program?->dispose();
        $this->program = null;
    }
}
