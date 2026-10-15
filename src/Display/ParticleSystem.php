<?php

namespace Phame\Display;

use Phame\Engine;
use Phame\Events\EnterFrameEvent;
use Phame\Events\Event;
use Phame\Math\Rectangle;
use Phame\Rendering\BlendMode;
use Phame\Rendering\Painter;
use Phame\Textures\Texture;

/**
 * A 2D particle emitter. Particles live in the system's own coordinates, so moving or rotating the system
 * moves them all, and they are emitted from its origin. The system advances itself every frame while it is on
 * the stage; nothing needs to be added to a juggler.
 *
 * The particles sit in fixed-size preallocated storage and are recycled, so a running system allocates nothing
 * per frame. Changing a setting affects particles emitted afterwards, not the ones already alive.
 *
 * ```php
 * $fire = new ParticleSystem(256);
 * $fire->emissionRate = 80;
 * $fire->startColor = 0xFFAA33;
 * $fire->endColor = 0xCC1100;
 * $fire->endAlpha = 0.0;
 * $fire->start();
 * $stage->addChild($fire);
 * ```
 *
 * Angles are in radians and follow the display convention: 0 points right, positive turns clockwise on screen,
 * so -M_PI / 2 points up. A variance of v means a uniformly random value in [value - v, value + v].
 */
class ParticleSystem extends DisplayObject
{
    /** Particles per second while emitting. */
    public float $emissionRate = 50.0;
    public float $lifetime = 1.0;
    public float $lifetimeVariance = 0.0;
    /** Initial speed in points per second. */
    public float $speed = 100.0;
    public float $speedVariance = 0.0;
    /** Direction of the emission. */
    public float $angle = -M_PI / 2;
    /** Particles leave within angle ± spread / 2. */
    public float $spread = M_PI / 4;
    public float $gravityX = 0.0;
    public float $gravityY = 0.0;
    /** Spin in radians per second. */
    public float $angularVelocity = 0.0;
    public float $angularVelocityVariance = 0.0;
    /** Edge length of the particle's square, at birth and at death. */
    public float $startSize = 16.0;
    public float $endSize = 16.0;
    /** 0xRRGGBB at birth and at death. */
    public int $startColor = 0xFFFFFF;
    public int $endColor = 0xFFFFFF;
    public float $startAlpha = 1.0;
    public float $endAlpha = 0.0;
    /** The particle image; a soft round dot when null. */
    public ?Texture $texture = null;
    public int $blendMode = BlendMode::ADD;

    public private(set) bool $isEmitting = false;
    public private(set) int $particleCount = 0;
    public readonly int $capacity;

    // One list per attribute, indexed by particle; the first $particleCount entries are alive.
    /** @var list<float> */
    private array $px;
    /** @var list<float> */
    private array $py;
    /** @var list<float> */
    private array $vx;
    /** @var list<float> */
    private array $vy;
    /** @var list<float> */
    private array $age;
    /** @var list<float> */
    private array $life;
    /** @var list<float> */
    private array $rot;
    /** @var list<float> */
    private array $spin;
    /** @var list<float> */
    private array $size0;
    /** @var list<float> */
    private array $size1;
    /** @var list<float> */
    private array $alpha0;
    /** @var list<float> */
    private array $alpha1;
    /** @var list<int> */
    private array $color0;
    /** @var list<int> */
    private array $color1;

    private float $emitAccumulator = 0.0;
    private int $rng = 2463534242;
    private readonly \Closure $tick;

    private static ?Texture $dot = null;
    private static ?\WeakReference $dotEngine = null;

    public function __construct(int $capacity = 512)
    {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('A particle system needs room for at least one particle');
        }
        $this->capacity = $capacity;
        foreach (['px', 'py', 'vx', 'vy', 'age', 'life', 'rot', 'spin', 'size0', 'size1', 'alpha0', 'alpha1'] as $name) {
            $this->$name = array_fill(0, $capacity, 0.0);
        }
        $this->color0 = $this->color1 = array_fill(0, $capacity, 0);
        $this->tick = $this->advance(...);
        $this->addEventListener(Event::ADDED_TO_STAGE, function (): void {
            $this->removeEventListener(Event::ENTER_FRAME, $this->tick);
            $this->addEventListener(Event::ENTER_FRAME, $this->tick);
        });
        $this->addEventListener(Event::REMOVED_FROM_STAGE, function (): void {
            $this->removeEventListener(Event::ENTER_FRAME, $this->tick);
        });
    }

    /** Makes the random sequence reproducible. */
    public function seed(int $seed): void
    {
        $this->rng = ($seed & 0xFFFFFFFF) ?: 2463534242;
    }

    public function start(): void
    {
        $this->isEmitting = true;
    }

    /** Stops emitting; particles that are alive finish their lives. */
    public function stop(): void
    {
        $this->isEmitting = false;
        $this->emitAccumulator = 0.0;
    }

    /** Emits $n particles at once, as many as there is room for. */
    public function burst(int $n): void
    {
        for ($i = 0; $i < $n && $this->particleCount < $this->capacity; ++$i) {
            $this->spawn();
        }
    }

    /** Moves all particles forward by $seconds. Called every frame while the system is on the stage. */
    public function advanceTime(float $seconds): void
    {
        $gx = $this->gravityX * $seconds;
        $gy = $this->gravityY * $seconds;
        for ($i = 0; $i < $this->particleCount;) {
            $age = $this->age[$i] + $seconds;
            if ($age >= $this->life[$i]) {
                $this->kill($i);
                continue;
            }
            $this->age[$i] = $age;
            $vx = $this->vx[$i] += $gx;
            $vy = $this->vy[$i] += $gy;
            $this->px[$i] += $vx * $seconds;
            $this->py[$i] += $vy * $seconds;
            $this->rot[$i] += $this->spin[$i] * $seconds;
            ++$i;
        }
        if ($this->isEmitting) {
            $this->emitAccumulator += $this->emissionRate * $seconds;
            while ($this->emitAccumulator >= 1.0) {
                $this->emitAccumulator -= 1.0;
                if ($this->particleCount < $this->capacity) {
                    $this->spawn();
                }
            }
        }
    }

    private function advance(EnterFrameEvent $e): void
    {
        $this->advanceTime($e->passedTime);
    }

    private function spawn(): void
    {
        $i = $this->particleCount++;
        $angle = $this->angle + ($this->random() - 0.5) * $this->spread;
        $speed = $this->speed + ($this->random() * 2.0 - 1.0) * $this->speedVariance;
        $this->px[$i] = 0.0;
        $this->py[$i] = 0.0;
        $this->vx[$i] = cos($angle) * $speed;
        $this->vy[$i] = sin($angle) * $speed;
        $this->age[$i] = 0.0;
        $this->life[$i] = max(0.001, $this->lifetime + ($this->random() * 2.0 - 1.0) * $this->lifetimeVariance);
        $this->rot[$i] = 0.0;
        $this->spin[$i] = $this->angularVelocity + ($this->random() * 2.0 - 1.0) * $this->angularVelocityVariance;
        $this->size0[$i] = $this->startSize;
        $this->size1[$i] = $this->endSize;
        $this->alpha0[$i] = $this->startAlpha;
        $this->alpha1[$i] = $this->endAlpha;
        $this->color0[$i] = $this->startColor;
        $this->color1[$i] = $this->endColor;
    }

    /** Frees slot $i by moving the last living particle into it. */
    private function kill(int $i): void
    {
        $last = --$this->particleCount;
        if ($i === $last) {
            return;
        }
        $this->px[$i] = $this->px[$last];
        $this->py[$i] = $this->py[$last];
        $this->vx[$i] = $this->vx[$last];
        $this->vy[$i] = $this->vy[$last];
        $this->age[$i] = $this->age[$last];
        $this->life[$i] = $this->life[$last];
        $this->rot[$i] = $this->rot[$last];
        $this->spin[$i] = $this->spin[$last];
        $this->size0[$i] = $this->size0[$last];
        $this->size1[$i] = $this->size1[$last];
        $this->alpha0[$i] = $this->alpha0[$last];
        $this->alpha1[$i] = $this->alpha1[$last];
        $this->color0[$i] = $this->color0[$last];
        $this->color1[$i] = $this->color1[$last];
    }

    /** xorshift32: a float in [0, 1). */
    private function random(): float
    {
        $x = $this->rng;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->rng = $x;
        return $x / 4294967296.0;
    }

    public function getBounds(?DisplayObject $space): Rectangle
    {
        $m = $this->getTransformationMatrix($space);
        [$x, $y] = $m->transform(0.0, 0.0);
        return new Rectangle($x, $y, 0.0, 0.0);
    }

    public function hitTest(float $x, float $y): ?DisplayObject
    {
        return null;
    }

    public function render(Painter $painter): void
    {
        if ($this->particleCount === 0) {
            return;
        }
        $texture = $this->texture ?? self::dot();
        $pa = $painter->a;
        $pb = $painter->b;
        $pc = $painter->c;
        $pd = $painter->d;
        $ptx = $painter->tx;
        $pty = $painter->ty;
        $palpha = $painter->alpha;
        for ($i = 0, $n = $this->particleCount; $i < $n; ++$i) {
            $t = $this->age[$i] / $this->life[$i];
            $size = $this->size0[$i] + ($this->size1[$i] - $this->size0[$i]) * $t;
            if ($size <= 0.0) {
                continue;
            }
            $half = $size * 0.5;
            $rot = $this->rot[$i];
            if ($rot == 0.0) {
                $la = $ld = 1.0;
                $lb = $lc = 0.0;
                $ltx = $this->px[$i] - $half;
                $lty = $this->py[$i] - $half;
            } else {
                $cos = cos($rot);
                $sin = sin($rot);
                $la = $ld = $cos;
                $lb = $sin;
                $lc = -$sin;
                // the particle's centre, local (half, half), lands on its position
                $ltx = $this->px[$i] - ($cos - $sin) * $half;
                $lty = $this->py[$i] - ($sin + $cos) * $half;
            }
            $painter->a = $pa * $la + $pc * $lb;
            $painter->b = $pb * $la + $pd * $lb;
            $painter->c = $pa * $lc + $pc * $ld;
            $painter->d = $pb * $lc + $pd * $ld;
            $painter->tx = $pa * $ltx + $pc * $lty + $ptx;
            $painter->ty = $pb * $ltx + $pd * $lty + $pty;
            $painter->alpha = $palpha * ($this->alpha0[$i] + ($this->alpha1[$i] - $this->alpha0[$i]) * $t);
            $c0 = $this->color0[$i];
            $c1 = $this->color1[$i];
            $rgb = (int) (($c0 >> 16 & 255) + (($c1 >> 16 & 255) - ($c0 >> 16 & 255)) * $t + 0.5) << 16
                | (int) (($c0 >> 8 & 255) + (($c1 >> 8 & 255) - ($c0 >> 8 & 255)) * $t + 0.5) << 8
                | (int) (($c0 & 255) + (($c1 & 255) - ($c0 & 255)) * $t + 0.5);
            $painter->drawQuad($texture, $size, $size, $rgb);
        }
        $painter->a = $pa;
        $painter->b = $pb;
        $painter->c = $pc;
        $painter->d = $pd;
        $painter->tx = $ptx;
        $painter->ty = $pty;
        $painter->alpha = $palpha;
    }

    /** A 16x16 white disc that fades to transparent at its edge, made once per engine. */
    private static function dot(): Texture
    {
        $engine = Engine::current();
        if (self::$dot === null || self::$dotEngine?->get() !== $engine) {
            $rgba = '';
            for ($y = 0; $y < 16; ++$y) {
                for ($x = 0; $x < 16; ++$x) {
                    $d = min(1.0, sqrt(($x - 7.5) ** 2 + ($y - 7.5) ** 2) / 8.0);
                    $fade = (1.0 - $d) * (1.0 - $d);
                    $rgba .= "\xFF\xFF\xFF" . chr((int) ($fade * 255 + 0.5));
                }
            }
            self::$dot = Texture::fromRgba(16, 16, $rgba);
            self::$dotEngine = \WeakReference::create($engine);
        }
        return self::$dot;
    }
}
