<?php

namespace Phame;

use phasync;
use Phame\Animation\Juggler;
use Phame\Display\DisplayObjectContainer;
use Phame\Display\DisplayObject;
use Phame\Display\Stage;
use Phame\Events\EnterFrameEvent;
use Phame\Events\Event;
use Phame\Events\KeyEvent;
use Phame\Events\TouchEvent;
use Phame\GL\GL;
use Phame\Math\IntRect;
use Phame\Platform\SDL;
use Phame\Platform\Window;
use Phame\Rendering\MeshRenderer;
use Phame\Rendering\Painter;
use Phame\Textures\Png;

/**
 * Shows a World in a window: owns the window, the GL context and the painter, and runs the frame loop that ticks
 * the world and draws it. $engine->stage, ->clock, ->juggler and ->go() are the world's.
 *
 * The loop is a phasync coroutine. Each frame it handles input, ticks the world (which advances game time and wakes
 * the coroutines that wait for the frame), then draws. Anything else running in phasync, such as network I/O,
 * shares the loop with the game.
 *
 * There is one engine per process. A headless engine has a GPU context but no window on screen: it renders
 * frames that can be read back with readPixels(), which is what the tests do.
 */
final class Engine
{
    private static ?Engine $current = null;

    public readonly Window $window;
    public readonly GL $gl;
    /** The game this engine shows. */
    public readonly World $world;
    public Stage $stage { get => $this->world->stage; }
    public Juggler $juggler { get => $this->world->juggler; }
    public Clock $clock { get => $this->world->clock; }
    public \stdClass $frameFlag { get => $this->world->frameFlag; }
    public readonly Painter $painter;
    /** Created when the first Scene3D is drawn. */
    public MeshRenderer $meshRenderer { get => $this->meshRendererInstance ??= new MeshRenderer($this->gl); }
    private ?MeshRenderer $meshRendererInstance = null;

    /** Stops the loop after this many frames, if set. */
    public ?int $maxFrames = null;
    private bool $quitRequested = false;

    // The area of the drawable the stage is scaled into, in drawable pixels: the stage keeps its aspect ratio.
    private float $viewX = 0.0;
    private float $viewY = 0.0;
    private float $viewScale = 1.0;
    private float $pixelRatio = 1.0;
    private int $drawableHeight = 0;

    private ?DisplayObject $touchTarget = null;
    /** The mixer: sound effects and music. Opened on first use. */
    public \Phame\Audio\Audio $audio {
        get => $this->audioInstance ??= new \Phame\Audio\Audio($this->headless);
    }
    private ?\Phame\Audio\Audio $audioInstance = null;
    /** Where the mouse (or the last touch) is, in stage coordinates. */
    public private(set) float $mouseX = 0.0;
    public private(set) float $mouseY = 0.0;
    /** Seconds the last frame spent on input, the juggler and coroutines (game logic). */
    public private(set) float $updateTime = 0.0;
    /** Seconds the last frame spent building and submitting draw calls (and mixing audio). */
    public private(set) float $renderTime = 0.0;
    /** Seconds the whole last frame took, including the buffer swap (which may wait for vsync or the GPU). */
    public private(set) float $frameTime = 0.0;

    public function __construct(
        public readonly int $stageWidth = 800,
        public readonly int $stageHeight = 600,
        string $title = 'Phame',
        public readonly bool $headless = false,
        bool $vsync = true,
        ?int $windowWidth = null,
        ?int $windowHeight = null,
    ) {
        if (self::$current !== null) {
            throw new \LogicException('There is already an engine: create one per process');
        }
        $this->window = new Window($title, $windowWidth ?? $stageWidth, $windowHeight ?? $stageHeight, $headless, $vsync);
        $this->gl = $this->window->gl;
        self::$current = $this;
        $this->world = new World($stageWidth, $stageHeight);
        $this->painter = new Painter($this->gl);
        $this->updateViewport();
    }

    public static function current(): self
    {
        return self::$current ?? throw new \LogicException('No engine has been created');
    }

    public static function isAlive(): bool
    {
        return self::$current !== null;
    }

    /**
     * Runs the game until quit(). $main runs as a coroutine, and may start others with go(). Everything still
     * running when the loop ends is cancelled. An exception that escapes any coroutine stops the game, and is
     * rethrown from here once the other coroutines have cleaned up.
     */
    public function run(?\Closure $main = null): void
    {
        $this->quitRequested = false;
        phasync::run(fn () => $this->world->run(function (World $world) use ($main) {
            if ($main !== null) {
                $world->go(fn () => $main($this));
            }
            $this->loop();
        }));
        if (($failure = $this->world->takeFailure()) !== null) {
            throw $failure;
        }
    }

    /** See World::go(). */
    public function go(\Closure $coroutine): \Fiber
    {
        return $this->world->go($coroutine);
    }

    /** Whether the key is held down right now. $keyCode is a Key constant (an SDL keycode). */
    public function isKeyDown(int $keyCode): bool
    {
        return $this->world->isKeyDown($keyCode);
    }

    /** Presses or releases a key as if the player did: for tests, demos, replays and bots. See Key for the codes. */
    public function simulateKey(int $keyCode, bool $down, bool $repeat = false): void
    {
        $this->world->simulateKey($keyCode, $down, $repeat);
    }

    public function quit(): void
    {
        $this->quitRequested = true;
    }

    private function loop(): void
    {
        $last = hrtime(true);
        while (!$this->quitRequested && !$this->world->stopped) {
            $now = hrtime(true);
            $seconds = min(0.1, ($now - $last) / 1e9);
            $last = $now;
            $this->processEvents();
            $this->world->tick($seconds);
            $rendering = hrtime(true);
            $this->render();
            $this->audioInstance?->pump($this->clock->realDelta);
            $swapping = hrtime(true);
            $this->window->swap();
            $this->updateTime = ($rendering - $now) / 1e9;
            $this->renderTime = ($swapping - $rendering) / 1e9;
            $this->frameTime = (hrtime(true) - $now) / 1e9;
            if ($this->maxFrames !== null && $this->clock->frame >= $this->maxFrames) {
                $this->quit();
            }
        }
    }

    /** Advances the world's game time without waking coroutines (see World::advance()). */
    public function advance(float $seconds): void
    {
        $this->world->advance($seconds);
    }

    /** Draws the stage into the window's back buffer. */
    public function render(): void
    {
        $gl = $this->gl;
        [$dw, $dh] = $this->window->getDrawableSize();
        $vx = (int) round($this->viewX);
        $vy = (int) round($dh - $this->viewY - $this->stageHeight * $this->viewScale);
        $vw = (int) round($this->stageWidth * $this->viewScale);
        $vh = (int) round($this->stageHeight * $this->viewScale);
        // Black bars around the stage, then the stage's background color inside it.
        $gl->viewport(0, 0, $dw, $dh);
        $this->painter->clear(0x000000);
        $gl->enable(GL::SCISSOR_TEST);
        $gl->scissor($vx, $vy, $vw, $vh);
        $this->painter->clear($this->stage->color);
        $gl->disable(GL::SCISSOR_TEST);
        $gl->viewport($vx, $vy, $vw, $vh);
        $this->painter->begin($this->stageWidth, $this->stageHeight);
        $this->stage->render($this->painter);
        $this->painter->end();
    }

    /** Advances time and draws one frame without a loop or presenting it. For tests and offline rendering. */
    public function renderFrame(float $seconds = 1 / 60): void
    {
        $this->advance($seconds);
        $this->render();
    }

    /**
     * The pixels of the last rendered frame as RGBA bytes, top row first. Read before the buffers are swapped.
     *
     * @return array{int, int, string} width, height, data
     */
    public function readPixels(): array
    {
        [$w, $h] = $this->window->getDrawableSize();
        $size = $w * $h * 4;
        $buffer = $this->gl->alloc("uint8_t[$size]");
        $this->gl->pixelStorei(GL::PACK_ALIGNMENT, 1);
        $this->gl->readPixels(0, 0, $w, $h, GL::RGBA, GL::UNSIGNED_BYTE, $buffer);
        $flat = \FFI::string($buffer, $size);
        $rows = [];
        for ($y = $h - 1; $y >= 0; --$y) {
            $rows[] = substr($flat, $y * $w * 4, $w * 4);
        }
        return [$w, $h, implode('', $rows)];
    }

    public function saveScreenshot(string $path): void
    {
        [$w, $h, $rgba] = $this->readPixels();
        file_put_contents($path, Png::encode($w, $h, $rgba));
    }

    private function updateViewport(): void
    {
        [$dw, $dh] = $this->window->getDrawableSize();
        $this->drawableHeight = $dh;
        [$ww] = $this->window->getSize();
        $this->pixelRatio = $ww > 0 ? $dw / $ww : 1.0;
        $this->viewScale = min($dw / $this->stageWidth, $dh / $this->stageHeight);
        $this->viewX = ($dw - $this->stageWidth * $this->viewScale) / 2;
        $this->viewY = ($dh - $this->stageHeight * $this->viewScale) / 2;
    }

    /** Writes the part of the drawable the stage is scaled into, as a GL viewport (origin at the bottom left), into $out. */
    public function stageViewport(IntRect $out): IntRect
    {
        return $this->viewportOf($out, 0.0, 0.0, $this->stageWidth, $this->stageHeight);
    }

    /** Writes a rectangle in stage coordinates, as a GL viewport (origin at the bottom left), into $out. */
    public function viewportOf(IntRect $out, float $x, float $y, float $w, float $h): IntRect
    {
        $out->x = (int) round($this->viewX + $x * $this->viewScale);
        $out->y = (int) round($this->drawableHeight - $this->viewY - ($y + $h) * $this->viewScale);
        $out->width = (int) round($w * $this->viewScale);
        $out->height = (int) round($h * $this->viewScale);
        return $out;
    }

    /** Window coordinates (as in mouse events) to stage coordinates. @return array{float, float} */
    public function windowToStage(float $x, float $y): array
    {
        return [($x * $this->pixelRatio - $this->viewX) / $this->viewScale, ($y * $this->pixelRatio - $this->viewY) / $this->viewScale];
    }

    private function processEvents(): void
    {
        foreach ($this->window->pollEvents() as $e) {
            switch ($e->type) {
                case SDL::EVENT_QUIT:
                    $this->quit();
                    break;
                case SDL::EVENT_WINDOW:
                    if ($e->window->event === SDL::WINDOW_EVENT_SIZE_CHANGED) {
                        $this->updateViewport();
                        $this->stage->dispatchEvent(new Event(Event::RESIZE));
                    }
                    break;
                case SDL::EVENT_KEY_DOWN:
                case SDL::EVENT_KEY_UP:
                    $this->simulateKey($e->key->keysym->sym, $e->type === SDL::EVENT_KEY_DOWN, $e->key->repeat !== 0);
                    break;
                case SDL::EVENT_MOUSE_MOTION:
                    $this->pointer($e->motion->x, $e->motion->y, $this->touchTarget !== null ? TouchEvent::MOVED : TouchEvent::HOVER, 0);
                    break;
                case SDL::EVENT_MOUSE_DOWN:
                    $this->pointer($e->button->x, $e->button->y, TouchEvent::BEGAN, $e->button->button);
                    break;
                case SDL::EVENT_MOUSE_UP:
                    $this->pointer($e->button->x, $e->button->y, TouchEvent::ENDED, $e->button->button);
                    break;
            }
        }
    }

    /**
     * Delivers a pointer event, in window coordinates. A press picks the target, and the rest of the gesture
     * goes to it, wherever the pointer moves, until released.
     */
    public function pointer(float $windowX, float $windowY, string $phase, int $button = 0): void
    {
        [$x, $y] = $this->windowToStage($windowX, $windowY);
        $this->mouseX = $x;
        $this->mouseY = $y;
        if ($phase === TouchEvent::BEGAN) {
            $this->touchTarget = $this->stage->hitTest($x, $y);
            $target = $this->touchTarget;
        } elseif ($phase === TouchEvent::HOVER) {
            $target = $this->stage->hitTest($x, $y);
        } else {
            $target = $this->touchTarget;
            if ($phase === TouchEvent::ENDED) {
                $this->touchTarget = null;
            }
        }
        $target?->dispatchEvent(new TouchEvent($phase, $x, $y, $button));
    }

    public function dispose(): void
    {
        $this->meshRendererInstance?->dispose();
        $this->audioInstance?->dispose();
        $this->painter->dispose();
        $this->world->dispose();
        self::$current = null;
        $this->window->dispose();
    }
}
