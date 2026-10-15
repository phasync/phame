<?php

namespace Phame\Rendering;

use Phame\Display\DisplayObject;
use Phame\Engine;
use Phame\GL\Shader;
use Phame\Textures\RenderTexture;

/**
 * A fragment shader applied to a display object and everything below it: `$object->filter = new Filter(...)`.
 * The object is drawn into a texture, which is then run through $passes passes of your shader and drawn where
 * the object was. The filter keeps GPU textures for this: use one instance per object, and dispose() it.
 *
 * Your GLSL defines `vec4 filterPixel(vec2 uv)`. Available to it:
 *   sampler2D uTexture   the input of this pass (the object, or the output of the previous pass)
 *   sampler2D uOriginal  the object as drawn, for compositing in later passes
 *   vec2  uTexelSize     the size of one texel in uv units
 *   float uTime          game seconds since the engine started
 *   int   uPass          0 for the first pass
 * Colors are premultiplied by alpha, in and out. In uv, y is 1 at the top. Set your own uniforms in setUniforms().
 * Shaders that make the picture bigger than the object (blurs, glows) need a $margin.
 */
class Filter
{
    /** Extra space around the object, in points, that the filter may draw into. */
    public float $margin = 0.0;
    /** Texels per point: below 1 is cheaper and softer. */
    public float $resolution = 1.0;

    private const HEADER = <<<'GLSL'
        #version 330 core
        in vec2 vUV;
        in vec4 vColor;
        uniform sampler2D uTexture;
        uniform sampler2D uOriginal;
        uniform vec2 uTexelSize;
        uniform float uTime;
        uniform int uPass;
        out vec4 fragColor;

        GLSL;

    private const FOOTER = "\nvoid main() { fragColor = filterPixel(vUV) * vColor; }\n";

    private ?Shader $shader = null;
    private ?RenderTexture $source = null;
    private ?RenderTexture $ping = null;
    private ?RenderTexture $pong = null;

    /** @param ?\Closure(Shader, int): void $uniforms called for each pass, with the shader in use, to set its uniforms */
    public function __construct(private readonly string $glsl, public readonly int $passes = 1, private readonly ?\Closure $uniforms = null)
    {
        if ($passes < 1) {
            throw new \InvalidArgumentException('A filter has at least one pass');
        }
    }

    /** Override to set uniforms, instead of passing a closure. */
    protected function setUniforms(Shader $shader, int $pass): void
    {
        ($this->uniforms)?->__invoke($shader, $pass);
    }

    /** Called by the Painter, after entering $object: draws it through the filter at the painter's transform. */
    final public function render(Painter $painter, DisplayObject $object): void
    {
        $bounds = $object->getBounds($object);
        $w = $bounds->width + 2 * $this->margin;
        $h = $bounds->height + 2 * $this->margin;
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }
        $engine = Engine::current();
        // Creating GL objects disturbs the context state the painter caches.
        $painter->flush();
        $this->ensureTargets($w, $h);
        $this->shader ??= new Shader($engine->gl, Painter::VERTEX, self::HEADER . $this->glsl . self::FOOTER);
        $shader = $this->shader;
        $painter->restore();

        $painter->pushTarget($this->source);
        $painter->clear(0x000000, 0.0);
        $painter->tx = $this->margin - $bounds->x;
        $painter->ty = $this->margin - $bounds->y;
        $object->render($painter);
        $painter->popTarget();

        $original = $this->source->texture->region(0, 0, $w, $h);
        $input = $original;
        for ($pass = 0; $pass < $this->passes; ++$pass) {
            $last = $pass === $this->passes - 1;
            $target = null;
            if (!$last) {
                $target = $pass % 2 === 0 ? $this->ping : $this->pong;
                $painter->pushTarget($target);
                $painter->clear(0x000000, 0.0);
            }
            $painter->useShader($shader);
            $original->gpu->bind(1);
            $shader->setInt('uOriginal', 1);
            $shader->set('uTexelSize', 1.0 / $input->gpu->width, 1.0 / $input->gpu->height);
            $shader->set('uTime', (float) $engine->clock->time);
            $shader->setInt('uPass', $pass);
            $this->setUniforms($shader, $pass);
            if ($last) {
                $dx = $bounds->x - $this->margin;
                $dy = $bounds->y - $this->margin;
                $tx = $painter->tx;
                $ty = $painter->ty;
                $painter->tx += $painter->a * $dx + $painter->c * $dy;
                $painter->ty += $painter->b * $dx + $painter->d * $dy;
                $painter->drawQuadWith($input, $w, $h);
                $painter->tx = $tx;
                $painter->ty = $ty;
            } else {
                $painter->drawQuadWith($input, $w, $h);
                $painter->popTarget();
                $input = $target->texture->region(0, 0, $w, $h);
            }
        }
    }

    /** Targets are allocated in steps of 32 points so that an object that grows does not allocate every frame. */
    private function ensureTargets(float $w, float $h): void
    {
        $w = max(32.0, ceil($w / 32) * 32);
        $h = max(32.0, ceil($h / 32) * 32);
        $fits = static fn (?RenderTexture $t) => $t !== null && $t->width === $w && $t->height === $h;
        if (!$fits($this->source)) {
            $this->source?->dispose();
            $this->source = new RenderTexture($w, $h, $this->resolution);
        }
        if ($this->passes > 1 && !$fits($this->ping)) {
            $this->ping?->dispose();
            $this->ping = new RenderTexture($w, $h, $this->resolution, false);
        }
        if ($this->passes > 2 && !$fits($this->pong)) {
            $this->pong?->dispose();
            $this->pong = new RenderTexture($w, $h, $this->resolution, false);
        }
    }

    public function dispose(): void
    {
        $this->shader?->dispose();
        $this->source?->dispose();
        $this->ping?->dispose();
        $this->pong?->dispose();
        $this->shader = $this->source = $this->ping = $this->pong = null;
    }
}
