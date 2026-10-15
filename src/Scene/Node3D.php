<?php

namespace Phame\Scene;

use Phame\Events\EventDispatcher;
use Phame\Math\Matrix4;
use Phame\Math\Vector3;

/**
 * A node in a 3D scene graph, the 3D counterpart of DisplayObject. Position, rotation (radians, applied in the
 * order z, x, y) and scale are relative to the parent. Events dispatched on a node bubble up through its parents.
 */
class Node3D extends EventDispatcher implements \Phame\Kinetic\Body, \Phame\Kinetic\Group
{
    use \Phame\Kinetic\Waits;

    /*
     * Motion description, as for DisplayObject: position and rotation at game time $mt plus their rates per second.
     * The getters read it at the current game time; every change rebases it and tells the world once. Readable from
     * outside (Physics\Space evaluates it inline in its hot loop), written only here.
     */
    public private(set) float $mx = 0.0;
    public private(set) float $my = 0.0;
    public private(set) float $mz = 0.0;
    /** Orientation at $mt, a unit quaternion. */
    private float $mqx = 0.0;
    private float $mqy = 0.0;
    private float $mqz = 0.0;
    private float $mqw = 1.0;
    public private(set) float $mt = 0.0;
    public private(set) float $mvx = 0.0;
    public private(set) float $mvy = 0.0;
    public private(set) float $mvz = 0.0;
    /** Angular velocity in the parent's space: the axis is its direction, the speed (rad/s) its length. */
    private float $mwx = 0.0;
    private float $mwy = 0.0;
    private float $mwz = 0.0;
    /** @internal The clock of the world the node is in, for reading the description. */
    public ?\Phame\Clock $clock = null;
    /** @internal The Scene3D whose root this node is. */
    public ?Scene3D $scene = null;
    /** @internal How many waiting coroutines watch this node's children (see Wake). */
    public int $dependents = 0;

    /**
     * A sphere of this radius takes part in its scene's collision detection (see Physics\Space) while the node is a
     * direct child of the scene's root. 0: no collisions.
     */
    public float $radius = 0.0 {
        set { $this->radius = $value; $this->syncPhysics(); $this->physicsSpace?->changed($this); }
    }
    public float $mass = 1.0;
    /** Whether the walls of the scene's box hold it in (see Scene3D's world bounds). False: it goes straight through. */
    public bool $confined = true;
    /** The last contact the world handled for this node (set before the collision handler runs). */
    public ?\Phame\Physics\Contact $impact = null;
    /** @internal */
    public ?\Phame\Physics\Space $physicsSpace = null;
    /** @internal */
    public int $physicsCounter = 0;
    /** @internal The cell of the physics grid it is in, packed (see Space). */
    public int $physicsCell = 0;

    /** @internal Joins or leaves the scene's physics space, as radius and position in the tree say. */
    public function syncPhysics(): void
    {
        $scene = $this->parent?->scene;
        $world = $scene?->stage?->world;
        $space = $this->radius > 0.0 && $world !== null && $this->clock !== null ? $world->physics($scene) : null;
        if ($space !== $this->physicsSpace) {
            $this->physicsSpace?->remove($this);
            $space?->add($this);
        }
    }
    private float $localTime = -1.0;

    public float $x { get => $this->mvx == 0.0 ? $this->mx : $this->mx + $this->mvx * $this->age(); set { $this->rebase(); $this->mx = $value; $this->edited(); } }
    public float $y { get => $this->mvy == 0.0 ? $this->my : $this->my + $this->mvy * $this->age(); set { $this->rebase(); $this->my = $value; $this->edited(); } }
    public float $z { get => $this->mvz == 0.0 ? $this->mz : $this->mz + $this->mvz * $this->age(); set { $this->rebase(); $this->mz = $value; $this->edited(); } }
    /**
     * Euler angles (radians, applied to a point in the order z, x, y): a view of the orientation, which is a
     * quaternion. Setting one turns the node (the others are kept, its spin goes on).
     */
    public float $rotationX { get { $this->eulerNow(); return self::$ex; } set { $this->eulerNow(); $this->setEuler($value, self::$ey, self::$ez); } }
    public float $rotationY { get { $this->eulerNow(); return self::$ey; } set { $this->eulerNow(); $this->setEuler(self::$ex, $value, self::$ez); } }
    public float $rotationZ { get { $this->eulerNow(); return self::$ez; } set { $this->eulerNow(); $this->setEuler(self::$ex, self::$ey, $value); } }
    /** The orientation now, as a unit quaternion. */
    public float $orientationX { get { $this->orientationNow(); return self::$qx; } }
    public float $orientationY { get { $this->orientationNow(); return self::$qy; } }
    public float $orientationZ { get { $this->orientationNow(); return self::$qz; } }
    public float $orientationW { get { $this->orientationNow(); return self::$qw; } }

    private static float $qx = 0.0;
    private static float $qy = 0.0;
    private static float $qz = 0.0;
    private static float $qw = 1.0;
    private static float $ex = 0.0;
    private static float $ey = 0.0;
    private static float $ez = 0.0;

    /** The orientation at the current game time into the $q statics: the start orientation turned by omega * age. */
    private function orientationNow(): void
    {
        $qx = $this->mqx;
        $qy = $this->mqy;
        $qz = $this->mqz;
        $qw = $this->mqw;
        $speed = sqrt($this->mwx * $this->mwx + $this->mwy * $this->mwy + $this->mwz * $this->mwz);
        if ($speed != 0.0) {
            $half = $speed * $this->age() / 2;
            $k = sin($half) / $speed;
            $dx = $this->mwx * $k;   // the turn so far, about the axis of omega (in the parent's space: applied first)
            $dy = $this->mwy * $k;
            $dz = $this->mwz * $k;
            $dw = cos($half);
            [$qx, $qy, $qz, $qw] = [
                $dw * $qx + $dx * $qw + $dy * $qz - $dz * $qy,
                $dw * $qy - $dx * $qz + $dy * $qw + $dz * $qx,
                $dw * $qz + $dx * $qy - $dy * $qx + $dz * $qw,
                $dw * $qw - $dx * $qx - $dy * $qy - $dz * $qz,
            ];
        }
        self::$qx = $qx;
        self::$qy = $qy;
        self::$qz = $qz;
        self::$qw = $qw;
    }

    /** The current orientation as Euler angles into the $e statics. */
    private function eulerNow(): void
    {
        $this->orientationNow();
        [$x, $y, $z, $w] = [self::$qx, self::$qy, self::$qz, self::$qw];
        $m9 = 2.0 * ($y * $z - $w * $x);   // the matrix entries Euler angles are read from (see Matrix4::compose)
        self::$ex = asin(max(-1.0, min(1.0, -$m9)));
        self::$ey = atan2(2.0 * ($x * $z + $w * $y), 1.0 - 2.0 * ($x * $x + $y * $y));
        self::$ez = atan2(2.0 * ($x * $y + $w * $z), 1.0 - 2.0 * ($x * $x + $z * $z));
    }

    /** Sets the orientation from Euler angles (q = qy * qx * qz, as Matrix4::compose() applies them). */
    private function setEuler(float $rx, float $ry, float $rz): void
    {
        [$cx, $sx] = [cos($rx / 2), sin($rx / 2)];
        [$cy, $sy] = [cos($ry / 2), sin($ry / 2)];
        [$cz, $sz] = [cos($rz / 2), sin($rz / 2)];
        $this->setOrientation(
            $cy * $sx * $cz + $sy * $cx * $sz,
            $sy * $cx * $cz - $cy * $sx * $sz,
            $cy * $cx * $sz - $sy * $sx * $cz,
            $cy * $cx * $cz + $sy * $sx * $sz,
        );
    }

    /** Turns the node to the orientation of a unit quaternion; its spin goes on from there. */
    public function setOrientation(float $x, float $y, float $z, float $w): void
    {
        $this->rebase();
        $n = sqrt($x * $x + $y * $y + $z * $z + $w * $w) ?: 1.0;
        $this->mqx = $x / $n;
        $this->mqy = $y / $n;
        $this->mqz = $z / $n;
        $this->mqw = $w / $n;
        $this->edited();
    }

    /** Units per second of game time, in the parent's space. */
    public float $velocityX { get => $this->mvx; set { $this->rebase(); $this->mvx = $value; $this->edited(); } }
    public float $velocityY { get => $this->mvy; set { $this->rebase(); $this->mvy = $value; $this->edited(); } }
    public float $velocityZ { get => $this->mvz; set { $this->rebase(); $this->mvz = $value; $this->edited(); } }

    /** Teleports: puts the node at the point and stops it there. */
    public function moveTo(float $x, float $y, float $z): void
    {
        $this->rebase();
        $this->mx = $x;
        $this->my = $y;
        $this->mz = $z;
        $this->mvx = $this->mvy = $this->mvz = 0.0;
        $this->edited();
    }

    /** Linear motion from now on, in units per second (the parent's space). */
    public function setVelocity(float $vx, float $vy, float $vz): void
    {
        $this->rebase();
        $this->mvx = $vx;
        $this->mvy = $vy;
        $this->mvz = $vz;
        $this->edited();
    }

    /**
     * Turning from now on, as an angular velocity in the parent's space: the axis (x, y, z) scaled by the speed in
     * radians per second. spin(0, M_PI, 0) turns half a circle per second around the up axis. Exact for any axis.
     */
    public function spin(float $x, float $y = 0.0, float $z = 0.0): void
    {
        $this->rebase();
        $this->mwx = $x;
        $this->mwy = $y;
        $this->mwz = $z;
        $this->edited();
    }

    public bool $isMoving {
        get => $this->mvx != 0.0 || $this->mvy != 0.0 || $this->mvz != 0.0 || $this->mwx != 0.0 || $this->mwy != 0.0 || $this->mwz != 0.0;
    }

    private function age(): float
    {
        return ($this->clock?->time ?? 0.0) - $this->mt;
    }

    private function rebase(): void
    {
        $t = $this->clock?->time ?? 0.0;
        if ($t != $this->mt) {
            $dt = $t - $this->mt;
            $this->mx += $this->mvx * $dt;
            $this->my += $this->mvy * $dt;
            $this->mz += $this->mvz * $dt;
            if ($this->mwx != 0.0 || $this->mwy != 0.0 || $this->mwz != 0.0) {
                $this->orientationNow();   // still relative to the old start time here
                $this->mqx = self::$qx;
                $this->mqy = self::$qy;
                $this->mqz = self::$qz;
                $this->mqw = self::$qw;
            }
            $this->mt = $t;
        }
    }

    private function edited(): void
    {
        $this->dirty = true;
        $this->motionChanged();
        $this->physicsSpace?->changed($this);
        if ($this->published && $this->clock !== null) {
            $this->world()->descriptionChanged($this);
        }
    }

    /** @internal Whether changes of the description are streamed to viewers (see World::descriptionChanged()). */
    public bool $published = false;
    /** @internal Where its latest change waits in the world's publish queue, or -1. */
    public int $publishSlot = -1;

    /**
     * The motion description as sent to browsers: [t0, x, y, z, vx, vy, vz, qx, qy, qz, qw, wx, wy, wz]. t0 is when it
     * last changed (the moment of a collision, say), not now: a browser switches to it exactly then, and evaluates it
     * at any later time as the server does.
     *
     * @return list<float>
     */
    public function describe(): array
    {
        return [$this->mt, $this->mx, $this->my, $this->mz, $this->mvx, $this->mvy, $this->mvz, $this->mqx, $this->mqy, $this->mqz, $this->mqw, $this->mwx, $this->mwy, $this->mwz];
    }

    /** @internal Joins (or leaves) a world's clock without moving the node. */
    public function attachClock(?\Phame\Clock $clock): void
    {
        if ($clock === $this->clock) {
            return;
        }
        $this->rebase();
        $this->clock = $clock;
        $this->mt = $clock?->time ?? 0.0;
        $this->syncPhysics();
    }

    /** @internal Gives $node and its descendants the clock. */
    public static function attachClockTo(Node3D $node, ?\Phame\Clock $clock): void
    {
        $node->attachClock($clock);
        foreach ($node->children as $child) {
            self::attachClockTo($child, $clock);
        }
    }

    protected function world(): \Phame\World
    {
        $root = $this;
        while ($root->parent !== null) {
            $root = $root->parent;
        }
        return $root->scene?->stage?->world ?? \Phame\World::current();
    }

    public function kineticGroup(): ?\Phame\Kinetic\Group
    {
        return $this->parent;
    }

    public function kineticMembers(): array
    {
        return $this->children;
    }

    /** The node's own box, in its local space; a point for a plain node. Mesh uses its geometry. */
    protected function localBox(Vector3 $min, Vector3 $max): void
    {
        $min->set(0.0, 0.0, 0.0);
        $max->set(0.0, 0.0, 0.0);
    }

    public function kineticState(): void
    {
        $min = Vector3::get();
        $max = Vector3::get();
        $this->localBox($min, $max);
        $m = $this->getWorldMatrix();
        $x0 = $y0 = $z0 = INF;
        $x1 = $y1 = $z1 = -INF;
        for ($i = 0; $i < 8; ++$i) {   // the eight corners, transformed: the world-space box around them
            $px = $i & 1 ? $max->x : $min->x;
            $py = $i & 2 ? $max->y : $min->y;
            $pz = $i & 4 ? $max->z : $min->z;
            $wx = $m->m0 * $px + $m->m4 * $py + $m->m8 * $pz + $m->m12;
            $wy = $m->m1 * $px + $m->m5 * $py + $m->m9 * $pz + $m->m13;
            $wz = $m->m2 * $px + $m->m6 * $py + $m->m10 * $pz + $m->m14;
            $x0 = min($x0, $wx);
            $y0 = min($y0, $wy);
            $z0 = min($z0, $wz);
            $x1 = max($x1, $wx);
            $y1 = max($y1, $wy);
            $z1 = max($z1, $wz);
        }
        $min->release();
        $max->release();
        $cx = ($x0 + $x1) / 2;
        $cy = ($y0 + $y1) / 2;
        $cz = ($z0 + $z1) / 2;
        if ($this->mwx != 0.0 || $this->mwy != 0.0 || $this->mwz != 0.0) {   // spinning: a sphere around its own origin
            $cx = $m->m12;
            $cy = $m->m13;
            $cz = $m->m14;
            $r = sqrt(max(($x0 - $cx) ** 2, ($x1 - $cx) ** 2) + max(($y0 - $cy) ** 2, ($y1 - $cy) ** 2) + max(($z0 - $cz) ** 2, ($z1 - $cz) ** 2));
            [$x0, $y0, $z0, $x1, $y1, $z1] = [$cx - $r, $cy - $r, $cz - $r, $cx + $r, $cy + $r, $cz + $r];
        }
        // world velocity of the reference point: every ancestor's velocity plus every turning ancestor's omega x r;
        // arcs are followed along their tangent for a short window, with the box grown to hold them
        $vx = $vy = $vz = 0.0;
        $margin = 0.0;
        $horizon = INF;
        $window = \Phame\Kinetic\Kinetic::$arcHorizon;
        for ($n = $this; $n !== null; $n = $n->parent) {
            $p = $n->parent?->getWorldMatrix();
            if ($n->mvx != 0.0 || $n->mvy != 0.0 || $n->mvz != 0.0) {
                if ($p === null) {
                    $vx += $n->mvx;
                    $vy += $n->mvy;
                    $vz += $n->mvz;
                } else {
                    $vx += $p->m0 * $n->mvx + $p->m4 * $n->mvy + $p->m8 * $n->mvz;
                    $vy += $p->m1 * $n->mvx + $p->m5 * $n->mvy + $p->m9 * $n->mvz;
                    $vz += $p->m2 * $n->mvx + $p->m6 * $n->mvy + $p->m10 * $n->mvz;
                }
            }
            if ($n !== $this && ($n->mwx != 0.0 || $n->mwy != 0.0 || $n->mwz != 0.0)) {
                // the angular velocity, from the parent's space into the world
                [$wx, $wy, $wz] = $p === null ? [$n->mwx, $n->mwy, $n->mwz] : [
                    $p->m0 * $n->mwx + $p->m4 * $n->mwy + $p->m8 * $n->mwz,
                    $p->m1 * $n->mwx + $p->m5 * $n->mwy + $p->m9 * $n->mwz,
                    $p->m2 * $n->mwx + $p->m6 * $n->mwy + $p->m10 * $n->mwz,
                ];
                $o = $n->getWorldMatrix();
                $rx = $cx - $o->m12;
                $ry = $cy - $o->m13;
                $rz = $cz - $o->m14;
                $vx += $wy * $rz - $wz * $ry;
                $vy += $wz * $rx - $wx * $rz;
                $vz += $wx * $ry - $wy * $rx;
                $margin += \Phame\Kinetic\Kinetic::arcDeviation(sqrt($rx * $rx + $ry * $ry + $rz * $rz), sqrt($wx * $wx + $wy * $wy + $wz * $wz), $window);
                $horizon = $window;
            }
        }
        \Phame\Kinetic\Kinetic::$x0 = $x0 - $margin;
        \Phame\Kinetic\Kinetic::$y0 = $y0 - $margin;
        \Phame\Kinetic\Kinetic::$z0 = $z0 - $margin;
        \Phame\Kinetic\Kinetic::$x1 = $x1 + $margin;
        \Phame\Kinetic\Kinetic::$y1 = $y1 + $margin;
        \Phame\Kinetic\Kinetic::$z1 = $z1 + $margin;
        \Phame\Kinetic\Kinetic::$vx = $vx;
        \Phame\Kinetic\Kinetic::$vy = $vy;
        \Phame\Kinetic\Kinetic::$vz = $vz;
        \Phame\Kinetic\Kinetic::$horizon = $horizon;
    }

    public function kineticBounds(): void
    {
        $root = $this;
        while ($root->parent !== null) {
            $root = $root->parent;
        }
        $scene = $root->scene;
        \Phame\Kinetic\Kinetic::$minX = $scene?->worldMinX ?? -INF;
        \Phame\Kinetic\Kinetic::$minY = $scene?->worldMinY ?? -INF;
        \Phame\Kinetic\Kinetic::$minZ = $scene?->worldMinZ ?? -INF;
        \Phame\Kinetic\Kinetic::$maxX = $scene?->worldMaxX ?? INF;
        \Phame\Kinetic\Kinetic::$maxY = $scene?->worldMaxY ?? INF;
        \Phame\Kinetic\Kinetic::$maxZ = $scene?->worldMaxZ ?? INF;
    }
    public float $scaleX = 1.0 { set { $this->scaleX = $value; $this->dirty = true; } }
    public float $scaleY = 1.0 { set { $this->scaleY = $value; $this->dirty = true; } }
    public float $scaleZ = 1.0 { set { $this->scaleZ = $value; $this->dirty = true; } }

    public bool $visible = true;
    public string $name = '';

    public private(set) ?Node3D $parent = null;
    /** @var list<Node3D> */
    public private(set) array $children = [];

    private ?Matrix4 $local = null;
    private ?Matrix4 $world = null;
    private bool $dirty = true;

    public function __destruct()
    {
        $this->local?->release();
        $this->world?->release();
        parent::__destruct();
    }

    /** Sets all three scale factors. */
    public function setScale(float $s): void
    {
        $this->scaleX = $this->scaleY = $this->scaleZ = $s;
    }

    public function setPosition(float $x, float $y, float $z): void
    {
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }

    /** The transform relative to the parent. The matrix belongs to the node: copy it if you need to keep it. */
    public function getLocalMatrix(): Matrix4
    {
        $this->local ??= Matrix4::get();
        if (!$this->dirty && $this->isMoving && ($this->clock?->time ?? 0.0) !== $this->localTime) {
            $this->dirty = true;   // moving: the transform is a function of time
        }
        if ($this->dirty) {
            $this->localTime = $this->clock?->time ?? 0.0;
            $this->orientationNow();
            $this->local->composeQuaternion($this->x, $this->y, $this->z, self::$qx, self::$qy, self::$qz, self::$qw, $this->scaleX, $this->scaleY, $this->scaleZ);
            $this->dirty = false;
        }
        return $this->local;
    }

    /** The transform from this node's space to the root of its tree, recomputed on every call. The matrix belongs to the node: copy it if you need to keep it. */
    public function getWorldMatrix(): Matrix4
    {
        $this->world ??= Matrix4::get();
        $local = $this->getLocalMatrix();
        return $this->parent === null ? $this->world->copyFrom($local) : $this->world->product($this->parent->getWorldMatrix(), $local);
    }

    /** Turns the node (rotationX and rotationY; roll is reset) so that its -z axis points at the target, given in the parent's space. */
    /**
     * Sets $out to the direction the node faces (its -z axis) in world space, unit length. For a camera that is
     * where it looks. Zero the y and normalize for movement along the ground:
     * `$camera->forward($f); $f->y = 0; $f->normalize();`
     */
    public function forward(Vector3 $out): Vector3
    {
        $m = $this->getWorldMatrix();
        return $out->set(-$m->m8, -$m->m9, -$m->m10)->normalize();
    }

    /** Sets $out to the node's right (+x axis) in world space, unit length. */
    public function right(Vector3 $out): Vector3
    {
        $m = $this->getWorldMatrix();
        return $out->set($m->m0, $m->m1, $m->m2)->normalize();
    }

    /** Sets $out to the node's up (+y axis) in world space, unit length. */
    public function up(Vector3 $out): Vector3
    {
        $m = $this->getWorldMatrix();
        return $out->set($m->m4, $m->m5, $m->m6)->normalize();
    }

    public function lookAt(float $x, float $y, float $z): void
    {
        $dx = $x - $this->x;
        $dy = $y - $this->y;
        $dz = $z - $this->z;
        $len = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        if ($len == 0.0) {
            return;
        }
        $this->rotationX = asin($dy / $len);
        $this->rotationY = atan2(-$dx, -$dz);
        $this->rotationZ = 0.0;
    }

    public function addChild(Node3D $child): Node3D
    {
        for ($n = $this; $n !== null; $n = $n->parent) {
            if ($n === $child) {
                throw new \LogicException('A node cannot be added to itself or to one of its descendants');
            }
        }
        $child->removeFromParent();
        $child->parent = $this;
        $this->children[] = $child;
        if ($this->clock !== null) {
            self::attachClockTo($child, $this->clock);
        }
        if ($child->watchedBelow > 0 || $this->dependents > 0) {
            $child->joinedGroup($this);
        }
        if ($this->dependents > 0) {
            $child->motionChanged();   // a new member: waiters on this group check it
        }
        return $child;
    }

    /** The first descendant with this name, searching depth first, or null. */
    public function findByName(string $name): ?Node3D
    {
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
            if (($found = $child->findByName($name)) !== null) {
                return $found;
            }
        }
        return null;
    }

    public function removeChild(Node3D $child): void
    {
        $i = array_search($child, $this->children, true);
        if ($i !== false) {
            if ($child->watchedBelow > 0) {
                $child->leftGroup($this);
            }
            array_splice($this->children, $i, 1);
            $child->parent = null;
            $child->syncPhysics();
            if ($this->dependents > 0) {
                $this->world()->memberRemoved($this, $child);
            }
            $event = null;
            self::leaveParent($child, $event);
        }
    }

    public function removeFromParent(): void
    {
        $this->parent?->removeChild($this);
    }

    /** Dispatches the event on $node and on all its descendants. */
    /** @internal Ends the coroutines of the node and its descendants and tells listeners (the event is made on demand). */
    public static function leaveParent(Node3D $node, ?\Phame\Events\Event &$event): void
    {
        $node->cancelCoroutines();
        if ($node->hasEventListener(\Phame\Events\Event::REMOVED_FROM_STAGE)) {
            $event ??= new \Phame\Events\Event(\Phame\Events\Event::REMOVED_FROM_STAGE);
            $event->target = null;
            $node->dispatchEvent($event);
        }
        foreach ($node->children as $child) {
            self::leaveParent($child, $event);
        }
    }

    public static function broadcast(Node3D $node, \Phame\Events\Event $event): void
    {
        $event->target = null;
        $node->dispatchEvent($event);
        foreach ($node->children as $child) {
            self::broadcast($child, $event);
        }
    }
}
