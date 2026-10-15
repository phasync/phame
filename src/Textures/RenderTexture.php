<?php

namespace Phame\Textures;

use Phame\Display\DisplayObject;
use Phame\Engine;
use Phame\GL\GL;
use Phame\GL\Texture2D;

/**
 * A texture that display objects are drawn into. Use $texture like any other: `new Image($rt->texture)`.
 *
 * The pixels are premultiplied and stored the way OpenGL renders them, bottom row first; $texture has flipped
 * texture coordinates to compensate. A scene drawn into it (including a Scene3D) looks as it does on screen.
 */
final class RenderTexture
{
    public readonly Texture $texture;
    public readonly int $pixelWidth;
    public readonly int $pixelHeight;
    public readonly int $framebuffer;
    private readonly GL $gl;
    private ?int $depthBuffer = null;
    private bool $disposed = false;

    /**
     * @param float $width  size in points; the texture is $width * $scale pixels wide
     * @param bool  $depth  whether a depth buffer is attached, which a Scene3D needs
     */
    public function __construct(public readonly float $width, public readonly float $height, public readonly float $scale = 1.0, bool $depth = true, bool $smooth = true)
    {
        $this->gl = $gl = Engine::current()->gl;
        $this->pixelWidth = max(1, (int) ceil($width * $scale - 1e-9));
        $this->pixelHeight = max(1, (int) ceil($height * $scale - 1e-9));
        $gpu = new Texture2D($gl, $this->pixelWidth, $this->pixelHeight, null, $smooth);
        $this->texture = new Texture($gpu, $width, $height, 0.0, 1.0, 1.0, 0.0, true, $scale);
        $this->framebuffer = $gl->gen('Framebuffers');
        $previous = $this->currentFramebuffer();
        $gl->bindFramebuffer(GL::FRAMEBUFFER, $this->framebuffer);
        $gl->framebufferTexture2D(GL::FRAMEBUFFER, GL::COLOR_ATTACHMENT0, GL::TEXTURE_2D, $gpu->id, 0);
        if ($depth) {
            $this->depthBuffer = $gl->gen('Renderbuffers');
            $gl->bindRenderbuffer(GL::RENDERBUFFER, $this->depthBuffer);
            $gl->renderbufferStorage(GL::RENDERBUFFER, GL::DEPTH_COMPONENT24, $this->pixelWidth, $this->pixelHeight);
            $gl->framebufferRenderbuffer(GL::FRAMEBUFFER, GL::DEPTH_ATTACHMENT, GL::RENDERBUFFER, $this->depthBuffer);
        }
        $status = $gl->checkFramebufferStatus(GL::FRAMEBUFFER);
        $gl->bindFramebuffer(GL::FRAMEBUFFER, $previous);
        if ($status !== GL::FRAMEBUFFER_COMPLETE) {
            $this->dispose();
            throw new \RuntimeException(sprintf('Cannot render to a %dx%d texture: framebuffer status 0x%04X', $this->pixelWidth, $this->pixelHeight, $status));
        }
    }

    /**
     * Draws $object into the texture, with the object's own transformation (x, y, scale, ... place it inside the
     * texture; the top left corner is 0,0 in points). Clears to transparent first unless $clear is false, so that
     * several objects can be drawn on top of each other.
     */
    public function draw(DisplayObject $object, bool $clear = true): void
    {
        $painter = Engine::current()->painter;
        $painter->pushTarget($this);
        if ($clear) {
            $painter->clear(0x000000, 0.0);
        }
        $painter->drawChild($object);
        $painter->popTarget();
    }

    /** The pixels as straight-alpha RGBA bytes, top row first. Slow: it waits for the GPU. */
    public function readPixels(): string
    {
        $gl = $this->gl;
        $size = $this->pixelWidth * $this->pixelHeight * 4;
        $buffer = $gl->alloc("uint8_t[$size]");
        $previous = $this->currentFramebuffer();
        $gl->bindFramebuffer(GL::FRAMEBUFFER, $this->framebuffer);
        $gl->pixelStorei(GL::PACK_ALIGNMENT, 1);
        $gl->readPixels(0, 0, $this->pixelWidth, $this->pixelHeight, GL::RGBA, GL::UNSIGNED_BYTE, $buffer);
        $gl->bindFramebuffer(GL::FRAMEBUFFER, $previous);
        $flat = \FFI::string($buffer, $size);
        $stride = $this->pixelWidth * 4;
        $rows = [];
        for ($y = $this->pixelHeight - 1; $y >= 0; --$y) {
            $row = substr($flat, $y * $stride, $stride);
            // un-premultiply
            for ($x = 0; $x < $stride; $x += 4) {
                $a = ord($row[$x + 3]);
                if ($a !== 0 && $a !== 255) {
                    $row[$x] = chr(min(255, intdiv(ord($row[$x]) * 255 + intdiv($a, 2), $a)));
                    $row[$x + 1] = chr(min(255, intdiv(ord($row[$x + 1]) * 255 + intdiv($a, 2), $a)));
                    $row[$x + 2] = chr(min(255, intdiv(ord($row[$x + 2]) * 255 + intdiv($a, 2), $a)));
                }
            }
            $rows[] = $row;
        }
        return implode('', $rows);
    }

    private function currentFramebuffer(): int
    {
        $out = $this->gl->alloc('int[1]');
        $this->gl->getIntegerv(GL::FRAMEBUFFER_BINDING, $out);
        return $out[0];
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        $this->texture->dispose();
        $this->gl->delete('Framebuffers', $this->framebuffer);
        if ($this->depthBuffer !== null) {
            $this->gl->delete('Renderbuffers', $this->depthBuffer);
        }
    }

    public function __destruct()
    {
        if (!$this->disposed && Engine::isAlive() && Engine::current()->gl === $this->gl) {
            $this->dispose();
        }
    }
}
