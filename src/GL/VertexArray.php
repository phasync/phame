<?php

namespace Phame\GL;

/** A VAO: a vertex buffer interpreted through a layout, plus an optional index buffer. */
final class VertexArray
{
    public readonly int $id;

    public function __construct(private readonly GL $gl, public readonly Buffer $vertices, public readonly VertexLayout $layout, public readonly ?Buffer $indices = null)
    {
        $this->id = $gl->gen('VertexArrays');
        $gl->bindVertexArray($this->id);
        $vertices->bind();
        foreach ($layout->attributes as $a) {
            $gl->enableVertexAttribArray($a['location']);
            $gl->vertexAttribPointer($a['location'], $a['size'], $a['type'], $a['normalized'] ? 1 : 0, $layout->stride, $a['offset']);
        }
        $indices?->bind();
        $gl->bindVertexArray(0);
    }

    public function bind(): void
    {
        $this->gl->bindVertexArray($this->id);
    }

    public function dispose(): void
    {
        $this->gl->delete('VertexArrays', $this->id);
    }
}
