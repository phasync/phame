<?php

namespace PhameServer;

use Phame\Physics\Body;
use Phame\Physics\Contact;
use Phame\Physics\JointType;
use Phame\Scene\Geometry;
use Phame\Scene\Material;
use Phame\Scene\Mesh;
use Phame\Scene\Node3D;
use Phame\World;

/**
 * A ragdoll as a tree: this invisible root carries the flight (a straight line and a tumble), engine Hinge joints
 * turn under it at their own rates, and limbs sit still on their joints. While nothing hits it, a tumbling,
 * flailing ragdoll is a handful of motion descriptions and no events at all; a joint reaching its limit and a rock
 * hitting a limb are the only things that happen - the joints' own limit events and a hit's momentum are both
 * Physics\Body's job now (see docs/PHYSICS-PLAN.md's Phase 2), not this class's own.
 */
final class Ragdoll extends Node3D
{
    public const TYPES = ['dummy', 'spider', 'rope', 'starfish'];

    private static ?Geometry $bone = null;
    private static ?Material $boneMaterial = null;

    /** @var list<Node3D> every Hinge joint, for launch()'s initial flail. */
    private array $joints = [];
    /** @var list<Limb> */
    public private(set) array $limbs = [];
    /** How much of the throw's tumble it takes: a long rope swung like a baton would whip its end around. */
    private float $tumble = 1.0;
    public bool $gone = false;

    public function __construct(public readonly Observer $by, public readonly string $type)
    {
        $this->confined = false;
        match ($type) {
            'dummy' => $this->dummy(),
            'spider' => $this->spider(),
            'rope' => $this->rope(),
            default => $this->starfish(),
        };
        new Body($this);   // mass, inertia tensor, the super sphere (Node3D::$radius) - all from this tree
    }

    // ---------- the types: built from joints (pivot, axis, limits) and limbs (offset on their joint, radius)

    private function dummy(): void
    {
        $pelvis = $this->limb($this, 0.0, 0.0, 0.0, 1.3);
        $spine = $this->joint($this, 0.0, 0.9, 0.0, [1, 0, 0], -0.4, 0.9);
        $chest = $this->limb($spine, 0.0, 1.3, 0.0, 1.5);
        $neck = $this->joint($spine, 0.0, 2.6, 0.0, [1, 0, 0], -0.5, 0.5);
        $head = $this->limb($neck, 0.0, 1.0, 0.0, 1.05);
        foreach ([-1, 1] as $side) {
            $shoulder = $this->joint($spine, 1.5 * $side, 2.0, 0.0, [0, 0, 1], $side < 0 ? -1.6 : -1.2, $side < 0 ? 1.2 : 1.6);
            $upper = $this->limb($shoulder, 1.1 * $side, 0.0, 0.0, 0.7);
            $elbow = $this->joint($shoulder, 2.2 * $side, 0.0, 0.0, [0, 1, 0], $side < 0 ? 0.0 : -2.2, $side < 0 ? 2.2 : 0.0);
            $fore = $this->limb($elbow, 1.0 * $side, 0.0, 0.0, 0.6);
            $hip = $this->joint($this, 0.8 * $side, -0.6, 0.0, [1, 0, 0], -1.4, 0.6);
            $thigh = $this->limb($hip, 0.0, -1.3, 0.0, 0.85);
            $knee = $this->joint($hip, 0.0, -2.6, 0.0, [1, 0, 0], 0.0, 2.0);
            $shin = $this->limb($knee, 0.0, -1.2, 0.0, 0.75);
        }
    }

    private function spider(): void
    {
        $body = $this->limb($this, 0.0, 0.0, 0.0, 1.6);
        for ($k = 0; $k < 8; ++$k) {
            $a = ($k + 0.5) * M_PI / 4;
            [$c, $s] = [cos($a), sin($a)];
            $hip = $this->joint($this, 1.4 * $c, 0.0, 1.4 * $s, [-$s, 0, $c], -0.6, 0.9);
            $femur = $this->limb($hip, 1.2 * $c, 0.6, 1.2 * $s, 0.45);
            $knee = $this->joint($hip, 2.4 * $c, 1.2, 2.4 * $s, [-$s, 0, $c], -1.8, -0.2);
            $tibia = $this->limb($knee, 0.8 * $c, -1.3, 0.8 * $s, 0.4);
        }
    }

    private function rope(): void
    {
        // many short segments, joints that bend far either way (sideways and up in turn) and swing back off their
        // limits: a hit anywhere curls the rest of it
        $this->tumble = 0.3;
        $previous = $this->limb($this, 0.0, 0.0, 0.0, 0.8);
        $parent = $this;
        $at = -0.6;
        for ($i = 1; $i < 11; ++$i) {
            $joint = $this->joint($parent, 0.0, 0.0, $at, $i % 2 ? [0, 1, 0] : [1, 0, 0], -1.4, 1.4, 0.4);
            $segment = $this->limb($joint, 0.0, 0.0, -0.6, 0.75 - 0.025 * $i);
            [$parent, $previous, $at] = [$joint, $segment, -1.2];
        }
    }

    private function starfish(): void
    {
        $centre = $this->limb($this, 0.0, 0.0, 0.0, 1.4);
        for ($k = 0; $k < 5; ++$k) {
            $a = $k * 2 * M_PI / 5;
            [$c, $s] = [cos($a), sin($a)];
            [$parent, $previous, $at] = [$this, $centre, 1.2];
            for ($i = 0; $i < 3; ++$i) {
                $joint = $this->joint($parent, $at * $c, $at * $s, 0.0, [-$s, $c, 0], -0.9, 0.9);
                $segment = $this->limb($joint, 0.9 * $c, 0.9 * $s, 0.0, 0.8 - 0.15 * $i);
                [$parent, $previous, $at] = [$joint, $segment, 1.8];
            }
        }
    }

    /** An invisible Hinge (Physics\JointType) at ($x, $y, $z) in $parent's space, turning about $axis. $friction
     * (rad/s²) is real joint stiffness - a bare hinge with none would keep whatever speed a hit or a limit's own
     * bounce gave it forever, which no real joint does. */
    private function joint(Node3D $parent, float $x, float $y, float $z, array $axis, float $min, float $max, float $bounce = 0.0, float $friction = 2.0): Node3D
    {
        $joint = new Node3D();
        $joint->jointType = JointType::Hinge;
        [$joint->jointAxisX, $joint->jointAxisY, $joint->jointAxisZ] = array_map('floatval', $axis);
        $joint->jointMin = $min;
        $joint->jointMax = $max;
        $joint->jointBounce = $bounce;
        $joint->jointFriction = $friction;
        $joint->moveTo($x, $y, $z);
        $parent->addChild($joint);
        $this->joints[] = $joint;
        return $joint;
    }

    /** A limb on $parent (a joint, or this root for one with no joint of its own), with a bone between them: a
     * thin cylinder from the joint's own pivot to the limb's centre - a fixed local shape (the limb never moves
     * relative to its own joint), so unlike a bone spanning two independently moving joints, this one costs
     * nothing to keep right: built once, it turns with the joint like the limb itself does. */
    private function limb(Node3D $parent, float $x, float $y, float $z, float $radius): Limb
    {
        $limb = new Limb($this, $radius);
        $limb->moveTo($x, $y, $z);
        $parent->addChild($limb);
        $this->limbs[] = $limb;
        if ($parent !== $this) {
            $parent->addChild($this->bone($x, $y, $z));
        }
        return $limb;
    }

    /** A thin cylinder from (0, 0, 0) to ($x, $y, $z), in whichever space it is added to. */
    private function bone(float $x, float $y, float $z): Mesh
    {
        $length = sqrt($x * $x + $y * $y + $z * $z) ?: 1.0;
        $bone = new Mesh(self::$bone ??= Geometry::cylinder(0.5, 1.0), self::$boneMaterial ??= new Material(color: 0x3a3632));
        [$qx, $qy, $qz, $qw] = self::rotationFromUpTo($x / $length, $y / $length, $z / $length);
        $bone->setOrientation($qx, $qy, $qz, $qw);
        $bone->setScale(0.12, $length, 0.12);
        return $bone;
    }

    /** The quaternion turning (0, 1, 0) to the unit vector ($x, $y, $z). */
    private static function rotationFromUpTo(float $x, float $y, float $z): array
    {
        if ($y > 0.999999) {
            return [0.0, 0.0, 0.0, 1.0];
        }
        if ($y < -0.999999) {
            return [1.0, 0.0, 0.0, 0.0];   // 180 degrees about x
        }
        // axis = (0,1,0) x (x,y,z), angle = acos(y); the half-angle sine/cosine form avoids the acos/sin round trip
        [$ax, $ay, $az] = [-$z, 0.0, $x];
        $s = sqrt((1.0 - $y) / 2.0);
        $c = sqrt((1.0 + $y) / 2.0);
        $n = sqrt($ax * $ax + $az * $az) ?: 1.0;
        return [$ax / $n * $s, $ay * $s, $az / $n * $s, $c];
    }

    // ---------- in the world

    /** Throws it: from $x, $y, $z at velocity $v, tumbling at $spin, its joints set flailing. */
    public function launch(World $world, array $at, array $v, array $spin): void
    {
        $this->moveTo(...$at);
        $this->setVelocity(...$v);
        $this->spin(...array_map(fn (float $c) => $c * $this->tumble, $spin));
        foreach ($this->joints as $joint) {
            $joint->setJointRate(mt_rand(-100, 100) / 100 * 3.0);   // each waits for its own limit - Event::EXPIRE, not a coroutine
        }
    }

    /**
     * A limb hit a ball (or the ball hit the limb): the impulse at the limb's centre is shared by the body (its
     * flight, its tumble, and every joint between the limb and the root) through Physics\Body's own exact
     * articulated-mass-matrix solve - see Body::effectiveInverseMass() and Body::applyImpulse().
     */
    public function hit(Limb $limb, Ball $ball, Contact $c): void
    {
        [$nx, $ny, $nz] = [$c->nx, $c->ny, $c->nz];   // from the limb towards the ball
        $inverse = $this->body->effectiveInverseMass($limb, $nx, $ny, $nz);
        $impulseOnBall = 1.5 * -$c->normalSpeed / (1.0 / $ball->mass + $inverse);   // restitution 0.5: plastic gives a little
        $k = $impulseOnBall / $ball->mass;
        $ball->setVelocity($ball->velocityX + $k * $nx, $ball->velocityY + $k * $ny, $ball->velocityZ + $k * $nz);
        $this->body->applyImpulse($limb, $nx, $ny, $nz, -$impulseOnBall);
    }

    /**
     * Gone: the tree is taken apart (its joints' own Event::EXPIRE listeners go with their nodes), so nothing of it
     * is left for the cycle collector (see Node3D::dispose()).
     */
    public function dispose(): void
    {
        $this->gone = true;
        [$this->joints, $this->limbs] = [[], []];
        parent::dispose();
    }

    /** Every node of the tree, root first: what viewers are told about (bodies), and how it hangs together. */
    public function nodes(): array
    {
        $out = [];
        $walk = static function (Node3D $node) use (&$walk, &$out) {
            $out[] = $node;
            foreach ($node->children as $child) {
                $walk($child);
            }
        };
        $walk($this);
        return $out;
    }
}
