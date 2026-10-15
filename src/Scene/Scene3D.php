<?php

namespace Phame\Scene;

use Phame\Display\DisplayObject;
use Phame\Engine;
use Phame\Math\IntRect;
use Phame\Math\Matrix4;
use Phame\Math\Rectangle;
use Phame\Math\Vector3;
use Phame\Rendering\Painter;

/**
 * A 3D scene shown in a rectangle of the 2D display list, like Starling's Sprite3D but with a full scene graph.
 * It sits among other display objects, so 2D interface can be drawn over it (and under it), and it keeps its
 * own depth buffer contents for the duration of its own drawing.
 *
 * It is drawn into the screen-aligned bounding box of its rectangle: do not rotate it, and alpha does not apply.
 * Touches that hit the rectangle are reported with the Scene3D as the target.
 */
final class Scene3D extends DisplayObject
{
    /** Everything that is drawn is a descendant of this node. */
    public readonly Node3D $root;
    /** Looks at the scene from the origin by default; move it, or add it as a child of a node to attach it. */
    public readonly Camera $camera;

    /** The direction the sun's light travels. */
    public readonly Vector3 $lightDirection;
    public int $lightColor = 0xFFFFFF;
    /** The light a surface gets whatever its direction. */
    public int $ambient = 0x404040;
    /** Surfaces fade to this color with distance, between $fogNear and $fogFar; null is no fog. */
    public ?int $fogColor = null;
    public float $fogNear = 10.0;
    public float $fogFar = 50.0;
    /** Fills the rectangle before drawing; null leaves what the 2D display list drew below it. */
    public ?int $background = null;

    /**
     * GLSL defining `vec3 environment(vec3 direction)`: the surroundings, for the background and for reflections
     * (browser viewers use it; '' for their default). SUN is the direction towards the key light.
     */
    public string $environment = '';

    /** The 3D world's edges, for Wake::leaveWorld() of nodes in this scene; infinite by default. */
    public float $worldMinX = -INF;
    public float $worldMinY = -INF;
    public float $worldMinZ = -INF;
    public float $worldMaxX = INF;
    public float $worldMaxY = INF;
    public float $worldMaxZ = INF;

    public function __construct(public readonly float $meshWidth, public readonly float $meshHeight)
    {
        $this->lightDirection = Vector3::get(-0.4, -1.0, -0.6);
        $this->root = new Node3D();
        $this->root->scene = $this;
        $this->camera = new Camera();
        $this->camera->z = 5.0;
    }

    public function getBounds(?DisplayObject $space): Rectangle
    {
        $m = $this->getTransformationMatrix($space);
        $xs = $ys = [];
        foreach ([[0.0, 0.0], [$this->meshWidth, 0.0], [0.0, $this->meshHeight], [$this->meshWidth, $this->meshHeight]] as [$px, $py]) {
            [$xs[], $ys[]] = $m->transform($px, $py);
        }
        return new Rectangle(min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys));
    }

    public function hitTest(float $x, float $y): ?DisplayObject
    {
        if (!$this->visible || !$this->touchable) {
            return null;
        }
        return $x >= 0.0 && $y >= 0.0 && $x < $this->meshWidth && $y < $this->meshHeight ? $this : null;
    }

    /**
     * Writes the world-space ray through a point of the scene's rectangle (x, y in the scene's own coordinates,
     * as `TouchEvent::getLocation($scene)` gives) into $origin and the normalized $direction.
     */
    public function ray(float $x, float $y, Vector3 $origin, Vector3 $direction): void
    {
        $world = $this->camera->getWorldMatrix();
        $tan = tan(deg2rad($this->camera->fov) / 2.0);
        $direction->set((2.0 * $x / $this->meshWidth - 1.0) * $tan * $this->meshWidth / $this->meshHeight, (1.0 - 2.0 * $y / $this->meshHeight) * $tan, -1.0);
        $world->transformDirection($direction)->normalize();
        $origin->set($world->m12, $world->m13, $world->m14);
    }

    /** The nearest visible mesh whose bounding box is under this point of the rectangle, or null. */
    public function pick(float $x, float $y): ?Mesh
    {
        $origin = Vector3::get();
        $direction = Vector3::get();
        $localOrigin = Vector3::get();
        $localDirection = Vector3::get();
        $identity = Matrix4::get();
        $this->ray($x, $y, $origin, $direction);
        $nearest = INF;
        $hit = $this->pickIn($this->root, $identity, $origin, $direction, $localOrigin, $localDirection, $nearest);
        $origin->release();
        $direction->release();
        $localOrigin->release();
        $localDirection->release();
        $identity->release();
        return $hit;
    }

    private function pickIn(Node3D $node, Matrix4 $parentWorld, Vector3 $origin, Vector3 $direction, Vector3 $localOrigin, Vector3 $localDirection, float &$nearest): ?Mesh
    {
        if (!$node->visible) {
            return null;
        }
        $world = Matrix4::get()->product($parentWorld, $node->getLocalMatrix());
        $hit = null;
        if ($node instanceof Mesh) {
            $inverse = Matrix4::get();
            if ($inverse->invertFrom($world)) {
                // The direction is not renormalized, so distances in the mesh's space equal distances in the world's.
                $inverse->transformPoint($localOrigin->copyFrom($origin));
                $inverse->transformDirection($localDirection->copyFrom($direction));
                $t = $node->geometry->rayDistance($localOrigin, $localDirection);
                if ($t < $nearest) {
                    $nearest = $t;
                    $hit = $node;
                }
            }
            $inverse->release();
        }
        foreach ($node->children as $child) {
            $hit = $this->pickIn($child, $world, $origin, $direction, $localOrigin, $localDirection, $nearest) ?? $hit;
        }
        $world->release();
        return $hit;
    }

    public function render(Painter $painter): void
    {
        $painter->flush();
        $engine = Engine::current();
        $tx = $painter->tx;
        $ty = $painter->ty;
        $a = $painter->a;
        $b = $painter->b;
        $c = $painter->c;
        $d = $painter->d;
        $w = $this->meshWidth;
        $h = $this->meshHeight;
        $x = min($tx, $tx + $a * $w, $tx + $c * $h, $tx + $a * $w + $c * $h);
        $y = min($ty, $ty + $b * $w, $ty + $d * $h, $ty + $b * $w + $d * $h);
        $right = max($tx, $tx + $a * $w, $tx + $c * $h, $tx + $a * $w + $c * $h);
        $bottom = max($ty, $ty + $b * $w, $ty + $d * $h, $ty + $b * $w + $d * $h);
        $viewport = $painter->viewportOf(IntRect::get(), $x, $y, $right - $x, $bottom - $y);
        $stage = $painter->stageViewport(IntRect::get());
        $scissor = IntRect::get()->copyFrom($viewport)->intersect($stage);
        $engine->meshRenderer->render($this, $viewport, $scissor);
        $viewport->release();
        $scissor->release();
        $stage->release();
        $painter->restore();
    }
}
