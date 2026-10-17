<?php

namespace Phame\Tests\Verification;

use Phame\Physics\JointType;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\TestCase;

/**
 * Space::placeParts() places a turning compound's parts from a cached per-part chain (see its own docblock),
 * evaluated at whatever time a probe asks - not by walking the tree again. This gate checks that chain evaluation
 * agrees with the ground truth (Node3D::getWorldMatrix(), walking from the scene root, independent of placeParts()
 * entirely) at random times, for a compound deep and branchy enough to exercise nested turning joints, a slider,
 * and some still (non-turning) branches alongside the turning ones.
 */
final class PlacePartsTest extends TestCase
{
    private function joint(Node3D $parent, float $x, float $y, float $z, array $axis, JointType $type = JointType::Hinge): Node3D
    {
        $joint = new Node3D();
        $joint->jointType = $type;
        [$joint->jointAxisX, $joint->jointAxisY, $joint->jointAxisZ] = $axis;
        $joint->jointMin = -100.0;
        $joint->jointMax = 100.0;   // wide enough that no limit fires during this test
        $joint->moveTo($x, $y, $z);
        $parent->addChild($joint);
        return $joint;
    }

    private function limb(Node3D $parent, float $x, float $y, float $z, float $radius = 0.4): Node3D
    {
        $limb = new Node3D();
        $limb->radius = $radius;
        $limb->mass = 1.0;
        $limb->moveTo($x, $y, $z);
        $parent->addChild($limb);
        return $limb;
    }

    public function testChainEvaluationAgreesWithGetWorldMatrixAtRandomTimes(): void
    {
        mt_srand(2026);
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(1000, 1000));
        $root = new Node3D();
        $root->confined = false;
        $root->moveTo(3.0, -1.0, 2.0);

        // a still branch: never touched again, must stay exactly where it was placed
        $this->limb($root, 5.0, 0.0, 0.0);

        // a turning branch: hinge -> hinge -> limb, both hinges spinning, different axes (nested turning)
        $hipAxis = [0.3, 1.0, -0.2];
        $hip = $this->joint($root, 1.0, 0.5, -0.3, $hipAxis);
        $this->limb($hip, 0.8, 0.0, 0.0);
        $kneeAxis = [-0.4, 0.2, 1.0];
        $knee = $this->joint($hip, 1.2, 0.0, 0.0, $kneeAxis);
        $this->limb($knee, 0.6, 0.1, -0.2);

        // a slider branch, also turning (translating)
        $slider = $this->joint($root, -0.5, 0.8, 0.4, [0.0, 0.0, 1.0], JointType::Slider);
        $slider->jointMin = -5.0;
        $slider->jointMax = 5.0;
        $this->limb($slider, 0.0, 0.0, 0.3);

        // a second, independent turning branch sharing the root but not the hip/knee chain
        $shoulder = $this->joint($root, -1.0, 0.6, 0.0, [1.0, 0.3, 0.0]);
        $this->limb($shoulder, -0.7, 0.2, 0.0);

        $parts = [];
        $walk = static function (Node3D $n) use (&$walk, &$parts) {
            if ($n->radius > 0.0) {
                $parts[] = $n;
            }
            foreach ($n->children as $c) {
                $walk($c);
            }
        };
        foreach ($root->children as $c) {
            $walk($c);
        }

        $root->compound = true;   // collectParts() scans descendants now, so they must all exist already
        $root->radius = 10.0;     // a compound only joins the physics space once it has a radius (see syncPhysics())
        $scene->root->addChild($root);
        $root->setVelocity(1.5, -0.5, 0.7);
        $root->spin(0.4, -0.6, 0.9);
        $hip->setJointRate(1.7);
        $knee->setJointRate(-2.3);
        $slider->setJointRate(0.9);
        $shoulder->setJointRate(-1.1);

        $space = $world->physics($scene);
        $maxError = 0.0;
        \phasync::run(static function () use ($world, $space, $root, $parts, &$maxError) {
            $world->run(static function (World $w) use ($space, $root, $parts, &$maxError) {
                for ($i = 0; $i < 40; ++$i) {
                    $w->tick(mt_rand(1, 50) / 1000);   // irregular steps: distinct query times, like real probes
                    $centres = $space->partCentres($root);
                    foreach ($parts as $k => $part) {
                        $m = $part->getWorldMatrix();   // ground truth: walks from the scene root independently
                        $dx = $centres[$k][0] - $m->m12;
                        $dy = $centres[$k][1] - $m->m13;
                        $dz = $centres[$k][2] - $m->m14;
                        $maxError = max($maxError, abs($dx), abs($dy), abs($dz));
                    }
                }
            });
        });
        $this->assertLessThan(1e-12, $maxError, 'placeParts() chain evaluation must agree with getWorldMatrix()');
        $world->dispose();
    }
}
