<?php

namespace PhameServer;

use Phame\Physics\Contact;
use Phame\Scene\Node3D;
use Phame\Wake\Wake;
use Phame\World;

/**
 * A ragdoll as a tree: this invisible root carries the flight (a straight line and a tumble), hinge joints turn under
 * it at their own rates, and limbs sit still on the joints. While nothing hits it, a tumbling, flailing ragdoll is a
 * handful of motion descriptions and no events at all; a joint reaching its limit and a rock hitting a limb are the
 * only things that happen. Physics\Space sees it as one compound body.
 */
final class Ragdoll extends Node3D
{
    public const TYPES = ['dummy', 'spider', 'rope', 'starfish'];

    /** @var list<Joint> */
    public private(set) array $joints = [];
    /** @var list<Limb> */
    public private(set) array $limbs = [];
    /** @var array<int, float> by joint: the mass of the limbs that turn with it */
    private array $below = [];
    private float $inertia = 1.0;
    /** How much of the throw's tumble it takes: a long rope swung like a baton would whip its end around. */
    private float $tumble = 1.0;
    public bool $gone = false;

    public function __construct(public readonly Observer $by, public readonly string $type)
    {
        $this->compound = true;
        $this->confined = false;
        match ($type) {
            'dummy' => $this->dummy(),
            'spider' => $this->spider(),
            'rope' => $this->rope(),
            default => $this->starfish(),
        };
        // mass, an inertia about the root (the rest pose, as a cloud of spheres), and a radius that holds every pose
        $this->mass = 0.0;
        $this->inertia = 0.0;
        $reach = 0.0;
        foreach ($this->limbs as $limb) {
            [$x, $y, $z, $chain] = self::restPosition($limb);
            $this->mass += $limb->mass;
            $this->inertia += $limb->mass * ((2 / 3) * ($x * $x + $y * $y + $z * $z) + 0.4 * $limb->radius ** 2);
            $reach = max($reach, $chain + $limb->radius);
        }
        $this->radius = $reach;
        foreach ($this->joints as $joint) {
            $this->below[spl_object_id($joint)] = self::massBelow($joint);
        }
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
            $joint = $this->joint($parent, 0.0, 0.0, $at, $i % 2 ? [0, 1, 0] : [1, 0, 0], -1.4, 1.4, 0.4, 1.0);
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

    private function joint(Node3D $parent, float $x, float $y, float $z, array $axis, float $min, float $max, float $bounce = 0.0, float $flail = 3.0): Joint
    {
        $joint = new Joint(...array_map('floatval', $axis), min: $min, max: $max, bounce: $bounce, flail: $flail);
        $joint->moveTo($x, $y, $z);
        $parent->addChild($joint);
        $this->joints[] = $joint;
        return $joint;
    }

    private function limb(Node3D $parent, float $x, float $y, float $z, float $radius): Limb
    {
        $limb = new Limb($this, $radius);
        $limb->moveTo($x, $y, $z);
        $parent->addChild($limb);
        $this->limbs[] = $limb;
        return $limb;
    }


    /** Where a limb sits in the rest pose, relative to the root, and the length of its chain of offsets from it. */
    private static function restPosition(Node3D $node): array
    {
        [$x, $y, $z, $chain] = [0.0, 0.0, 0.0, 0.0];
        for ($n = $node; !($n instanceof self); $n = $n->parent) {
            [$x, $y, $z] = [$x + $n->x, $y + $n->y, $z + $n->z];
            $chain += sqrt($n->x ** 2 + $n->y ** 2 + $n->z ** 2);
        }
        return [$x, $y, $z, $chain];
    }

    private static function massBelow(Node3D $node): float
    {
        $mass = $node instanceof Limb ? $node->mass : 0.0;
        foreach ($node->children as $child) {
            $mass += self::massBelow($child);
        }
        return $mass;
    }

    // ---------- in the world

    /** Throws it: from $x, $y, $z at velocity $v, tumbling at $spin, its joints set flailing. */
    public function launch(World $world, array $at, array $v, array $spin): void
    {
        $this->moveTo(...$at);
        $this->setVelocity(...$v);
        $this->spin(...array_map(fn (float $c) => $c * $this->tumble, $spin));
        foreach ($this->joints as $joint) {
            $joint->setRate(mt_rand(-100, 100) / 100 * $joint->flail);
            // each joint waits for its limit (an exact time: its angle is a straight line too), stops there, and
            // starts over whenever a hit changes its rate
            $world->go(function () use ($joint) {
                while (!$this->gone) {
                    $woken = $joint->wait(min($joint->untilLimit(), 60.0), Wake::woken());
                    if ($this->gone) {
                        return;
                    }
                    if ($woken === null && $joint->untilLimit() <= 1e-9) {
                        $joint->reachLimit();
                    }
                }
            });
        }
    }

    /**
     * A limb hit a ball (or the ball hit the limb). The impulse at the contact point is shared by the flight, the
     * tumble (its torque about the root) and every joint between the limb and the root (its torque about its hinge),
     * in proportion to how easily each gives way: the effective mass at the point decides how hard the ball bounces.
     */
    public function hit(Limb $limb, Ball $ball, Contact $c): void
    {
        [$nx, $ny, $nz] = [$c->nx, $c->ny, $c->nz];   // from the limb towards the ball
        $m = $limb->getWorldMatrix();
        [$px, $py, $pz] = [$m->m12, $m->m13, $m->m14];
        [$rx, $ry, $rz] = [$px - $this->x, $py - $this->y, $pz - $this->z];
        [$tx, $ty, $tz] = [$ry * $nz - $rz * $ny, $rz * $nx - $rx * $nz, $rx * $ny - $ry * $nx];   // r x n
        $inverse = 1.0 / $this->mass + ($tx * $tx + $ty * $ty + $tz * $tz) / $this->inertia;
        // every joint between the limb and the root gives way, each by its torque about its hinge and the mass that
        // turns with it: a hit near the end of a rope curls the length of it
        $giving = [];
        for ($joint = $limb->parent; $joint instanceof Joint; $joint = $joint->parent) {
            $j = $joint->getWorldMatrix();
            [$lx, $ly, $lz] = [$px - $j->m12, $py - $j->m13, $pz - $j->m14];
            $p = $joint->parent->getWorldMatrix();   // the hinge axis turns with the joint's parent
            [$wx, $wy, $wz] = [
                $p->m0 * $joint->ax + $p->m4 * $joint->ay + $p->m8 * $joint->az,
                $p->m1 * $joint->ax + $p->m5 * $joint->ay + $p->m9 * $joint->az,
                $p->m2 * $joint->ax + $p->m6 * $joint->ay + $p->m10 * $joint->az,
            ];
            $arm = ($ly * $nz - $lz * $ny) * $wx + ($lz * $nx - $lx * $nz) * $wy + ($lx * $ny - $ly * $nx) * $wz;   // (l x n) . axis
            $angle = $joint->angleNow();
            if (($angle >= $joint->max && $arm < 0.0) || ($angle <= $joint->min && $arm > 0.0)) {
                continue;   // pushed against its limit: it holds
            }
            $jointInertia = $this->below[spl_object_id($joint)] * max(0.5, $lx * $lx + $ly * $ly + $lz * $lz);
            $inverse += $arm * $arm / $jointInertia;
            $giving[] = [$joint, $arm, $jointInertia];
        }
        $impulse = 1.5 * -$c->normalSpeed / (1.0 / $ball->mass + $inverse);   // restitution 0.5: plastic gives a little
        $k = $impulse / $ball->mass;
        $ball->setVelocity($ball->velocityX + $k * $nx, $ball->velocityY + $k * $ny, $ball->velocityZ + $k * $nz);
        $k = $impulse / $this->mass;
        $this->setVelocity($this->velocityX - $k * $nx, $this->velocityY - $k * $ny, $this->velocityZ - $k * $nz);
        $k = $impulse / $this->inertia;
        $this->spin($this->mwx - $k * $tx, $this->mwy - $k * $ty, $this->mwz - $k * $tz);
        foreach ($giving as [$joint, $arm, $jointInertia]) {
            $turn = $impulse * $arm / $jointInertia;
            if (abs($turn) < 0.15) {
                continue;   // far up a long chain the share is next to nothing: not worth waking the joint for
            }
            $joint->setRate($joint->rate - $turn);
            $this->world()->wake($joint);
        }
    }

    /**
     * Gone: the joints stop waiting (their coroutines end), and the tree is taken apart, so nothing of it is left
     * for the cycle collector (see Node3D::dispose()).
     */
    public function dispose(): void
    {
        $this->gone = true;
        foreach ($this->joints as $joint) {
            $this->world()->wake($joint);
        }
        [$this->joints, $this->limbs, $this->below] = [[], [], []];
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
