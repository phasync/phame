<?php

namespace Phame\GL;

/** A GPU buffer object: vertex data (ARRAY_BUFFER) or indices (ELEMENT_ARRAY_BUFFER). */
final class Buffer
{
    public readonly int $id;

    public function __construct(private readonly GL $gl, public readonly int $target, private readonly int $usage = GL::STREAM_DRAW)
    {
        $this->id = $gl->gen('Buffers');
    }

    public function bind(): void
    {
        $this->gl->bindBuffer($this->target, $this->id);
    }

    /** Replaces the contents. Orphans the old storage, so the GPU can keep drawing from it while the new data is written. */
    public function upload(string $bytes): void
    {
        $this->gl->bindBuffer($this->target, $this->id);
        $this->gl->bufferData($this->target, strlen($bytes), $bytes, $this->usage);
    }

    public function dispose(): void
    {
        $this->gl->delete('Buffers', $this->id);
    }
}
