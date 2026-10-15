<?php

namespace Phame\Platform;

use FFI\CData;
use Phame\GL\GL;

/**
 * An SDL window with an OpenGL 3.3 core context. A headless window uses SDL's offscreen video driver: there
 * is no display, but there is a real GPU context, and the pixels can be read back.
 */
final class Window
{
    private readonly \FFI $sdl;
    private ?CData $window;
    private ?CData $context;
    private CData $event;
    private CData $eventPointer;
    public readonly GL $gl;

    public function __construct(string $title, public readonly int $width, public readonly int $height, public readonly bool $headless = false, bool $vsync = true, bool $resizable = true)
    {
        $this->sdl = $sdl = SDL::ffi();
        if ($headless) {
            $sdl->SDL_SetHint('SDL_VIDEODRIVER', 'offscreen');
        }
        if ($sdl->SDL_Init(SDL::INIT_VIDEO) !== 0) {
            throw new \RuntimeException('SDL_Init failed: ' . $sdl->SDL_GetError());
        }
        $sdl->SDL_GL_SetAttribute(SDL::GL_CONTEXT_MAJOR_VERSION, 3);
        $sdl->SDL_GL_SetAttribute(SDL::GL_CONTEXT_MINOR_VERSION, 3);
        $sdl->SDL_GL_SetAttribute(SDL::GL_DEPTH_SIZE, 24);
        $sdl->SDL_GL_SetAttribute(SDL::GL_CONTEXT_PROFILE_MASK, SDL::GL_PROFILE_CORE);
        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS only offers a core profile context that is forward compatible.
            $sdl->SDL_GL_SetAttribute(SDL::GL_CONTEXT_FLAGS, SDL::GL_FORWARD_COMPATIBLE_FLAG);
        }
        $flags = SDL::WINDOW_OPENGL | SDL::WINDOW_ALLOW_HIGHDPI | ($headless ? SDL::WINDOW_HIDDEN : 0) | ($resizable && !$headless ? SDL::WINDOW_RESIZABLE : 0);
        $this->window = $sdl->SDL_CreateWindow($title, 0x2FFF0000, 0x2FFF0000, $width, $height, $flags);
        if ($this->window === null) {
            throw new \RuntimeException('SDL_CreateWindow failed: ' . $sdl->SDL_GetError());
        }
        $this->context = $sdl->SDL_GL_CreateContext($this->window);
        if ($this->context === null) {
            throw new \RuntimeException('Could not create an OpenGL 3.3 core context: ' . $sdl->SDL_GetError());
        }
        $sdl->SDL_GL_SetSwapInterval($vsync && !$headless ? 1 : 0);
        $this->event = $sdl->new('SDL_Event');
        $this->eventPointer = \FFI::addr($this->event);
        $this->gl = new GL(static fn (string $name) => $sdl->SDL_GL_GetProcAddress($name));
    }

    /** Size of the drawable in pixels: larger than the window's size on high-DPI displays. @return array{int, int} */
    public function getDrawableSize(): array
    {
        $w = $this->sdl->new('int[1]');
        $h = $this->sdl->new('int[1]');
        $this->sdl->SDL_GL_GetDrawableSize($this->window, $w, $h);
        return [$w[0], $h[0]];
    }

    /** Size of the window in screen units, the unit of mouse coordinates. @return array{int, int} */
    public function getSize(): array
    {
        $w = $this->sdl->new('int[1]');
        $h = $this->sdl->new('int[1]');
        $this->sdl->SDL_GetWindowSize($this->window, $w, $h);
        return [$w[0], $h[0]];
    }

    public function setTitle(string $title): void
    {
        $this->sdl->SDL_SetWindowTitle($this->window, $title);
    }

    public function swap(): void
    {
        $this->sdl->SDL_GL_SwapWindow($this->window);
    }

    /**
     * Drains the event queue. The yielded SDL_Event is reused: read what is needed before advancing.
     *
     * @return \Generator<int, CData>
     */
    public function pollEvents(): \Generator
    {
        while ($this->sdl->SDL_PollEvent($this->eventPointer) !== 0) {
            yield $this->event;
        }
    }

    public function dispose(): void
    {
        if ($this->window === null) {
            return;
        }
        $this->sdl->SDL_GL_DeleteContext($this->context);
        $this->sdl->SDL_DestroyWindow($this->window);
        $this->window = $this->context = null;
    }
}
