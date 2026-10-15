<?php

namespace Phame\Rendering;

use Phame\GL\Buffer;
use Phame\GL\GL;
use Phame\GL\VertexArray;
use Phame\GL\VertexLayout;

/**
 * Collects textured, tinted quads and draws them with one call. A vertex is 20 bytes: position (2 floats),
 * texture coordinate (2 floats) and a premultiplied RGBA color (4 bytes). The vertices are built as one PHP
 * string, which is handed to OpenGL without copying into C memory first.
 */
final class QuadBatch
{
    public const CAPACITY = 8192;

    private readonly VertexArray $vao;
    private readonly Buffer $vertices;
    private readonly Buffer $indices;
    private string $data = '';
    public private(set) int $count = 0;

    public function __construct(private readonly GL $gl)
    {
        $this->vertices = new Buffer($gl, GL::ARRAY_BUFFER, GL::STREAM_DRAW);
        $this->indices = new Buffer($gl, GL::ELEMENT_ARRAY_BUFFER, GL::STATIC_DRAW);
        $layout = (new VertexLayout())
            ->add(0, 2)
            ->add(1, 2)
            ->add(2, 4, GL::UNSIGNED_BYTE, true);
        $this->vao = new VertexArray($gl, $this->vertices, $layout, $this->indices);
        $this->indices->upload(pack('v*', ...self::indexPattern()));
    }

    /** @return list<int> two triangles per quad: corners are top left, top right, bottom left, bottom right */
    private static function indexPattern(): array
    {
        $indices = [];
        for ($i = 0; $i < self::CAPACITY; ++$i) {
            $v = $i * 4;
            array_push($indices, $v, $v + 1, $v + 2, $v + 1, $v + 3, $v + 2);
        }
        return $indices;
    }

    public function isFull(): bool
    {
        return $this->count >= self::CAPACITY;
    }

    /** Corners are given as top left (0), top right (1), bottom left (2), bottom right (3). $color is the packed premultiplied RGBA. */
    public function add(
        float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, float $x3, float $y3,
        float $u0, float $v0, float $u1, float $v1, int $color,
    ): void {
        $this->data .= pack('ffffVffffVffffVffffV',
            $x0, $y0, $u0, $v0, $color,
            $x1, $y1, $u1, $v0, $color,
            $x2, $y2, $u0, $v1, $color,
            $x3, $y3, $u1, $v1, $color);
        ++$this->count;
    }

    /** Draws the collected quads with the shader and texture currently bound, then empties the batch. */
    public function draw(): void
    {
        if ($this->count === 0) {
            return;
        }
        $this->vao->bind();
        $this->vertices->upload($this->data);
        $this->gl->drawElements(GL::TRIANGLES, $this->count * 6, GL::UNSIGNED_SHORT, 0);
        $this->data = '';
        $this->count = 0;
    }

    public function dispose(): void
    {
        $this->vao->dispose();
        $this->vertices->dispose();
        $this->indices->dispose();
    }
}
