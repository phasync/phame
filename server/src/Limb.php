<?php

namespace PhameServer;

use Phame\Rendering\MeshShader;
use Phame\Scene\{Geometry, Material, Mesh};

/** A visible, solid piece of a ragdoll: a sphere that sits still on its joint and collides as part of the ragdoll. */
final class Limb extends Mesh
{
    private static ?Geometry $sphere = null;
    private static ?MeshShader $shader = null;
    /** @var array<int, Material> one Material per colour, not one per limb: as Ball's own note explains, Net\View
     * sends a Material's fields (its shader's GLSL included) inline every time that *object* is new, so every
     * ragdoll thrown in the same colour must share one Material, not mint a fresh one per limb. */
    private static array $materials = [];

    public function __construct(public readonly Ragdoll $doll, float $radius)
    {
        $material = self::$materials[$doll->by->color] ??= new Material(color: $doll->by->color, shader: self::$shader ??= self::material());
        parent::__construct(self::$sphere ??= Geometry::sphere(1.0), $material);
        $this->radius = $radius;
        $this->setScale($radius);
        $this->mass = 3.0 * $radius ** 3;
    }

    /** A crash test dummy: glossy plastic in the thrower's colour, with black quadrant markers on the round parts. */
    public static function material(): MeshShader
    {
        return new MeshShader(<<<'GLSL'
            vec4 shade() {
                vec3 n = normalize(vNormal);
                vec3 view = normalize(uEye - vWorldPos);
                vec3 p = normalize(vWorldPos - vCenter);
                float marker = step(0.0, p.x * p.y) * step(0.7, vRadius);   // not on the thinner bones
                vec3 albedo = mix(vColor, vec3(0.05), marker * 0.9);
                float diffuse = max(dot(n, SUN), 0.0);
                vec3 sky = mix(vec3(0.04, 0.035, 0.03), vec3(0.1, 0.12, 0.2), n.y * 0.5 + 0.5);
                float spec = pow(max(dot(reflect(-SUN, n), view), 0.0), 40.0) * 0.5;
                float rim = pow(1.0 - max(dot(n, view), 0.0), 3.0);
                return vec4(albedo * (sky + vec3(1.5, 1.35, 1.15) * diffuse) + spec + vColor * rim * 0.35, 1.0);
            }
            GLSL);
    }
}
