<?php

namespace Phame\Rendering;

use Phame\Display\DisplayObject;
use Phame\GL\GL;
use Phame\GL\Shader;
use Phame\GL\Texture2D;
use Phame\Math\IntRect;
use Phame\Textures\RenderTexture;
use Phame\Textures\Texture;

/**
 * Draws display objects. It keeps the state that accumulates while the display list is walked (the transform
 * from local to stage coordinates, alpha, blend mode) and batches quads that share a texture and blend mode
 * into one draw call. A quad that changes the texture or blend mode flushes the batch first.
 *
 * The transform is kept as plain float properties, not a Matrix object: this is the hot path.
 */
final class Painter
{
    /** The vertex stage every quad program shares (see Filter): inputs aPos, aUV, aColor; outputs vUV, vColor. */
    public const VERTEX = <<<'GLSL'
        #version 330 core
        layout(location = 0) in vec2 aPos;
        layout(location = 1) in vec2 aUV;
        layout(location = 2) in vec4 aColor;
        uniform vec4 uProjection; // scale x, scale y, offset x, offset y
        out vec2 vUV;
        out vec4 vColor;
        void main() {
            gl_Position = vec4(aPos * uProjection.xy + uProjection.zw, 0.0, 1.0);
            vUV = aUV;
            vColor = aColor;
        }
        GLSL;

    private const FRAGMENT = <<<'GLSL'
        #version 330 core
        in vec2 vUV;
        in vec4 vColor;
        uniform sampler2D uTexture;
        uniform float uPremultiplied;
        out vec4 fragColor;
        void main() {
            vec4 t = texture(uTexture, vUV);
            t.rgb *= mix(t.a, 1.0, uPremultiplied);
            fragColor = t * vColor;
        }
        GLSL;

    /** Current transform, local to stage: x' = a*x + c*y + tx, y' = b*x + d*y + ty. */
    public float $a = 1.0;
    public float $b = 0.0;
    public float $c = 0.0;
    public float $d = 1.0;
    public float $tx = 0.0;
    public float $ty = 0.0;
    public float $alpha = 1.0;
    public int $blendMode = BlendMode::NORMAL;

    public private(set) int $drawCalls = 0;
    public private(set) int $quadsDrawn = 0;

    private readonly Shader $shader;
    private readonly QuadBatch $batch;
    private readonly Texture $white;
    /** @var list<float|int> eight slots per level of the display list: a, b, c, d, tx, ty, alpha, blendMode */
    private array $stack = [];
    private int $depth = 0;

    // The state the pending quads in the batch will be drawn with.
    private ?Texture2D $gpu = null;
    private bool $premultiplied = false;
    private int $batchBlend = BlendMode::NORMAL;
    // The state of the GL context.
    private ?int $appliedBlend = null;
    private ?int $boundTexture = null;
    /** Size of the current render target in points (the stage, or a RenderTexture). */
    public private(set) int $width = 1;
    public private(set) int $height = 1;
    // Where the painter draws: the window, or a render texture. The state of the outer target waits on the stacks.
    private ?RenderTexture $target = null;
    /** @var list<?RenderTexture> */
    private array $targets = [];
    /** @var list<float|int> twelve slots per nested target: transform, alpha, blend mode, width, height, depth */
    private array $targetStack = [];
    private int $targetDepth = 0;

    public function __construct(private readonly GL $gl)
    {
        $this->shader = new Shader($gl, self::VERTEX, self::FRAGMENT);
        $this->batch = new QuadBatch($gl);
        $this->white = Texture::fromColor(1, 1, 0xFFFFFFFF);
        $this->stack = array_fill(0, 8 * 32, 0.0);
    }

    /** Starts a frame: stage coordinates (0,0) to ($width,$height) fill the current viewport. */
    public function begin(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->a = $this->d = $this->alpha = 1.0;
        $this->b = $this->c = $this->tx = $this->ty = 0.0;
        $this->blendMode = BlendMode::NORMAL;
        $this->depth = 0;
        $this->drawCalls = $this->quadsDrawn = 0;
        $this->gpu = null;
        $this->target = null;
        $this->targets = [];
        $this->targetDepth = 0;
        $this->restore();
    }

    /** Re-applies the GL state the painter relies on, after other code (see Scene3D) has used the context. Flush first. */
    public function restore(): void
    {
        $gl = $this->gl;
        $this->appliedBlend = $this->boundTexture = null;
        $gl->bindFramebuffer(GL::FRAMEBUFFER, $this->target?->framebuffer ?? 0);
        $viewport = $this->stageViewport(IntRect::get());
        $gl->viewport($viewport->x, $viewport->y, $viewport->width, $viewport->height);
        $viewport->release();
        $gl->enable(GL::BLEND);
        $this->shader->use();
        $this->applyProjection($this->shader);
        $gl->uniform1i($this->shader->uniform('uTexture'), 0);
    }

    private function applyProjection(Shader $shader): void
    {
        $this->gl->uniform4f($shader->uniform('uProjection'), 2.0 / $this->width, -2.0 / $this->height, -1.0, 1.0);
    }

    /** Writes the part of the target the stage covers, as a GL viewport (origin at the bottom left), into $out. */
    public function stageViewport(IntRect $out): IntRect
    {
        return $this->viewportOf($out, 0.0, 0.0, $this->width, $this->height);
    }

    /** Writes a rectangle in stage coordinates, as a GL viewport (origin at the bottom left), into $out. */
    public function viewportOf(IntRect $out, float $x, float $y, float $w, float $h): IntRect
    {
        if ($this->target === null) {
            return \Phame\Engine::current()->viewportOf($out, $x, $y, $w, $h);
        }
        $scale = $this->target->scale;
        $out->x = (int) round($x * $scale);
        $out->y = (int) round($this->target->pixelHeight - ($y + $h) * $scale);
        $out->width = (int) round($w * $scale);
        $out->height = (int) round($h * $scale);
        return $out;
    }

    /**
     * Redirects drawing into a render texture, until popTarget(). Its top left corner becomes 0,0 and the
     * transform, alpha and blend mode start afresh. Targets nest. It does not clear the texture.
     */
    public function pushTarget(RenderTexture $target): void
    {
        $this->flush();
        $i = $this->targetDepth++ * 12;
        $t = &$this->targetStack;
        $t[$i] = $this->a;
        $t[$i + 1] = $this->b;
        $t[$i + 2] = $this->c;
        $t[$i + 3] = $this->d;
        $t[$i + 4] = $this->tx;
        $t[$i + 5] = $this->ty;
        $t[$i + 6] = $this->alpha;
        $t[$i + 7] = $this->blendMode;
        $t[$i + 8] = $this->width;
        $t[$i + 9] = $this->height;
        $t[$i + 10] = $this->depth;
        $this->targets[] = $this->target;
        $this->target = $target;
        $this->width = (int) ceil($target->width);
        $this->height = (int) ceil($target->height);
        $this->a = $this->d = $this->alpha = 1.0;
        $this->b = $this->c = $this->tx = $this->ty = 0.0;
        $this->blendMode = BlendMode::NORMAL;
        $this->restore();
    }

    public function popTarget(): void
    {
        $this->flush();
        $i = --$this->targetDepth * 12;
        $t = &$this->targetStack;
        $this->a = $t[$i];
        $this->b = $t[$i + 1];
        $this->c = $t[$i + 2];
        $this->d = $t[$i + 3];
        $this->tx = $t[$i + 4];
        $this->ty = $t[$i + 5];
        $this->alpha = $t[$i + 6];
        $this->blendMode = (int) $t[$i + 7];
        $this->width = (int) $t[$i + 8];
        $this->height = (int) $t[$i + 9];
        $this->depth = (int) $t[$i + 10];
        $this->target = array_pop($this->targets);
        $this->restore();
    }

    /** Draws a child display object: enters it, draws it (through its filter, if it has one) and leaves it. */
    public function drawChild(DisplayObject $o): void
    {
        $this->push($o);
        if ($o->filter === null) {
            $o->render($this);
        } else {
            $o->filter->render($this, $o);
        }
        $this->pop();
    }

    /**
     * Makes $shader, which must use self::VERTEX, the program for the next drawQuadWith(), with the painter's
     * projection and `uTexture` bound to unit 0. Set the shader's other uniforms after this call. The painter
     * goes back to its own program on its next restore().
     */
    public function useShader(Shader $shader): void
    {
        $this->flush();
        $shader->use();
        $this->applyProjection($shader);
        $shader->setInt('uTexture', 0);
    }

    /** Draws a rectangle from (0,0) to ($w,$h) in local coordinates with the program from useShader() and the texture on unit 0, then restores the painter's program. */
    public function drawQuadWith(Texture $texture, float $w, float $h): void
    {
        $texture->gpu->bind(0);
        $this->gl->blendFunc(BlendMode::sourceFactor($this->blendMode), BlendMode::destinationFactor($this->blendMode));
        $alpha = $this->alpha;
        $x1 = $this->a * $w + $this->tx;
        $y1 = $this->b * $w + $this->ty;
        $ch = $this->c * $h;
        $dh = $this->d * $h;
        $color = (int) ($alpha * 255 + 0.5) * 0x01010101;
        $this->batch->add($this->tx, $this->ty, $x1, $y1, $this->tx + $ch, $this->ty + $dh, $x1 + $ch, $y1 + $dh,
            $texture->u0, $texture->v0, $texture->u1, $texture->v1, $color);
        $this->batch->draw();
        ++$this->drawCalls;
        $this->restore();
    }

    public function clear(int $rgb, float $alpha = 1.0): void
    {
        $this->gl->clearColor((($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255, $alpha);
        $this->gl->clear(GL::COLOR_BUFFER_BIT);
    }

    /** Enters a child: the state becomes the parent's state combined with the child's. Pair with pop(). */
    public function push(DisplayObject $o): void
    {
        $o->updateMatrix();
        $i = $this->depth++ << 3;
        $s = &$this->stack;
        $pa = $this->a;
        $pb = $this->b;
        $pc = $this->c;
        $pd = $this->d;
        $ptx = $this->tx;
        $pty = $this->ty;
        $s[$i] = $pa;
        $s[$i + 1] = $pb;
        $s[$i + 2] = $pc;
        $s[$i + 3] = $pd;
        $s[$i + 4] = $ptx;
        $s[$i + 5] = $pty;
        $s[$i + 6] = $this->alpha;
        $s[$i + 7] = $this->blendMode;
        $la = $o->ma;
        $lb = $o->mb;
        $lc = $o->mc;
        $ld = $o->md;
        $this->a = $pa * $la + $pc * $lb;
        $this->b = $pb * $la + $pd * $lb;
        $this->c = $pa * $lc + $pc * $ld;
        $this->d = $pb * $lc + $pd * $ld;
        $this->tx = $pa * $o->mtx + $pc * $o->mty + $ptx;
        $this->ty = $pb * $o->mtx + $pd * $o->mty + $pty;
        $this->alpha *= $o->alpha;
        if ($o->blendMode !== BlendMode::AUTO) {
            $this->blendMode = $o->blendMode;
        }
    }

    public function pop(): void
    {
        $i = --$this->depth << 3;
        $s = &$this->stack;
        $this->a = $s[$i];
        $this->b = $s[$i + 1];
        $this->c = $s[$i + 2];
        $this->d = $s[$i + 3];
        $this->tx = $s[$i + 4];
        $this->ty = $s[$i + 5];
        $this->alpha = $s[$i + 6];
        $this->blendMode = (int) $s[$i + 7];
    }

    /**
     * Draws a rectangle from (0,0) to ($w,$h) in the current local coordinates, tinted with $rgb (0xRRGGBB)
     * and the current alpha. Without a texture it is a solid rectangle.
     */
    public function drawQuad(?Texture $texture, float $w, float $h, int $rgb): void
    {
        $texture ??= $this->white;
        $blend = $this->blendMode;
        if ($texture->gpu !== $this->gpu || $texture->premultiplied !== $this->premultiplied || $blend !== $this->batchBlend || $this->batch->isFull()) {
            $this->flush();
            $this->gpu = $texture->gpu;
            $this->premultiplied = $texture->premultiplied;
            $this->batchBlend = $blend;
        }
        $alpha = $this->alpha;
        $x1 = $this->a * $w + $this->tx;
        $y1 = $this->b * $w + $this->ty;
        $ch = $this->c * $h;
        $dh = $this->d * $h;
        $color = (int) ((($rgb >> 16 & 255) * $alpha) + 0.5)
            | (int) ((($rgb >> 8 & 255) * $alpha) + 0.5) << 8
            | (int) ((($rgb & 255) * $alpha) + 0.5) << 16
            | (int) ($alpha * 255 + 0.5) << 24;
        $this->batch->add(
            $this->tx, $this->ty, $x1, $y1, $this->tx + $ch, $this->ty + $dh, $x1 + $ch, $y1 + $dh,
            $texture->u0, $texture->v0, $texture->u1, $texture->v1, $color,
        );
        ++$this->quadsDrawn;
    }

    /** Draws what has been collected so far. Called when the state changes and at the end of the frame. */
    public function flush(): void
    {
        if ($this->batch->count === 0 || $this->gpu === null) {
            return;
        }
        $gl = $this->gl;
        if ($this->boundTexture !== $this->gpu->id) {
            $this->gpu->bind(0);
            $this->boundTexture = $this->gpu->id;
        }
        $gl->uniform1f($this->shader->uniform('uPremultiplied'), $this->premultiplied ? 1.0 : 0.0);
        if ($this->appliedBlend !== $this->batchBlend) {
            $gl->blendFunc(BlendMode::sourceFactor($this->batchBlend), BlendMode::destinationFactor($this->batchBlend));
            $this->appliedBlend = $this->batchBlend;
        }
        $this->batch->draw();
        ++$this->drawCalls;
    }

    public function end(): void
    {
        $this->flush();
    }

    public function dispose(): void
    {
        $this->batch->dispose();
        $this->shader->dispose();
        $this->white->dispose();
    }
}
