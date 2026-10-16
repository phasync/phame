<?php

namespace Phame\Tests;

use Phame\Math\{Matrix4, Vector3};
use Phame\World;
use Phame\Scene\Node3D;
use PHPUnit\Framework\TestCase;

/** Node3D orientation: a quaternion plus an angular velocity, exact for any axis. No engine needed. */
final class OrientationTest extends TestCase
{
    public function testEulerAnglesRoundTripAndMatchCompose(): void
    {
        $n = new Node3D();
        $n->rotationX = 0.3;
        $n->rotationY = -1.1;
        $n->rotationZ = 0.7;
        $this->assertEqualsWithDelta([0.3, -1.1, 0.7], [$n->rotationX, $n->rotationY, $n->rotationZ], 1e-9);
        $expected = Matrix4::get()->compose(0, 0, 0, 0.3, -1.1, 0.7, 1, 1, 1);
        $m = $n->getLocalMatrix();
        foreach (['m0', 'm1', 'm2', 'm4', 'm5', 'm6', 'm8', 'm9', 'm10'] as $k) {
            $this->assertEqualsWithDelta($expected->$k, $m->$k, 1e-9, $k);
        }
        $expected->release();
    }

    public function testSpinAboutADiagonalAxisIsExact(): void
    {
        $world = new World();
        $node = new Node3D();
        $node->attachClock($world->clock);
        $axis = 1 / sqrt(2);
        $node->spin($axis * M_PI, $axis * M_PI, 0.0);   // half a turn per second about (1, 1, 0)
        $world->clock->tick(1.0);
        $p = $node->getLocalMatrix()->transformPoint(Vector3::get(1, 0, 0));
        $this->assertEqualsWithDelta([0.0, 1.0, 0.0], [$p->x, $p->y, $p->z], 1e-9, '+x turned half a circle about (1,1,0) is +y');
        $p->release();
        $world->dispose();
    }

    public function testChangingTheDescriptionMidSpinKeepsTheOrientation(): void
    {
        $world = new World();
        $node = new Node3D();
        $node->attachClock($world->clock);
        $node->spin(0, M_PI / 2, 0);   // a quarter turn per second about y
        $world->clock->tick(0.5);
        $before = [$node->orientationX, $node->orientationY, $node->orientationZ, $node->orientationW];
        $node->setVelocity(1, 0, 0);   // rebases: the orientation must not jump
        $this->assertEqualsWithDelta($before, [$node->orientationX, $node->orientationY, $node->orientationZ, $node->orientationW], 1e-12);
        $world->clock->tick(0.5);
        $this->assertEqualsWithDelta(M_PI / 2, $node->rotationY, 1e-9, 'a quarter turn after one second in all');
        $world->dispose();
    }

    /** Regression: describe() rebased to "now", so browsers switched paths at the publishing time, not the event's. */
    public function testDescriptionCarriesTheTimeOfItsLastChange(): void
    {
        $world = new World();
        $node = new Node3D();
        $node->attachClock($world->clock);
        $world->clock->tick(0.3);
        $node->setVelocity(1, 0, 0);
        $world->clock->tick(0.2);
        $d = $node->describe();
        $this->assertEqualsWithDelta(0.3, $d[0], 1e-12, 't0 is when the velocity changed');
        $this->assertEqualsWithDelta(0.0, $d[1], 1e-12, 'x0 is where it was then');
        $this->assertEqualsWithDelta(0.2, $node->x, 1e-12, 'evaluated now it has moved on');
        $world->dispose();
    }

    public function testADisposedTreeIsFreedWithoutTheCycleCollector(): void
    {
        // children know their parent and parents their children: a removed tree is a cycle, left to the collector.
        // Taken apart by its owner, it goes the moment the last reference does
        $world = new \Phame\World();
        $scene = $world->stage->addChild(new \Phame\Scene\Scene3D(100, 100));
        $root = new Node3D();
        $joint = $root->addChild(new Node3D());
        $joint->addChild(new Node3D());
        $scene->root->addChild($root);
        $weak = \WeakReference::create($joint);
        gc_disable();
        try {
            $root->dispose();
            unset($root, $joint);
            $this->assertNull($weak->get(), 'freed by its reference count alone');
        } finally {
            gc_enable();
        }
        $world->dispose();
    }
}
