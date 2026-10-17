<?php

namespace Phame\Physics;

use Phame\Scene\Node3D;

/**
 * Mass and momentum of a tree of jointed parts, owned by its root (Node3D::$body) - what used to be a game's own
 * hand-set $mass, $inertia and Node3D::$compound (Ragdoll's own fields, before this) is now one engine concept any
 * game attaches to a compound's root.
 *
 * Generalized coordinates: the root's own linear and angular velocity (6) plus every Hinge/Ball/Slider's own rate
 * (one each), in a fixed order chosen once at construction. A part's world velocity - and so its contribution to
 * the body's mass matrix M(q) - is linear in these, through a Jacobian built fresh from the CURRENT pose each time
 * (parallel-axis terms at the part's actual position, not a rest-pose approximation): M(q) = Σ_parts m·Jv^T·Jv +
 * I_self·Jw^T·Jw, Jv/Jw being a part's linear/angular velocity per unit of each generalized velocity. An impulse at
 * a point is then an ordinary constrained-rigid-body impulse, Δq̇ = M⁻¹ Jₙᵀ λ with λ sized by the same effective
 * mass 1 / (Jₙ M⁻¹ Jₙᵀ) any two-body contact uses (see applyImpulse()/effectiveInverseMass()) - momentum- and
 * energy-consistent by construction, not by a clamp afterwards: a dense N x N solve (N ~ 10-20 for a ragdoll) is
 * simpler than Featherstone's O(N) articulated-body algorithm and plenty fast enough at hit-time, not per tick.
 * A joint reaching a limit is the same solve, aimed at one joint's own rate (see jointLimitImpulse()) instead of a
 * world-space contact normal.
 */
final class Body
{
    /** @var list<Node3D> every part (radius > 0) under the root, not the root itself. */
    private array $parts = [];
    /** @var list<Node3D> every Hinge/Ball/Slider under the root, in the fixed order its own generalized coordinate
     * takes (index 6 + i in a state vector, after the root's own 3 linear + 3 angular). */
    private array $joints = [];
    /** @var array<int, int> by joint (spl_object_id) -> its index into $joints. */
    private array $jointIndex = [];
    /** M⁻¹ at the pose last built it, and the game time it was built at: positions only ever change between game
     * time ticks (never within the synchronous span of one hit or one limit event), so a repeat call at the same
     * time - effectiveInverseMass() immediately followed by applyImpulse(), say - reuses it instead of inverting
     * the same matrix twice. */
    private ?array $cachedMinv = null;
    private float $cachedAt = -INF;

    public function __construct(public readonly Node3D $root)
    {
        $root->compound = true;
        $root->body = $this;
        $reach = 0.0;
        foreach (self::descendants($root) as $node) {
            $node->body = $this;
            if ($node->radius > 0.0) {
                [$x, $y, $z, $chain] = self::restOffset($root, $node);
                $reach = max($reach, $chain + $node->radius);
                $this->parts[] = $node;
            }
            if ($node->jointType === JointType::Hinge || $node->jointType === JointType::Ball || $node->jointType === JointType::Slider) {
                $this->jointIndex[spl_object_id($node)] = count($this->joints);
                $this->joints[] = $node;
            }
        }
        $root->radius = $reach;   // a bounding sphere around the root, covering every part at every pose the joints allow
    }

    /** 6 (the root's own linear + angular velocity) plus one per Hinge/Ball/Slider. */
    private function dof(): int
    {
        return 6 + count($this->joints);
    }

    /** The mass-weighted centre of every part, now, in the scene's space - a convenience for callers (tests,
     * mostly) that want it; Body's own solve never needs to relocate the root there. */
    public function centerOfMass(): array
    {
        [$cx, $cy, $cz, $mass] = [0.0, 0.0, 0.0, 0.0];
        foreach ($this->parts as $part) {
            $m = $part->getWorldMatrix();
            $cx += $part->mass * $m->m12;
            $cy += $part->mass * $m->m13;
            $cz += $part->mass * $m->m14;
            $mass += $part->mass;
        }
        $mass = $mass ?: 1.0;
        return [$cx / $mass, $cy / $mass, $cz / $mass];
    }

    /** The world axis of a Hinge/Slider/Ball joint now: its own local axis, turned by its parent's current rotation. */
    private function worldAxis(Node3D $joint): array
    {
        $p = $joint->parent->getWorldMatrix();
        return [
            $p->m0 * $joint->jointAxisX + $p->m4 * $joint->jointAxisY + $p->m8 * $joint->jointAxisZ,
            $p->m1 * $joint->jointAxisX + $p->m5 * $joint->jointAxisY + $p->m9 * $joint->jointAxisZ,
            $p->m2 * $joint->jointAxisX + $p->m6 * $joint->jointAxisY + $p->m10 * $joint->jointAxisZ,
        ];
    }

    /**
     * $part's world linear and angular velocity, each as a function of the generalized velocity, now: one 3-vector
     * per coordinate (so Jv/Jw are "N columns of 3", not a literal matrix type) - columns 0-2 the root's own
     * velocity, 3-5 its angular velocity (world frame, since the root's parent is the scene itself), and 6+i a
     * joint's own rate, zero unless that joint is actually between $part and the root.
     *
     * @return array{list<array{float,float,float}>, list<array{float,float,float}>}
     */
    private function jacobians(Node3D $part): array
    {
        $n = $this->dof();
        $rm = $this->root->getWorldMatrix();
        $pm = $part->getWorldMatrix();
        [$rx, $ry, $rz] = [$pm->m12 - $rm->m12, $pm->m13 - $rm->m13, $pm->m14 - $rm->m14];
        $jv = array_fill(0, $n, [0.0, 0.0, 0.0]);
        $jw = array_fill(0, $n, [0.0, 0.0, 0.0]);
        $jv[0] = [1.0, 0.0, 0.0];
        $jv[1] = [0.0, 1.0, 0.0];
        $jv[2] = [0.0, 0.0, 1.0];
        $jv[3] = [0.0, -$rz, $ry];
        $jv[4] = [$rz, 0.0, -$rx];
        $jv[5] = [-$ry, $rx, 0.0];
        $jw[3] = [1.0, 0.0, 0.0];
        $jw[4] = [0.0, 1.0, 0.0];
        $jw[5] = [0.0, 0.0, 1.0];
        for ($n2 = $part->parent; $n2 !== null && $n2 !== $this->root; $n2 = $n2->parent) {
            $i = $this->jointIndex[spl_object_id($n2)] ?? null;
            if ($i === null) {
                continue;   // Fixed, or some other structural ancestor: no DOF of its own
            }
            [$ax, $ay, $az] = $this->worldAxis($n2);
            $jm = $n2->getWorldMatrix();
            [$lx, $ly, $lz] = [$pm->m12 - $jm->m12, $pm->m13 - $jm->m13, $pm->m14 - $jm->m14];
            $jv[6 + $i] = [$ay * $lz - $az * $ly, $az * $lx - $ax * $lz, $ax * $ly - $ay * $lx];   // axis x (part - joint)
            $jw[6 + $i] = [$ax, $ay, $az];
        }
        return [$jv, $jw];
    }

    /** The body's mass matrix M(q) at the current pose: M = Σ_parts m·Jvᵀ·Jv + I_self·Jwᵀ·Jw. */
    private function massMatrix(): array
    {
        $n = $this->dof();
        $m = array_fill(0, $n, array_fill(0, $n, 0.0));
        foreach ($this->parts as $part) {
            [$jv, $jw] = $this->jacobians($part);
            $mass = $part->mass;
            $self = 0.4 * $mass * $part->radius ** 2;
            for ($i = 0; $i < $n; ++$i) {
                if ($jv[$i] === [0.0, 0.0, 0.0] && $jw[$i] === [0.0, 0.0, 0.0]) {
                    continue;
                }
                for ($j = $i; $j < $n; ++$j) {
                    $v = $mass * ($jv[$i][0] * $jv[$j][0] + $jv[$i][1] * $jv[$j][1] + $jv[$i][2] * $jv[$j][2])
                        + $self * ($jw[$i][0] * $jw[$j][0] + $jw[$i][1] * $jw[$j][1] + $jw[$i][2] * $jw[$j][2]);
                    $m[$i][$j] += $v;
                    if ($i !== $j) {
                        $m[$j][$i] += $v;
                    }
                }
            }
        }
        // a small Tikhonov regularization: a random pose can bring two joints' axes and positions close enough to
        // near-dependent columns that Gauss-Jordan elimination amplifies rounding error past what a sub-1
        // restitution hit should ever cost in energy; this trades a negligible, uniform bias for that not
        // happening, far below the scale of the masses and radii actually in play. jointLimitImpulse() additionally
        // checks the one invariant that matters (kinetic energy cannot rise) and falls back to an uncoupled bounce
        // whenever even this isn't enough - a many-jointed chain folded on itself (a rope ragdoll) can still bring
        // M's smallest eigenvalue far closer to 0 than this constant, at which point M⁻¹ cannot be trusted at all.
        for ($i = 0; $i < $n; ++$i) {
            $m[$i][$i] += 1e-9;
        }
        return $m;
    }

    /** M⁻¹ at the current pose, from the cache if nothing has had a chance to move since it was built. */
    private function inverseMassMatrix(): array
    {
        $now = $this->root->clock?->time ?? 0.0;
        if ($this->cachedMinv === null || $now !== $this->cachedAt) {
            $this->cachedMinv = self::invert($this->massMatrix());
            $this->cachedAt = $now;
        }
        return $this->cachedMinv;
    }

    /** $jₙ · M⁻¹ · $jₙᵀ for a generalized-coordinate row $jn (length dof()): the quadratic form both
     * effectiveInverseMass() and applyImpulse() need, computed once from the same $minv either already has. */
    private static function quadraticForm(array $minv, array $jn): float
    {
        $n = count($jn);
        $sum = 0.0;
        for ($i = 0; $i < $n; ++$i) {
            $s = 0.0;
            for ($k = 0; $k < $n; ++$k) {
                $s += $minv[$i][$k] * $jn[$k];
            }
            $sum += $jn[$i] * $s;
        }
        return $sum;
    }

    /**
     * 1/effective-mass of $part along ($nx, $ny, $nz): what a contact handler divides a closing speed by to get
     * the impulse (`$impulse = -(1+e) * $normalSpeed / (1/$other->mass + $body->effectiveInverseMass(...))`, the
     * same shape as any two-body collision, with the whole articulated body's own give folded into one number).
     * Pass the same $impulse (negated: this body receives the opposite of what the other side gets) to
     * applyImpulse().
     */
    public function effectiveInverseMass(Node3D $part, float $nx, float $ny, float $nz): float
    {
        [$jv] = $this->jacobians($part);
        $jn = array_map(static fn (array $v) => $nx * $v[0] + $ny * $v[1] + $nz * $v[2], $jv);
        return self::quadraticForm($this->inverseMassMatrix(), $jn);
    }

    /** The current generalized velocity: the root's own linear and angular velocity, then one per joint, in the
     * same fixed order the mass matrix's rows/columns use. */
    private function qdot(): array
    {
        $root = $this->root;
        $q = [$root->velocityX, $root->velocityY, $root->velocityZ, $root->mwx, $root->mwy, $root->mwz];
        foreach ($this->joints as $joint) {
            $q[] = $joint->jointRate;
        }
        return $q;
    }

    /**
     * Total kinetic energy now, ½ q̇ᵀ M q̇ from the mass matrix and the generalized velocity as the tree itself
     * reports it (the root's velocity/spin, every joint's own rate). This must equal the same total measured
     * directly from every part's own velocity (Σ ½m|v|² + ½I|ω|²) - a test asserts that identity permanently,
     * since any disagreement means a Δq̇ was written back inconsistently with the M it was solved in (a rate
     * applied in the wrong space, a clamp silently changing it, and so on), not that the solve itself is wrong.
     */
    public function kineticEnergy(): float
    {
        $qdot = $this->qdot();
        $m = $this->massMatrix();
        return 0.5 * self::quadraticForm($m, $qdot);
    }

    /**
     * $part's velocity along ($nx, $ny, $nz) now, via the same Jacobian effectiveInverseMass()/applyImpulse() use
     * (Jₙ · q̇) - a contact handler's own "closing speed" must be measured this way, not assumed, whenever the
     * struck part might already be moving (the usual case, once anything has been thrown): a test asserts this
     * agrees with the part's real kinematic velocity (Node3D::kineticState()) permanently.
     */
    public function normalVelocity(Node3D $part, float $nx, float $ny, float $nz): float
    {
        [$jv] = $this->jacobians($part);
        $jn = array_map(static fn (array $v) => $nx * $v[0] + $ny * $v[1] + $nz * $v[2], $jv);
        $qdot = $this->qdot();
        $s = 0.0;
        foreach ($jn as $k => $v) {
            $s += $v * $qdot[$k];
        }
        return $s;
    }

    /** A contact at $part's own centre gives it a linear impulse of $impulse along ($nx, $ny, $nz): Δq̇ = M⁻¹ Jₙᵀ
     * λ, the exact (momentum- and energy-consistent) response of the whole articulated body, root and every
     * joint between $part and it included, each in proportion to how the mass matrix actually couples them at
     * this pose - not an approximation that needs clamping back afterwards. */
    public function applyImpulse(Node3D $part, float $nx, float $ny, float $nz, float $impulse): void
    {
        [$jv] = $this->jacobians($part);
        $jn = array_map(static fn (array $v) => $nx * $v[0] + $ny * $v[1] + $nz * $v[2], $jv);
        $minv = $this->inverseMassMatrix();
        $n = $this->dof();
        $delta = [];
        for ($i = 0; $i < $n; ++$i) {
            $s = 0.0;
            for ($k = 0; $k < $n; ++$k) {
                $s += $minv[$i][$k] * $jn[$k];
            }
            $delta[$i] = $s * $impulse;
        }
        $this->applyDelta($delta);
    }

    /**
     * A Hinge, Ball or Slider reaching a limit (or a tether going taut) turns $joint's own rate to $newRate: the
     * same mass-matrix solve as applyImpulse(), aimed at one generalized coordinate (the joint's own) reaching a
     * target value instead of a world-space contact normal - the reaction this takes from the rest of the body
     * (root included) falls out of the same M⁻¹, not a separate rule.
     *
     * A coupled solve is only as good as M⁻¹: at a pose where two joints' axes and positions are nearly dependent
     * (a chain folded on itself - common for a many-jointed rope ragdoll), Gauss-Jordan elimination's M⁻¹ can be
     * inaccurate enough that M·M⁻¹ meaningfully differs from the identity, and the usual guarantee that a bounce
     * (bounce ≤ 1) never raises kinetic energy - exact whenever M⁻¹ is exact - breaks: a little energy slips in,
     * and bounce after bounce (the common case for a limit under a sustained load) compounds it, eventually driving
     * a joint's own rate (and so everything coupled to it) to the point where other parts of the engine - the
     * compound's probing against nearby bodies, which bounds how fast it reschedules on a turning node's own
     * angular speed - start firing thousands of times a second. The fix is to check the one invariant that matters
     * (kinetic energy cannot rise here) and fall back to an uncoupled bounce - exactly what a joint without a Body
     * already does - whenever the coupled solve would violate it: real articulation is lost only at the rare poses
     * where M⁻¹ cannot be trusted, and energy is never gained.
     */
    public function jointLimitImpulse(Node3D $joint, float $newRate): void
    {
        $i = $this->jointIndex[spl_object_id($joint)] ?? null;
        if ($i === null) {
            return;
        }
        $col = 6 + $i;
        $minv = $this->inverseMassMatrix();
        $diag = $minv[$col][$col];
        if ($diag < 1e-9) {
            $joint->setJointRate($newRate);   // too close to singular (or, numerically, negative) to trust: bounce it alone
            return;
        }
        $tau = ($newRate - $joint->jointRate) / $diag;
        $n = $this->dof();
        $delta = [];
        for ($i2 = 0; $i2 < $n; ++$i2) {
            $delta[$i2] = $minv[$i2][$col] * $tau;
        }
        $before = $this->snapshotVelocities();
        $keBefore = $this->kineticEnergy();
        $this->applyDelta($delta);
        if ($this->kineticEnergy() > $keBefore + 1e-6 * max(1.0, abs($keBefore))) {
            $this->restoreVelocities($before);
            $joint->setJointRate($newRate);   // the coupled solve gained energy here: bounce it alone instead
        }
    }

    /** The root's own velocity and spin, and every joint's own rate - what jointLimitImpulse() restores exactly if
     * a coupled solve turns out untrustworthy at the current pose (see there). */
    private function snapshotVelocities(): array
    {
        $root = $this->root;
        return [$root->velocityX, $root->velocityY, $root->velocityZ, $root->mwx, $root->mwy, $root->mwz, array_map(static fn (Node3D $j) => $j->jointRate, $this->joints)];
    }

    private function restoreVelocities(array $snapshot): void
    {
        [$vx, $vy, $vz, $wx, $wy, $wz, $rates] = $snapshot;
        $root = $this->root;
        $root->setVelocity($vx, $vy, $vz);
        $root->spin($wx, $wy, $wz);
        foreach ($this->joints as $i => $joint) {
            if ($joint->jointRate !== $rates[$i]) {
                $joint->setJointRate($rates[$i]);
            }
        }
    }

    /** Applies a generalized velocity change (root linear, root angular, then one per joint) to the real tree. */
    private function applyDelta(array $delta): void
    {
        $root = $this->root;
        $root->setVelocity($root->velocityX + $delta[0], $root->velocityY + $delta[1], $root->velocityZ + $delta[2]);
        $root->spin($root->mwx + $delta[3], $root->mwy + $delta[4], $root->mwz + $delta[5]);
        foreach ($this->joints as $i => $joint) {
            if ($delta[6 + $i] != 0.0) {
                $joint->setJointRate($joint->jointRate + $delta[6 + $i]);
            }
        }
    }

    /** Every descendant of $root (not $root itself), depth-first. */
    private static function descendants(Node3D $root): \Generator
    {
        foreach ($root->children as $child) {
            yield $child;
            yield from self::descendants($child);
        }
    }

    /** Where $node sits in the rest pose, relative to $root, and the length of its chain of offsets from it - used
     * only for the super sphere (a static bound on how far a part can ever reach, not a dynamics quantity). */
    private static function restOffset(Node3D $root, Node3D $node): array
    {
        [$x, $y, $z, $chain] = [0.0, 0.0, 0.0, 0.0];
        for ($n = $node; $n !== $root; $n = $n->parent) {
            [$x, $y, $z] = [$x + $n->x, $y + $n->y, $z + $n->z];
            $chain += sqrt($n->x ** 2 + $n->y ** 2 + $n->z ** 2);
        }
        return [$x, $y, $z, $chain];
    }

    /** The inverse of an N x N matrix (list of N rows of N floats), by Gauss-Jordan elimination with partial
     * pivoting - called once per hit or limit event, not per tick, so O(N^3) for N ~ 10-20 costs nothing worth
     * avoiding. A column that cannot be pivoted (a genuinely singular direction - an isolated point with nothing
     * to turn it) is left as the identity's: that generalized coordinate just does not couple to anything else. */
    private static function invert(array $m): array
    {
        $n = count($m);
        $a = [];
        for ($i = 0; $i < $n; ++$i) {
            $row = $m[$i];
            for ($j = 0; $j < $n; ++$j) {
                $row[] = $i === $j ? 1.0 : 0.0;
            }
            $a[] = $row;
        }
        for ($col = 0; $col < $n; ++$col) {
            $pivot = $col;
            for ($r = $col + 1; $r < $n; ++$r) {
                if (abs($a[$r][$col]) > abs($a[$pivot][$col])) {
                    $pivot = $r;
                }
            }
            if (abs($a[$pivot][$col]) < 1e-12) {
                continue;
            }
            [$a[$col], $a[$pivot]] = [$a[$pivot], $a[$col]];
            $div = $a[$col][$col];
            for ($j = 0; $j < 2 * $n; ++$j) {
                $a[$col][$j] /= $div;
            }
            for ($r = 0; $r < $n; ++$r) {
                if ($r === $col || $a[$r][$col] == 0.0) {
                    continue;
                }
                $factor = $a[$r][$col];
                for ($j = 0; $j < 2 * $n; ++$j) {
                    $a[$r][$j] -= $factor * $a[$col][$j];
                }
            }
        }
        $inv = [];
        for ($i = 0; $i < $n; ++$i) {
            $inv[] = array_slice($a[$i], $n);
        }
        return $inv;
    }
}
