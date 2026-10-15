<?php

namespace Phame\Scene;

use Phame\Math\Matrix4;

/** A perspective camera. It looks down its own -z axis; move and turn it like any node. */
final class Camera extends Node3D
{
    /** Vertical field of view in degrees. */
    public float $fov = 60.0;
    public float $near = 0.1;
    public float $far = 1000.0;

    /** Writes the projection into $out and returns it. */
    public function projectionMatrix(Matrix4 $out, float $aspect): Matrix4
    {
        return $out->perspective(deg2rad($this->fov), $aspect, $this->near, $this->far);
    }

    /** Writes the world-to-camera transform into $out and returns it. */
    public function viewMatrix(Matrix4 $out): Matrix4
    {
        return $out->invertFrom($this->getWorldMatrix()) ? $out : $out->identity();
    }
}
