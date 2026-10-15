<?php

namespace Phame\Rendering;

use Phame\Math\Matrix4;
use Phame\Pool\Poolable;
use Phame\Scene\Mesh;

/** One mesh queued for drawing this frame. It owns its world matrix and gives it back to the pool on release. */
final class DrawItem
{
    use Poolable {
        release as private releaseToPool;
    }

    public Mesh $mesh;
    public Matrix4 $world;
    public float $distance = 0.0;

    private function __construct()
    {
    }

    public static function get(Mesh $mesh, Matrix4 $world, float $distance): self
    {
        $item = self::acquire();
        $item->mesh = $mesh;
        $item->world = $world;
        $item->distance = $distance;
        return $item;
    }

    public function release(): void
    {
        $this->world->release();
        $this->releaseToPool();
    }
}
