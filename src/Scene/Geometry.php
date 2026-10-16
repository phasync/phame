<?php

namespace Phame\Scene;

use Phame\Engine;
use Phame\GL\Buffer;
use Phame\GL\GL;
use Phame\GL\VertexArray;
use Phame\GL\VertexLayout;
use Phame\Math\Vector3;

/**
 * Triangle mesh data on the GPU: interleaved position (3), normal (3) and texture coordinates (2) per vertex,
 * and 16 bit indices. Triangles are counter-clockwise seen from the front. Texture v runs downward like in
 * images, so the top of a texture is v = 0.
 */
final class Geometry
{
    public readonly int $indexCount;
    private ?VertexArray $vao = null;
    private ?Buffer $vertexBuffer = null;
    private ?Buffer $indexBuffer = null;
    private ?GL $gl = null;
    /** The axis-aligned box around the vertices, in the geometry's own space. */
    public readonly Vector3 $boundsMin;
    public readonly Vector3 $boundsMax;
    /** @var list<float> x, y, z, nx, ny, nz, u, v for each vertex */
    public readonly array $vertices;
    /** @var list<int> three per triangle */
    public readonly array $indices;
    /**
     * Where this came from, if a loader (glTF, later) built it from an asset rather than `box()`/`sphere()`/a
     * hand-built array: "url#sub-id" (e.g. "/assets/ship.glb#mesh0"). Null (the default, for everything today)
     * means Net\View sends the vertex/index data inline; set, it sends this instead, and a browser fetches and
     * caches the asset itself - large mesh data never goes over the WebSocket. See docs/REMOTE-VIEW.md.
     */
    public ?string $source = null;

    /**
     * Building a Geometry never touches the GPU: the vertex and index data (and the bounds derived from them) are
     * plain PHP values from the start, readable without an Engine - a headless process describing a scene for
     * Net\View (see docs/REMOTE-VIEW.md) builds the same Mesh/Geometry a renderer would, with no GL context at all.
     * The GPU resources (a VAO, its buffers) are created lazily, on the first draw() in a process that has a GL
     * context.
     *
     * @param list<float> $vertices x, y, z, nx, ny, nz, u, v for each vertex
     * @param list<int>   $indices  three per triangle
     */
    public function __construct(array $vertices, array $indices)
    {
        if (count($vertices) % 8 !== 0 || count($vertices) === 0) {
            throw new \InvalidArgumentException('Vertices are 8 floats each: x, y, z, nx, ny, nz, u, v');
        }
        $count = intdiv(count($vertices), 8);
        if ($count > 65536) {
            throw new \InvalidArgumentException("A geometry holds at most 65536 vertices, got $count");
        }
        if (count($indices) % 3 !== 0 || count($indices) === 0 || max($indices) >= $count || min($indices) < 0) {
            throw new \InvalidArgumentException('Indices must come in triangles and refer to existing vertices');
        }
        $this->boundsMin = Vector3::get(INF, INF, INF);
        $this->boundsMax = Vector3::get(-INF, -INF, -INF);
        for ($i = 0, $n = count($vertices); $i < $n; $i += 8) {
            $this->boundsMin->set(min($this->boundsMin->x, $vertices[$i]), min($this->boundsMin->y, $vertices[$i + 1]), min($this->boundsMin->z, $vertices[$i + 2]));
            $this->boundsMax->set(max($this->boundsMax->x, $vertices[$i]), max($this->boundsMax->y, $vertices[$i + 1]), max($this->boundsMax->z, $vertices[$i + 2]));
        }
        $this->vertices = $vertices;
        $this->indices = $indices;
        $this->indexCount = count($indices);
    }

    /** Creates the VAO and its buffers, the first time something actually draws this geometry. */
    private function upload(): void
    {
        if ($this->vao !== null) {
            return;
        }
        $this->gl = $gl = Engine::current()->gl;
        $this->vertexBuffer = new Buffer($gl, GL::ARRAY_BUFFER, GL::STATIC_DRAW);
        $this->vertexBuffer->upload(pack('f*', ...$this->vertices));
        $this->indexBuffer = new Buffer($gl, GL::ELEMENT_ARRAY_BUFFER, GL::STATIC_DRAW);
        $this->vao = new VertexArray($gl, $this->vertexBuffer, (new VertexLayout())->add(0, 3)->add(1, 3)->add(2, 2), $this->indexBuffer);
        // The index buffer is part of the VAO state, so it must be uploaded while the VAO is bound.
        $this->vao->bind();
        $this->indexBuffer->upload(pack('S*', ...$this->indices));
        $gl->bindVertexArray(0);
    }

    public function draw(): void
    {
        $this->upload();
        $this->vao->bind();
        $this->gl->drawElements(GL::TRIANGLES, $this->indexCount, GL::UNSIGNED_SHORT, 0);
    }

    /**
     * The distance along the ray, in multiples of $direction's length, at which it enters the bounding box, or INF
     * if it misses. Both are given in the geometry's space. A ray that starts inside the box returns 0.
     */
    public function rayDistance(Vector3 $origin, Vector3 $direction): float
    {
        $near = 0.0;
        $far = INF;
        $min = $this->boundsMin;
        $max = $this->boundsMax;
        if (!self::slab($origin->x, $direction->x, $min->x, $max->x, $near, $far)
            || !self::slab($origin->y, $direction->y, $min->y, $max->y, $near, $far)
            || !self::slab($origin->z, $direction->z, $min->z, $max->z, $near, $far)) {
            return INF;
        }
        return $near <= $far ? $near : INF;
    }

    /** Narrows [near, far] to where the ray is between the two planes of one axis; false if it never is. */
    private static function slab(float $o, float $d, float $lo, float $hi, float &$near, float &$far): bool
    {
        if ($d == 0.0) {
            return $o >= $lo && $o <= $hi;
        }
        $t1 = ($lo - $o) / $d;
        $t2 = ($hi - $o) / $d;
        $near = max($near, min($t1, $t2));
        $far = min($far, max($t1, $t2));
        return true;
    }

    public function dispose(): void
    {
        $this->vao?->dispose();
        $this->vertexBuffer?->dispose();
        $this->indexBuffer?->dispose();
        $this->boundsMin->release();
        $this->boundsMax->release();
    }

    /** A box centered on the origin; every face has its own 0..1 texture coordinates. */
    public static function box(float $width = 1.0, float $height = 1.0, float $depth = 1.0): self
    {
        return new self(...self::boxData($width, $height, $depth));
    }

    /** @return array{list<float>, list<int>} */
    private static function boxData(float $width, float $height, float $depth): array
    {
        [$x, $y, $z] = [$width / 2, $height / 2, $depth / 2];
        // each face: normal, then its four corners ordered top-left, top-right, bottom-right, bottom-left as seen from outside
        $faces = [
            [[0, 0, 1], [-$x, $y, $z], [$x, $y, $z], [$x, -$y, $z], [-$x, -$y, $z]],
            [[0, 0, -1], [$x, $y, -$z], [-$x, $y, -$z], [-$x, -$y, -$z], [$x, -$y, -$z]],
            [[1, 0, 0], [$x, $y, $z], [$x, $y, -$z], [$x, -$y, -$z], [$x, -$y, $z]],
            [[-1, 0, 0], [-$x, $y, -$z], [-$x, $y, $z], [-$x, -$y, $z], [-$x, -$y, -$z]],
            [[0, 1, 0], [-$x, $y, -$z], [$x, $y, -$z], [$x, $y, $z], [-$x, $y, $z]],
            [[0, -1, 0], [-$x, -$y, $z], [$x, -$y, $z], [$x, -$y, -$z], [-$x, -$y, -$z]],
        ];
        $uvs = [[0, 0], [1, 0], [1, 1], [0, 1]];
        $vertices = $indices = [];
        foreach ($faces as $f => [$n, $a, $b, $c, $d]) {
            foreach ([$a, $b, $c, $d] as $i => $p) {
                array_push($vertices, ...$p, ...$n, ...$uvs[$i]);
            }
            $o = $f * 4;
            array_push($indices, $o, $o + 3, $o + 2, $o, $o + 2, $o + 1);
        }
        return [$vertices, $indices];
    }

    /** A horizontal rectangle in the xz plane, facing up (+y), centered on the origin, cut into $segments squares per side (for displacement shaders). */
    public static function plane(float $width = 1.0, float $depth = 1.0, int $segments = 1): self
    {
        return new self(...self::planeData($width, $depth, $segments));
    }

    /** @return array{list<float>, list<int>} */
    private static function planeData(float $width, float $depth, int $segments): array
    {
        if ($segments < 1 || $segments > 255) {
            throw new \InvalidArgumentException('A plane has 1 to 255 segments per side');
        }
        $vertices = $indices = [];
        for ($j = 0; $j <= $segments; ++$j) {
            for ($i = 0; $i <= $segments; ++$i) {
                $u = $i / $segments;
                $v = $j / $segments;
                array_push($vertices, ($u - 0.5) * $width, 0, ($v - 0.5) * $depth, 0, 1, 0, $u, $v);
            }
        }
        $stride = $segments + 1;
        for ($j = 0; $j < $segments; ++$j) {
            for ($i = 0; $i < $segments; ++$i) {
                $a = $j * $stride + $i;
                array_push($indices, $a, $a + $stride, $a + $stride + 1, $a, $a + $stride + 1, $a + 1);
            }
        }
        return [$vertices, $indices];
    }

    /** A UV sphere centered on the origin, with its poles on the y axis. */
    public static function sphere(float $radius = 0.5, int $segments = 24, int $rings = 16): self
    {
        return new self(...self::sphereData($radius, $segments, $rings));
    }

    /** @return array{list<float>, list<int>} */
    private static function sphereData(float $radius, int $segments, int $rings): array
    {
        $vertices = $indices = [];
        for ($r = 0; $r <= $rings; ++$r) {
            $v = $r / $rings;
            $phi = $v * M_PI;
            for ($s = 0; $s <= $segments; ++$s) {
                $u = $s / $segments;
                $theta = $u * 2 * M_PI;
                $nx = sin($phi) * cos($theta);
                $ny = cos($phi);
                $nz = -sin($phi) * sin($theta);
                array_push($vertices, $nx * $radius, $ny * $radius, $nz * $radius, $nx, $ny, $nz, $u, $v);
            }
        }
        $stride = $segments + 1;
        for ($r = 0; $r < $rings; ++$r) {
            for ($s = 0; $s < $segments; ++$s) {
                $a = $r * $stride + $s;
                $b = $a + $stride;
                array_push($indices, $a, $b, $a + 1, $a + 1, $b, $b + 1);
            }
        }
        return [$vertices, $indices];
    }

    /** An uncapped tube along the y axis, from 0 to $height, centered on the x/z origin. A bone between two joints. */
    public static function cylinder(float $radius = 0.5, float $height = 1.0, int $segments = 12): self
    {
        return new self(...self::cylinderData($radius, $height, $segments));
    }

    /** @return array{list<float>, list<int>} */
    private static function cylinderData(float $radius, float $height, int $segments): array
    {
        $vertices = $indices = [];
        for ($i = 0; $i <= $segments; ++$i) {
            $u = $i / $segments;
            $a = $u * 2 * M_PI;
            [$nx, $nz] = [cos($a), sin($a)];
            array_push($vertices, $nx * $radius, 0.0, $nz * $radius, $nx, 0.0, $nz, $u, 0.0);
            array_push($vertices, $nx * $radius, $height, $nz * $radius, $nx, 0.0, $nz, $u, 1.0);
            if ($i < $segments) {
                $o = $i * 2;
                array_push($indices, $o, $o + 1, $o + 2, $o + 1, $o + 3, $o + 2);
            }
        }
        return [$vertices, $indices];
    }
}
