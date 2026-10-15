<?php

namespace Phame\Scene;

use Phame\Math\Vector3;

/** A node that draws a geometry with a material. */
final class Mesh extends Node3D
{
    public function __construct(public Geometry $geometry, public Material $material = new Material())
    {
    }

    /** Writes the axis-aligned box around the transformed geometry, in the space of the scene root, into $min and $max. */
    protected function localBox(Vector3 $min, Vector3 $max): void
    {
        $min->copyFrom($this->geometry->boundsMin);
        $max->copyFrom($this->geometry->boundsMax);
    }

    public function worldBounds(Vector3 $min, Vector3 $max): void
    {
        $world = $this->getWorldMatrix();
        $lo = $this->geometry->boundsMin;
        $hi = $this->geometry->boundsMax;
        $corner = Vector3::get();
        $min->set(INF, INF, INF);
        $max->set(-INF, -INF, -INF);
        for ($i = 0; $i < 8; ++$i) {
            $world->transformPoint($corner->set($i & 1 ? $hi->x : $lo->x, $i & 2 ? $hi->y : $lo->y, $i & 4 ? $hi->z : $lo->z));
            $min->set(min($min->x, $corner->x), min($min->y, $corner->y), min($min->z, $corner->z));
            $max->set(max($max->x, $corner->x), max($max->y, $corner->y), max($max->z, $corner->z));
        }
        $corner->release();
    }

    /** Whether the world-space bounding boxes of the two meshes overlap (touching counts). */
    public function intersects(Mesh $other): bool
    {
        $aMin = Vector3::get();
        $aMax = Vector3::get();
        $bMin = Vector3::get();
        $bMax = Vector3::get();
        $this->worldBounds($aMin, $aMax);
        $other->worldBounds($bMin, $bMax);
        $hit = $aMin->x <= $bMax->x && $aMax->x >= $bMin->x
            && $aMin->y <= $bMax->y && $aMax->y >= $bMin->y
            && $aMin->z <= $bMax->z && $aMax->z >= $bMin->z;
        $aMin->release();
        $aMax->release();
        $bMin->release();
        $bMax->release();
        return $hit;
    }
}
