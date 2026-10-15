<?php

namespace Phame\GL;

/** A GPU texture: RGBA, 8 bits per channel, straight (non-premultiplied) alpha unless the producer says otherwise. */
final class Texture2D
{
    public readonly int $id;
    private bool $disposed = false;

    /** @param ?string $rgba width*height*4 bytes, top row first; null allocates uninitialised storage */
    public function __construct(private readonly GL $gl, public readonly int $width, public readonly int $height, ?string $rgba = null, bool $smooth = true)
    {
        if ($rgba !== null && strlen($rgba) !== $width * $height * 4) {
            throw new \InvalidArgumentException(sprintf('Expected %d bytes for a %dx%d RGBA texture, got %d', $width * $height * 4, $width, $height, strlen($rgba)));
        }
        $this->id = $gl->gen('Textures');
        $gl->bindTexture(GL::TEXTURE_2D, $this->id);
        $gl->pixelStorei(GL::UNPACK_ALIGNMENT, 1);
        $gl->texImage2D(GL::TEXTURE_2D, 0, GL::RGBA, $width, $height, 0, GL::RGBA, GL::UNSIGNED_BYTE, $rgba);
        $this->setSmooth($smooth);
        $gl->texParameteri(GL::TEXTURE_2D, GL::TEXTURE_WRAP_S, GL::CLAMP_TO_EDGE);
        $gl->texParameteri(GL::TEXTURE_2D, GL::TEXTURE_WRAP_T, GL::CLAMP_TO_EDGE);
    }

    public function setSmooth(bool $smooth): void
    {
        $filter = $smooth ? GL::LINEAR : GL::NEAREST;
        $this->gl->bindTexture(GL::TEXTURE_2D, $this->id);
        $this->gl->texParameteri(GL::TEXTURE_2D, GL::TEXTURE_MIN_FILTER, $filter);
        $this->gl->texParameteri(GL::TEXTURE_2D, GL::TEXTURE_MAG_FILTER, $filter);
    }

    public function bind(int $unit = 0): void
    {
        $this->gl->activeTexture(GL::TEXTURE0 + $unit);
        $this->gl->bindTexture(GL::TEXTURE_2D, $this->id);
    }

    public function dispose(): void
    {
        if (!$this->disposed) {
            $this->disposed = true;
            $this->gl->delete('Textures', $this->id);
        }
    }

    public function __destruct()
    {
        // The context can be gone, or replaced by another: only delete from the context that created the texture.
        if (!$this->disposed && \Phame\Engine::isAlive() && \Phame\Engine::current()->gl === $this->gl) {
            $this->dispose();
        }
    }
}
