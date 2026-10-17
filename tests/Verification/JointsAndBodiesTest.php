<?php

namespace Phame\Tests\Verification;

use Phame\Physics\Body;
use Phame\Physics\JointType;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 gate: joints and bodies (see docs/PHYSICS-PLAN.md). A Body's momentum bookkeeping is checked directly
 * against the real kinematics of its parts (Node3D::kineticState(), the same mechanism collision detection already
 * trusts) - not against Body's own internal totals, so a bug in Body could not hide behind its own arithmetic.
 */
final class JointsAndBodiesTest extends TestCase
{
    /** Builds a world and scene: no gravity is ever set on it, so these tests isolate the engine's own
     * conservation, which an external force would otherwise have to be subtracted back out of. */
    private function stage(): array
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(1000, 1000));
        return [$world, $scene];
    }

    /** A hinge (Node3D) with $mass at $offset along x from its own origin, itself at $pivot along x from $parent. */
    private function arm(Node3D $parent, float $pivot, float $offset, float $mass, float $radius = 0.3): array
    {
        $joint = new Node3D();
        $joint->jointType = JointType::Hinge;
        $joint->jointAxisX = 0.0;
        $joint->jointAxisY = 1.0;
        $joint->jointAxisZ = 0.0;
        $joint->jointMin = -2.0;
        $joint->jointMax = 2.0;
        $joint->moveTo($pivot, 0.0, 0.0);
        $parent->addChild($joint);
        $limb = new Node3D();
        $limb->radius = $radius;
        $limb->mass = $mass;
        $limb->moveTo($offset, 0.0, 0.0);
        $joint->addChild($limb);
        return [$joint, $limb];
    }

    /** @param list<Node3D> $parts @return array{float,float,float} total linear momentum, from real kinematics. */
    private function momentum(array $parts): array
    {
        [$px, $py, $pz] = [0.0, 0.0, 0.0];
        foreach ($parts as $part) {
            $part->kineticState();
            $px += $part->mass * \Phame\Kinetic\Kinetic::$vx;
            $py += $part->mass * \Phame\Kinetic\Kinetic::$vy;
            $pz += $part->mass * \Phame\Kinetic\Kinetic::$vz;
        }
        return [$px, $py, $pz];
    }

    /** As momentum(), angular about $root's current origin, including each part's own spin (its ancestors',
     * composed) about its own centre - the same decomposition Body::totalMomentum() uses internally. */
    private function angularMomentum(Node3D $root, array $parts): array
    {
        $rm = $root->getWorldMatrix();
        [$ox, $oy, $oz] = [$rm->m12, $rm->m13, $rm->m14];
        [$lx, $ly, $lz] = [0.0, 0.0, 0.0];
        foreach ($parts as $part) {
            $part->kineticState();
            [$vx, $vy, $vz] = [\Phame\Kinetic\Kinetic::$vx, \Phame\Kinetic\Kinetic::$vy, \Phame\Kinetic\Kinetic::$vz];
            $m = $part->getWorldMatrix();
            [$rx, $ry, $rz] = [$m->m12 - $ox, $m->m13 - $oy, $m->m14 - $oz];
            $lx += $part->mass * ($ry * $vz - $rz * $vy);
            $ly += $part->mass * ($rz * $vx - $rx * $vz);
            $lz += $part->mass * ($rx * $vy - $ry * $vx);
            [$wx, $wy, $wz] = $this->worldSpin($root, $part);
            $self = 0.4 * $part->mass * $part->radius ** 2;
            $lx += $self * $wx;
            $ly += $self * $wy;
            $lz += $self * $wz;
        }
        return [$lx, $ly, $lz];
    }

    /** $node's own angular velocity in world space: every ancestor's spin up to AND INCLUDING $root, composed -
     * the root's own tumble turns every part's own body frame too (a rigidly-welded part spins exactly with it),
     * not only what a joint between $node and $root adds on top of that. */
    private function worldSpin(Node3D $root, Node3D $node): array
    {
        [$wx, $wy, $wz] = [0.0, 0.0, 0.0];
        for ($n = $node; $n !== null; $n = $n->parent) {
            if ($n->mwx != 0.0 || $n->mwy != 0.0 || $n->mwz != 0.0) {
                $p = $n->parent?->getWorldMatrix();
                if ($p === null) {
                    [$wx, $wy, $wz] = [$wx + $n->mwx, $wy + $n->mwy, $wz + $n->mwz];
                } else {
                    $wx += $p->m0 * $n->mwx + $p->m4 * $n->mwy + $p->m8 * $n->mwz;
                    $wy += $p->m1 * $n->mwx + $p->m5 * $n->mwy + $p->m9 * $n->mwz;
                    $wz += $p->m2 * $n->mwx + $p->m6 * $n->mwy + $p->m10 * $n->mwz;
                }
            }
            if ($n === $root) {
                break;
            }
        }
        return [$wx, $wy, $wz];
    }

    /** @param list<Node3D> $parts Total translational kinetic energy, from real kinematics. */
    private function kineticEnergy(array $parts): float
    {
        $e = 0.0;
        foreach ($parts as $part) {
            $part->kineticState();
            $e += 0.5 * $part->mass * (\Phame\Kinetic\Kinetic::$vx ** 2 + \Phame\Kinetic\Kinetic::$vy ** 2 + \Phame\Kinetic\Kinetic::$vz ** 2);
        }
        return $e;
    }

    /** As kineticEnergy(), plus each part's own rotational energy about its own centre (a sphere spinning with
     * whatever ancestor chain carries it - the randomized stress test needs this not to be able to hide energy). */
    private function kineticEnergyFull(Node3D $root, array $parts): float
    {
        $e = $this->kineticEnergy($parts);
        foreach ($parts as $part) {
            [$wx, $wy, $wz] = $this->worldSpin($root, $part);
            $e += 0.5 * (0.4 * $part->mass * $part->radius ** 2) * ($wx ** 2 + $wy ** 2 + $wz ** 2);
        }
        return $e;
    }

    /** @param list<Node3D> $parts Angular momentum about an arbitrary world point, from real kinematics (not
     * necessarily the root's own origin - the randomized stress test checks it about the centre of mass). */
    private function angularMomentumAbout(Node3D $root, array $parts, array $point): array
    {
        [$ox, $oy, $oz] = $point;
        [$lx, $ly, $lz] = [0.0, 0.0, 0.0];
        foreach ($parts as $part) {
            $part->kineticState();
            [$vx, $vy, $vz] = [\Phame\Kinetic\Kinetic::$vx, \Phame\Kinetic\Kinetic::$vy, \Phame\Kinetic\Kinetic::$vz];
            $m = $part->getWorldMatrix();
            [$rx, $ry, $rz] = [$m->m12 - $ox, $m->m13 - $oy, $m->m14 - $oz];
            $lx += $part->mass * ($ry * $vz - $rz * $vy);
            $ly += $part->mass * ($rz * $vx - $rx * $vz);
            $lz += $part->mass * ($rx * $vy - $ry * $vx);
            [$wx, $wy, $wz] = $this->worldSpin($root, $part);
            $self = 0.4 * $part->mass * $part->radius ** 2;
            $lx += $self * $wx;
            $ly += $self * $wy;
            $lz += $self * $wz;
        }
        return [$lx, $ly, $lz];
    }

    /** @param list<Node3D> $parts mass-weighted centre, from real world positions. */
    private function centerOfMass(array $parts): array
    {
        [$cx, $cy, $cz, $mass] = [0.0, 0.0, 0.0, 0.0];
        foreach ($parts as $part) {
            $m = $part->getWorldMatrix();
            $cx += $part->mass * $m->m12;
            $cy += $part->mass * $m->m13;
            $cz += $part->mass * $m->m14;
            $mass += $part->mass;
        }
        return [$cx / $mass, $cy / $mass, $cz / $mass];
    }

    public function testLinearMomentumIsExactThroughAJointLimitAndAHit(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $root = new Node3D();
                $root->radius = 0.0;
                $scene->root->addChild($root);
                $torso = new Node3D();
                $torso->radius = 1.0;
                $torso->mass = 10.0;
                $root->addChild($torso);
                [$arm, $limb] = $this->arm($root, 2.0, 1.0, 1.0);
                $body = new Body($root);
                $parts = [$torso, $limb];

                $root->setVelocity(1.0, 0.0, 0.0);
                $this->assertSame([10.0 + 1.0, 0.0, 0.0], $this->momentum($parts), 'baseline: a straight throw at rest joints');

                // drive the joint until it bounces off its own limit: momentum must not so much as twitch
                $arm->setJointRate(5.0);   // fast: reaches its limit well within the test's own window
                [$beforePx, $beforePy, $beforePz] = $this->momentum($parts);
                $w->tick($arm->jointUntilLimit() + 0.05);   // past the limit event
                [$afterPx, $afterPy, $afterPz] = $this->momentum($parts);
                $this->assertEqualsWithDelta($beforePx, $afterPx, 1e-9, 'a joint limit is internal: it cannot change the system\'s own momentum');
                $this->assertEqualsWithDelta($beforePy, $afterPy, 1e-9);
                $this->assertEqualsWithDelta($beforePz, $afterPz, 1e-9);

                // a hit: ball's gain must exactly equal the body's loss
                $ball = new Node3D();
                $ball->radius = 0.4;
                $ball->mass = 2.0;
                $ball->moveTo($limb->x, $limb->y, $limb->z);
                $scene->root->addChild($ball);
                $ball->setVelocity(-3.0, 0.0, 0.0);
                [$totalBefore] = [$this->momentum([...$parts, $ball])];
                $inverse = $body->effectiveInverseMass($limb, 1.0, 0.0, 0.0);
                $impulseOnBall = -1.5 * -3.0 / (1.0 / $ball->mass + $inverse);   // e=0.5, closing speed 3 along +x
                $ball->setVelocity($ball->velocityX + $impulseOnBall / $ball->mass, $ball->velocityY, $ball->velocityZ);
                $body->applyImpulse($limb, 1.0, 0.0, 0.0, -$impulseOnBall);
                $totalAfter = $this->momentum([...$parts, $ball]);
                $this->assertEqualsWithDelta($totalBefore[0], $totalAfter[0], 1e-9, 'ball + body: exactly conserved through the hit');
                $this->assertEqualsWithDelta($totalBefore[1], $totalAfter[1], 1e-9);
                $this->assertEqualsWithDelta($totalBefore[2], $totalAfter[2], 1e-9);
                $w->stop();
            });
        });
    }

    public function testAngularMomentumStaysWithinToleranceAcrossAFreeSwing(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $root = new Node3D();
                $root->radius = 0.0;
                $scene->root->addChild($root);
                $torso = new Node3D();
                $torso->radius = 1.0;
                $torso->mass = 10.0;
                $root->addChild($torso);
                [$arm1, $limb1] = $this->arm($root, 2.0, 1.0, 1.0);
                [$arm2, $limb2] = $this->arm($root, -2.0, -1.0, 1.0);
                $body = new Body($root);
                $parts = [$torso, $limb1, $limb2];

                $arm1->setJointRate(2.0);
                [$l0x, $l0y, $l0z] = $this->angularMomentum($root, $parts);
                $this->assertEqualsWithDelta(0.0, $l0y, 1e-9, 'starting from rest: still zero the instant it starts');

                // a game drives the joint smoothly: many small steps, each one refreshing the body (as instructed -
                // "refresh mass properties at joint events") - the error this leaves is bounded by the step, not
                // by how long the swing lasts, which is exactly the tolerance Phase 2's gate asks for.
                for ($i = 0; $i < 40; ++$i) {
                    $w->tick(0.01);
                    $arm1->setJointRate($arm1->jointRate);
                    [$lx, $ly, $lz] = $this->angularMomentum($root, $parts);
                    $this->assertEqualsWithDelta(0.0, $ly, 0.02, "angular momentum drifted too far at step $i");
                }
                $w->stop();
            });
        });
    }

    public function testEnergyNeverIncreasesAtAJointLimitBounce(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $root = new Node3D();
                $root->radius = 0.0;
                $scene->root->addChild($root);
                $torso = new Node3D();
                $torso->radius = 1.0;
                $torso->mass = 20.0;   // heavy, so the root barely reacts: isolates the joint's own bounce loss
                $root->addChild($torso);
                [$arm, $limb] = $this->arm($root, 2.0, 1.0, 1.0);
                $arm->jointBounce = 0.4;   // well under 1: a real loss, not a borderline rounding one
                new Body($root);
                $parts = [$torso, $limb];

                $arm->setJointRate(3.0);
                $beforeLimit = $this->kineticEnergy($parts);
                $w->tick($arm->jointUntilLimit() + 0.02);   // past the bounce
                $afterLimit = $this->kineticEnergy($parts);
                $this->assertLessThan($beforeLimit, $afterLimit, 'a sub-1 bounce must cost real kinetic energy');
                $w->stop();
            });
        });
    }

    /**
     * Coulomb friction's closed form: a hinge spinning at $omega0, decelerating at constant $alpha, travels
     * $omega0²/(2·$alpha) before stopping, taking $omega0/$alpha seconds to do it - Phase 1's own constant-
     * deceleration kinematics (docs/PHYSICS-PLAN.md), applied to a joint's own angle instead of a position.
     */
    public function testHingeFrictionStopsAtTheExactClosedFormAngleAndTime(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $root = new Node3D();
                $root->radius = 0.0;
                $scene->root->addChild($root);
                [$joint, $limb] = $this->arm($root, 2.0, 1.0, 1.0);
                $joint->jointMin = -1000.0;
                $joint->jointMax = 1000.0;   // wide enough that only friction, never a limit, stops it
                $alpha = 1.5;
                $joint->jointFriction = $alpha;
                $omega0 = 4.0;
                $theta0 = $joint->jointPositionNow();
                $joint->setJointRate($omega0);

                $tStop = $omega0 / $alpha;
                $thetaStop = $theta0 + $omega0 * $omega0 / (2.0 * $alpha);

                $w->tick($tStop + 0.5);   // well past the stop: the event must already have fired and frozen it

                $this->assertSame(0.0, $joint->jointRate, 'friction alone must leave the joint exactly at rest');
                $this->assertEqualsWithDelta($thetaStop, $joint->jointPositionNow(), 1e-9, 'stopped at the exact closed-form angle');
                $w->stop();
            });
        });
    }

    /**
     * The randomized energy gate (see testRandomizedPosesAndHitsNeverGainEnergyAndConserveMomentumExactly()),
     * extended for Node3D::$jointFriction: unlike a limit bounce (one instant, checked once), friction removes
     * energy continuously, so this samples kinetic energy at several points during - and checks it is monotonically
     * non-increasing, in a system with no other energy source (no hit, no limit reached: friction is the only thing
     * happening). The root is left at rest and every joint hangs directly off it, no nesting (unlike the general
     * random-structure helper the other randomized gate uses): Node3D::$jointFriction decelerates each joint's own
     * generalized coordinate in isolation (a kinematic description, like Phase 1's, not a coupled dynamic force
     * solved through the mass matrix), so the total 0.5 q̇ᵀMq̇ is only guaranteed non-increasing where that is also
     * exactly how the physics decomposes: a root at rest with every joint a direct, independent child makes M
     * block-diagonal in the joints (no part is ever a descendant of two of them, so every cross term is exactly
     * zero) and each joint's own diagonal term constant (its part's lever arm about a fixed axis does not change as
     * it turns) - total energy is then exactly Σ ½ Mᵢᵢ rateᵢ², strictly decreasing termwise. Nest a joint under
     * another turning one, or give the root its own motion, and this stops holding - not a bug, the same way two
     * ordinary constant velocities can momentarily add up to more speed or less depending on the angle between
     * them: a lever's own instantaneous contribution to a shared, moving frame's kinetic energy depends on
     * direction, not only on the magnitudes decelerating it.
     */
    public function testRandomizedJointFrictionNeverIncreasesEnergy(): void
    {
        [$world, $scene] = $this->stage();
        $failures = [];
        \phasync::run(function () use ($world, $scene, &$failures) {
            $world->run(function (World $w) use ($scene, &$failures) {
                mt_srand(20261101);
                $trials = 500;
                for ($trial = 0; $trial < $trials; ++$trial) {
                    $root = new Node3D();
                    $root->radius = 0.0;
                    $scene->root->addChild($root);
                    $root->confined = false;
                    $torso = new Node3D();
                    $torso->radius = 0.8 + self::rnd(0.0, 1.0);
                    $torso->mass = 3.0 + self::rnd(0.0, 5.0);
                    $root->addChild($torso);
                    $parts = [$torso];
                    $joints = [];
                    $chains = mt_rand(1, 4);
                    for ($c = 0; $c < $chains; ++$c) {
                        // every joint a direct child of the root - no nesting (see this test's own docblock for why)
                        $joint = new Node3D();
                        $joint->jointType = JointType::Hinge;
                        [$ax, $ay, $az] = self::randomUnitVector();
                        [$joint->jointAxisX, $joint->jointAxisY, $joint->jointAxisZ] = [$ax, $ay, $az];
                        $joint->jointMin = -1000.0;
                        $joint->jointMax = 1000.0;   // friction, not a limit, is what this trial measures
                        $joint->jointFriction = self::rnd(0.1, 3.0);
                        $joint->moveTo(self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5));
                        $root->addChild($joint);
                        $joints[] = $joint;
                        $limb = new Node3D();
                        $limb->radius = 0.2 + self::rnd(0.0, 0.5);
                        $limb->mass = 0.3 + self::rnd(0.0, 2.0);
                        $limb->moveTo(self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5));
                        $joint->addChild($limb);
                        $parts[] = $limb;
                    }
                    foreach ($joints as $joint) {
                        $joint->setJointRate(self::rnd(-6.0, 6.0));
                    }
                    $body = new Body($root);

                    $previous = $body->kineticEnergy();
                    for ($step = 0; $step < 5; ++$step) {
                        $w->tick(self::rnd(0.05, 0.4));
                        $now = $body->kineticEnergy();
                        if ($now > $previous + 1e-6 * max(1.0, abs($previous))) {
                            $failures[] = sprintf('trial %d step %d: KE rose %.9f -> %.9f', $trial, $step, $previous, $now);
                        }
                        $previous = $now;
                    }
                    $root->dispose();
                }
                $w->stop();
            });
        });
        $this->assertSame([], array_slice($failures, 0, 10), sprintf('%d of 2500 steps failed', count($failures)));
    }

    public function testSuperSphereIsNeverViolatedAtSampledTimes(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $root = new Node3D();
                $root->radius = 0.0;
                $scene->root->addChild($root);
                $torso = new Node3D();
                $torso->radius = 1.0;
                $torso->mass = 5.0;
                $root->addChild($torso);
                [$arm1, $limb1] = $this->arm($root, 2.0, 1.5, 1.0, 0.4);
                [$arm2, $limb2] = $this->arm($root, -2.0, -1.5, 1.0, 0.4);
                $body = new Body($root);
                $parts = [$torso, $limb1, $limb2];

                $arm1->setJointRate(1.7);
                $arm2->setJointRate(-1.3);
                for ($i = 0; $i < 60; ++$i) {
                    $w->tick(0.03);
                    $arm1->setJointRate($arm1->jointRate);
                    $arm2->setJointRate($arm2->jointRate);
                    $rm = $root->getWorldMatrix();
                    foreach ($parts as $part) {
                        $m = $part->getWorldMatrix();
                        $d = sqrt(($m->m12 - $rm->m12) ** 2 + ($m->m13 - $rm->m13) ** 2 + ($m->m14 - $rm->m14) ** 2);
                        $this->assertLessThanOrEqual($root->radius + 1e-6, $d + $part->radius, "a part left the super sphere at step $i");
                    }
                }
                $w->stop();
            });
        });
    }

    public function testCentreOfPercussionOfAHingedRodStruckAtTwoThirdsGivesNoReactionAtTheHinge(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $length = 3.0;
                $segments = 30;
                $totalMass = 6.0;
                $root = new Node3D();
                $root->radius = 0.0;
                $scene->root->addChild($root);
                $points = [];
                for ($i = 1; $i <= $segments; ++$i) {
                    $p = new Node3D();
                    $p->radius = 0.001;
                    $p->mass = $totalMass / $segments;
                    $p->moveTo($length * $i / $segments, 0.0, 0.0);
                    $root->addChild($p);
                    $points[] = $p;
                }
                $body = new Body($root);

                $strike = function (float $d) use ($body, $root, $points): float {
                    $closest = $points[0];
                    $best = INF;
                    foreach ($points as $p) {
                        if (abs($p->x - $d) < $best) {
                            $best = abs($p->x - $d);
                            $closest = $p;
                        }
                    }
                    $body->applyImpulse($closest, 0.0, 0.0, 1.0, 1.0);
                    $vz = $root->velocityZ;
                    $root->setVelocity(0.0, 0.0, 0.0);
                    $root->spin(0.0, 0.0, 0.0);
                    return $vz;
                };

                // the hinge end feels a reaction either way a little off the sweet spot, and (within this
                // discretisation's own resolution) none exactly at it
                $before = $strike($length * 2.0 / 3.0 - 0.15);
                $at = $strike($length * 2.0 / 3.0);
                $after = $strike($length * 2.0 / 3.0 + 0.15);
                $this->assertLessThan(0.0, $before * $after, 'the reaction changes sign either side of the sweet spot');
                $this->assertLessThan(abs($before), abs($at), 'the sweet spot itself is closer to zero than either side');
                $this->assertLessThan(abs($after), abs($at) + 1e-9, 'the sweet spot itself is closer to zero than either side');
                $this->assertEqualsWithDelta(0.0, $at, 0.02, 'struck at 2/3 of its length, a uniform rod gives (almost) no reaction at the hinge');
                $w->stop();
            });
        });
    }

    public function testFigureSkaterConservesAngularMomentumAsArmsPullIn(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $root = new Node3D();
                $root->radius = 0.0;
                $root->mass = 1.0;   // the torso itself: negligible radius, a small reference mass
                $scene->root->addChild($root);
                $mkSlider = function (float $x) use ($root) {
                    $slider = new Node3D();
                    $slider->jointType = JointType::Slider;
                    $slider->jointAxisX = 1.0;
                    $slider->jointAxisY = 0.0;
                    $slider->jointAxisZ = 0.0;
                    $slider->jointMin = 0.3;
                    $slider->jointMax = 3.0;
                    $slider->moveTo($x, 0.0, 0.0);
                    $root->addChild($slider);
                    $weight = new Node3D();
                    $weight->radius = 0.1;
                    $weight->mass = 2.0;
                    $slider->addChild($weight);
                    $slider->setJointRate(0.0);   // establishes jointPos at its current offset
                    return [$slider, $weight];
                };
                [$sliderA, $weightA] = $mkSlider(2.0);
                [$sliderB, $weightB] = $mkSlider(-2.0);
                $body = new Body($root);
                $parts = [$weightA, $weightB];

                $root->spin(0.0, 0.0, 1.0);   // spinning about z: the arms (along x) are the lever for it
                [, , $l0] = $this->angularMomentum($root, $parts);
                $omega0 = $root->mwz;

                // pull both arms in, a small step at a time (each one a joint event the body refreshes at)
                for ($i = 0; $i < 60; ++$i) {
                    $w->tick(0.01);
                    $sliderA->setJointRate(-0.5);
                    $sliderB->setJointRate(0.5);
                    [, , $l] = $this->angularMomentum($root, $parts);
                    $this->assertEqualsWithDelta($l0, $l, max(0.03, abs($l0) * 0.08), "angular momentum drifted too far pulling in at step $i");
                }
                $this->assertGreaterThan(abs($omega0) * 1.5, abs($root->mwz), 'arms pulled in: spin must speed up to conserve L');
                $w->stop();
            });
        });
    }

    public function testFallingCatZeroAngularMomentumStaysZeroWhileTheShapeChanges(): void
    {
        [$world, $scene] = $this->stage();
        \phasync::run(function () use ($world, $scene) {
            $world->run(function (World $w) use ($scene) {
                $root = new Node3D();
                $root->radius = 0.0;
                $scene->root->addChild($root);
                $torso = new Node3D();
                $torso->radius = 1.0;
                $torso->mass = 8.0;
                $root->addChild($torso);
                [$arm1, $limb1] = $this->arm($root, 2.0, 1.2, 1.0);
                [$arm2, $limb2] = $this->arm($root, -2.0, -1.2, 1.0);
                $body = new Body($root);
                $parts = [$torso, $limb1, $limb2];

                // starts at rest: L = 0 exactly. Nothing from here on is an external impulse - only the shape
                // changes (both arms swinging, out of phase with each other so the pose keeps changing, not
                // undoing itself step for step).
                $arm1->setJointRate(1.4);
                $arm2->setJointRate(-0.9);
                for ($i = 0; $i < 80; ++$i) {
                    $w->tick(0.01);
                    $arm1->setJointRate($arm1->jointRate);
                    $arm2->setJointRate($arm2->jointRate);
                    [$lx, $ly, $lz] = $this->angularMomentum($root, $parts);
                    $this->assertEqualsWithDelta(0.0, $ly, 0.03, "angular momentum should stay at zero (step $i)");
                }
                $this->assertNotSame(0.0, $root->mwy, 'the shape changing still turns the root, even at L = 0');
                $w->stop();
            });
        });
    }

    /**
     * The gate the coordinator asked for after the clamps: thousands of seeded random ragdoll-like trees, each in
     * a random pose (random joint angles within their own limits, random joint rates, a random tumble), hit once
     * at a random part along a random normal with a random restitution - energy must never be gained, linear
     * momentum must change by exactly the impulse, and angular momentum about the centre of mass must change by
     * exactly r x J (a general identity of rigid-body mechanics, true of any correct impulse response, checked
     * here by a formula Body itself never computes, so a bug in Body cannot hide behind its own arithmetic). This
     * is what caught the original bug (an approximate, rest-pose effective mass that could manufacture energy on
     * a multi-joint chain): with Body's old clamp-based safety net removed, this must still pass, because the
     * exact mass-matrix solve cannot produce a losing answer to clamp in the first place.
     */
    public function testRandomizedPosesAndHitsNeverGainEnergyAndConserveMomentumExactly(): void
    {
        [$world, $scene] = $this->stage();
        $failures = [];
        \phasync::run(function () use ($world, $scene, &$failures) {
            $world->run(function (World $world) use ($scene, &$failures) {
                mt_srand(20261017);
                $trials = 2000;
                for ($trial = 0; $trial < $trials; ++$trial) {
                    $root = new Node3D();
                    $root->radius = 0.0;
                    $scene->root->addChild($root);
                    $torso = new Node3D();
                    $torso->radius = 0.8 + self::rnd(0.0, 1.0);
                    $torso->mass = 3.0 + self::rnd(0.0, 5.0);
                    $root->addChild($torso);
                    $root->confined = false;
                    $parts = [$torso];
                    $joints = [];
                    // 2-3 chains of 1-2 joints each (Hinge, or occasionally Ball - a fixed axis for this trial, no
                    // mid-flight redirection: Body never redirects a Ball's axis itself, only a game explicitly
                    // asking to would, and that one instant is a deliberate discontinuity, not this gate's concern),
                    // random axes, limits, a random target angle - every joint reaches its own target over the SAME
                    // one second of game time (setJointRate(), then one shared World::advance()), so moving one
                    // cannot drift another's, and jointRate/jointPos (what applyImpulse()'s own setJointRate() calls
                    // read as "before") stay correct throughout - unlike setOrientation()/spin() directly.
                    $chains = mt_rand(2, 3);
                    for ($c = 0; $c < $chains; ++$c) {
                        $parent = $root;
                        $depth = mt_rand(1, 2);
                        for ($d = 0; $d < $depth; ++$d) {
                            $joint = new Node3D();
                            $joint->jointType = mt_rand(0, 3) === 0 ? JointType::Ball : JointType::Hinge;
                            [$ax, $ay, $az] = self::randomUnitVector();
                            [$joint->jointAxisX, $joint->jointAxisY, $joint->jointAxisZ] = [$ax, $ay, $az];
                            $joint->jointMin = -self::rnd(0.3, 1.5);
                            $joint->jointMax = self::rnd(0.3, 1.5);
                            $joint->moveTo(self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5));
                            $parent->addChild($joint);
                            $angle = self::rnd($joint->jointMin, $joint->jointMax);
                            $joint->setJointRate($angle);   // reached in exactly 1 second, starting at angle 0
                            $joints[] = $joint;
                            $limb = new Node3D();
                            $limb->radius = 0.2 + self::rnd(0.0, 0.5);
                            $limb->mass = 0.3 + self::rnd(0.0, 2.0);
                            $limb->moveTo(self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5), self::rnd(-1.5, 1.5));
                            $joint->addChild($limb);
                            $parts[] = $limb;
                            $parent = $joint;
                        }
                    }
                    $world->advance(1.0);   // every joint above is now at its own target angle, all having moved together
                    foreach ($joints as $joint) {
                        $joint->setJointRate(self::rnd(-2.5, 2.5));   // its real, independent rate for the hit itself
                    }
                    $root->setVelocity(self::rnd(-5.0, 5.0), self::rnd(-5.0, 5.0), self::rnd(-5.0, 5.0));
                    $root->spin(self::rnd(-3.0, 3.0), self::rnd(-3.0, 3.0), self::rnd(-3.0, 3.0));
                    $body = new Body($root);

                    // identity 1 (state consistency), before the hit: the mass matrix's own ½ q̇ᵀ M q̇, from the
                    // generalized velocity the tree itself reports, must equal what every part's own kinematics
                    // measures - any disagreement is a bug in how a Δq̇ got written back, not in the solve itself.
                    $keBeforeFromState = $body->kineticEnergy();
                    $keBeforeMeasured = $this->kineticEnergyFull($root, $parts);
                    if (abs($keBeforeFromState - $keBeforeMeasured) > 1e-6 * max(1.0, abs($keBeforeMeasured))) {
                        $failures[] = sprintf('trial %d: KE before, from state (%.9f) != measured (%.9f)', $trial, $keBeforeFromState, $keBeforeMeasured);
                    }

                    $struck = $parts[mt_rand(0, count($parts) - 1)];
                    [$nx, $ny, $nz] = self::randomUnitVector();
                    $ballMass = 0.2 + self::rnd(0.0, 3.0);
                    $closingSpeed = self::rnd(0.5, 8.0);
                    $restitution = self::rnd(0.0, 1.0);

                    // identity 3: the struck part's own closing speed along the normal must come from the same
                    // Jacobian the solve uses (Body::normalVelocity()), not be assumed independent of its current
                    // motion - the earlier version of this test used the ball's own speed alone as "closing speed"
                    // and ignored whatever the struck part was already doing, which is exactly what injected the
                    // phantom energy the coordinator's first report found (true momentum/angular momentum, wrong
                    // energy): a struck part already moving away faster than the ball closes in should barely
                    // transfer anything, not the full restitution impulse a resting target would take.
                    $struck->kineticState();
                    $vnStruckMeasured = $nx * \Phame\Kinetic\Kinetic::$vx + $ny * \Phame\Kinetic\Kinetic::$vy + $nz * \Phame\Kinetic\Kinetic::$vz;
                    $vnStruckFromJacobian = $body->normalVelocity($struck, $nx, $ny, $nz);
                    if (abs($vnStruckMeasured - $vnStruckFromJacobian) > 1e-6 * max(1.0, abs($vnStruckMeasured))) {
                        $failures[] = sprintf('trial %d: v_n measured (%.9f) != v_n from Jacobian (%.9f)', $trial, $vnStruckMeasured, $vnStruckFromJacobian);
                    }
                    $vnBall = -$closingSpeed;   // the ball approaches from the +n side, moving in -n
                    $vRel = $vnBall - $vnStruckFromJacobian;   // ball relative to the struck part, along n

                    $comBefore = $this->centerOfMass($parts);
                    $peBefore = $this->kineticEnergyFull($root, $parts) + 0.5 * $ballMass * $closingSpeed ** 2;
                    [$pxBefore, $pyBefore, $pzBefore] = $this->momentum($parts);
                    $lBefore = $this->angularMomentumAbout($root, $parts, $comBefore);

                    $inverse = $body->effectiveInverseMass($struck, $nx, $ny, $nz);
                    $impulseOnBall = -(1.0 + $restitution) * $vRel / (1.0 / $ballMass + $inverse);
                    $body->applyImpulse($struck, $nx, $ny, $nz, -$impulseOnBall);

                    // identity 1 again, after the hit.
                    $keAfterFromState = $body->kineticEnergy();
                    $keAfterMeasured = $this->kineticEnergyFull($root, $parts);
                    if (abs($keAfterFromState - $keAfterMeasured) > 1e-6 * max(1.0, abs($keAfterMeasured))) {
                        $failures[] = sprintf('trial %d: KE after, from state (%.9f) != measured (%.9f)', $trial, $keAfterFromState, $keAfterMeasured);
                    }

                    $ballVAfter = $vnBall + $impulseOnBall / $ballMass;
                    $keAfter = $keAfterMeasured + 0.5 * $ballMass * $ballVAfter ** 2;
                    if ($keAfter > $peBefore * (1.0 + 1e-6) + 1e-9) {
                        $failures[] = sprintf('trial %d: energy gained, %.12f > %.12f', $trial, $keAfter, $peBefore);
                    }

                    [$pxAfter, $pyAfter, $pzAfter] = $this->momentum($parts);
                    $jx = -$impulseOnBall * $nx;
                    $jy = -$impulseOnBall * $ny;
                    $jz = -$impulseOnBall * $nz;
                    foreach ([[$pxBefore + $jx, $pxAfter, 'px'], [$pyBefore + $jy, $pyAfter, 'py'], [$pzBefore + $jz, $pzAfter, 'pz']] as [$expected, $actual, $label]) {
                        if (abs($expected - $actual) > 1e-6) {
                            $failures[] = sprintf('trial %d: %s expected %.9f, got %.9f', $trial, $label, $expected, $actual);
                        }
                    }

                    $sm = $struck->getWorldMatrix();
                    $rx = $sm->m12 - $comBefore[0];
                    $ry = $sm->m13 - $comBefore[1];
                    $rz = $sm->m14 - $comBefore[2];
                    $expectedDl = [$ry * $jz - $rz * $jy, $rz * $jx - $rx * $jz, $rx * $jy - $ry * $jx];
                    $lAfter = $this->angularMomentumAbout($root, $parts, $comBefore);
                    foreach ([[$lBefore[0] + $expectedDl[0], $lAfter[0], 'Lx'], [$lBefore[1] + $expectedDl[1], $lAfter[1], 'Ly'], [$lBefore[2] + $expectedDl[2], $lAfter[2], 'Lz']] as [$expected, $actual, $label]) {
                        if (abs($expected - $actual) > 1e-4) {
                            $failures[] = sprintf('trial %d: %s about COM expected %.9f, got %.9f', $trial, $label, $expected, $actual);
                        }
                    }

                    $root->dispose();
                }
                $world->stop();
            });
        });
        $this->assertSame([], array_slice($failures, 0, 10), sprintf('%d of 2000 trials failed', count($failures)));
    }
    private static function rnd(float $min, float $max): float
    {
        return $min + mt_rand(0, 1000000) / 1000000 * ($max - $min);
    }

    private static function randomUnitVector(): array
    {
        do {
            [$x, $y, $z] = [self::rnd(-1.0, 1.0), self::rnd(-1.0, 1.0), self::rnd(-1.0, 1.0)];
            $n = sqrt($x * $x + $y * $y + $z * $z);
        } while ($n < 1e-6);
        return [$x / $n, $y / $n, $z / $n];
    }
}
