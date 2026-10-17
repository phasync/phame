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
        return $m;
    }

    /**
     * M's Cholesky factor L (M = LLᵀ), fresh from the current pose every time: M is symmetric positive definite for
     * any pose as long as every generalized coordinate actually moves some real mass (see the class docblock's
     * dof()), so this always succeeds for a correctly built Body - no inverse, no regularization needed. Built
     * fresh (not cached) because a coupled solve's own applyDelta() can - through a different joint also due for
     * its own limit at this same instant - advance that joint's position too (see nudgeJointRate()); a cache keyed
     * only by game time would not see that. A failed decomposition (a non-positive pivot) means some generalized
     * coordinate has no real inertia - a joint with nothing of its own hanging below it - and is a modelling bug in
     * the body, not something to paper over here.
     */
    private function cholesky(): array
    {
        $m = $this->massMatrix();
        $n = count($m);
        $l = array_fill(0, $n, array_fill(0, $n, 0.0));
        for ($i = 0; $i < $n; ++$i) {
            for ($j = 0; $j <= $i; ++$j) {
                $s = $m[$i][$j];
                for ($k = 0; $k < $j; ++$k) {
                    $s -= $l[$i][$k] * $l[$j][$k];
                }
                if ($i === $j) {
                    if ($s <= 0.0) {
                        throw new \RuntimeException("Body's mass matrix is not positive definite at generalized coordinate $i (pivot {$s}): a joint with no real inertia of its own - give it the inertia of what hangs below it, or fold it out as a DOF");
                    }
                    $l[$i][$j] = sqrt($s);
                } else {
                    $l[$i][$j] = $s / $l[$j][$j];
                }
            }
        }
        return $l;
    }

    /** Solves M x = $rhs via $l (M's Cholesky factor): forward substitution (L y = rhs), then back substitution
     * (Lᵀ x = y) - O(n²), the only work effectiveInverseMass(), applyImpulse() and jointLimitImpulse() need from
     * M⁻¹, without ever forming it. */
    private static function solve(array $l, array $rhs): array
    {
        $n = count($l);
        $y = [];
        for ($i = 0; $i < $n; ++$i) {
            $s = $rhs[$i];
            for ($k = 0; $k < $i; ++$k) {
                $s -= $l[$i][$k] * $y[$k];
            }
            $y[$i] = $s / $l[$i][$i];
        }
        $x = array_fill(0, $n, 0.0);
        for ($i = $n - 1; $i >= 0; --$i) {
            $s = $y[$i];
            for ($k = $i + 1; $k < $n; ++$k) {
                $s -= $l[$k][$i] * $x[$k];
            }
            $x[$i] = $s / $l[$i][$i];
        }
        return $x;
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
        $x = self::solve($this->cholesky(), $jn);
        $sum = 0.0;
        foreach ($jn as $i => $v) {
            $sum += $v * $x[$i];
        }
        return $sum;
    }

    /** The current generalized velocity: the root's own linear and angular velocity, then one per joint, in the
     * same fixed order the mass matrix's rows/columns use. */
    private function qdot(): array
    {
        $root = $this->root;
        $q = [$root->velocityX, $root->velocityY, $root->velocityZ, $root->mwx, $root->mwy, $root->mwz];
        foreach ($this->joints as $joint) {
            $q[] = $joint->jointRateNow();   // decelerating under Node3D::$jointFriction, if any - not $jointRate
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
        $n = count($qdot);
        $sum = 0.0;
        for ($i = 0; $i < $n; ++$i) {
            $s = 0.0;
            for ($k = 0; $k < $n; ++$k) {
                $s += $m[$i][$k] * $qdot[$k];
            }
            $sum += $qdot[$i] * $s;
        }
        return 0.5 * $sum;
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
        $x = self::solve($this->cholesky(), $jn);
        $this->applyDelta(array_map(static fn (float $v) => $v * $impulse, $x));
    }

    /**
     * A Hinge, Ball or Slider reaching a limit (or a tether going taut) turns $joint's own rate to $newRate: the
     * same mass-matrix solve as applyImpulse(), aimed at one generalized coordinate (the joint's own) reaching a
     * target value instead of a world-space contact normal - the reaction this takes from the rest of the body
     * (root included) falls out of the same M⁻¹, not a separate rule.
     *
     * With M's exact Cholesky factor (no inverse, no regularization) and applyDelta() never clamping a joint it
     * only touches through coupling (see nudgeJointRate()), this is energy non-increasing by construction whenever
     * $newRate's bounce coefficient is at most 1: ΔKE = (newRate² - oldRate²) / (2·diag), diag = M⁻¹'s own (col,
     * col) entry, always positive for a positive-definite M - provided M itself is built from the pose $joint was
     * actually at when it crossed its limit. A tick advances game time in one jump (not step by step through each
     * event the way Space's own contacts are), so by the time this runs, $joint's honest jointPositionNow() may
     * already be past the limit by however far its rate carried it over the rest of the tick; the clock is
     * rewound to the exact crossing instant (Node3D::jointCrossingTime()) for the whole solve, then restored - the
     * same "handle it at its exact time" every other event in this engine already gets. The check below is then an
     * assertion of the identity above, not a behaviour: if it ever fires, the solve has a real bug.
     */
    public function jointLimitImpulse(Node3D $joint, float $newRate): void
    {
        $i = $this->jointIndex[spl_object_id($joint)] ?? null;
        if ($i === null) {
            return;
        }
        $col = 6 + $i;
        $limit = $joint->jointRate > 0.0 ? $joint->jointMax : $joint->jointMin;   // the side it was approaching -
        // friction only ever decelerates towards 0, never reverses, so the stored rate's sign already settles this
        $clock = $this->root->clock;
        $now = $clock?->time ?? 0.0;
        $at = $clock !== null ? $joint->jointCrossingTime($limit) : $now;
        $rewound = $clock !== null && $at < $now - 1e-9;
        if ($rewound) {
            $clock->travelTo($at);
        }
        $oldRate = $joint->jointRateNow();   // at the exact crossing instant, not $jointRate's stale, pre-friction value
        $n = $this->dof();
        $e = array_fill(0, $n, 0.0);
        $e[$col] = 1.0;
        $x = self::solve($this->cholesky(), $e);   // M⁻¹'s column $col, without ever forming M⁻¹
        $tau = ($newRate - $oldRate) / $x[$col];
        $keBefore = $this->kineticEnergy();
        $this->applyDelta(array_map(static fn (float $v) => $v * $tau, $x), $joint, $limit);
        $keAfter = $this->kineticEnergy();
        if ($rewound) {
            $clock->travelTo($now);   // the rest of the engine still thinks it is "now"; every node's own rebase()
            // mechanism correctly carries its new, post-impulse motion forward from $at to here
        }
        if ($keAfter > $keBefore + 1e-6 * max(1.0, abs($keBefore))) {
            throw new \RuntimeException("jointLimitImpulse() gained energy ($keBefore -> $keAfter): the mass-matrix solve has a bug, not the pose");
        }
    }

    /**
     * Applies a generalized velocity change (root linear, root angular, then one per joint) to the real tree. $pin
     * and $pinAt are jointLimitImpulse()'s own joint and the limit it just reached: every other joint this delta
     * touches is only a coupling reaction (nudgeJointRate() - no clamp, see there), but the one whose own limit
     * this is must land exactly on it, or a position that drifted past it from an earlier, unrelated coupling touch
     * is never corrected and keeps re-triggering.
     */
    private function applyDelta(array $delta, ?Node3D $pin = null, float $pinAt = 0.0): void
    {
        $root = $this->root;
        $root->setVelocity($root->velocityX + $delta[0], $root->velocityY + $delta[1], $root->velocityZ + $delta[2]);
        $root->spin($root->mwx + $delta[3], $root->mwy + $delta[4], $root->mwz + $delta[5]);
        foreach ($this->joints as $i => $joint) {
            if ($delta[6 + $i] != 0.0) {
                // the delta is a change to the CURRENT velocity: add it to jointRateNow(), not the stale $jointRate
                // a friction-slowing joint was given at its last event
                if ($joint === $pin) {
                    $joint->pinJointAtLimit($pinAt, $joint->jointRateNow() + $delta[6 + $i]);
                } else {
                    $joint->nudgeJointRate($joint->jointRateNow() + $delta[6 + $i]);
                }
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

}
