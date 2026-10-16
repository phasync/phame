<?php

namespace Phame\Tests\Verification;

use Phame\Physics\Contact;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 baseline: today's engine checked against exact answers worked out by hand (textbook rigid-body mechanics),
 * not against the engine's own formulas. See docs/PHYSICS-PLAN.md, "Phase 0".
 */
final class ClosedFormTest extends TestCase
{
    public function testHeadOnCollisionOfUnequalMassesMatchesTheTextbookFormula(): void
    {
        // A (m=2) at x=-5 moving +1, B (m=1) at x=5 moving -1, both radius 1: centres close from 10 to 2 at a
        // combined closing speed of 2, so contact at t = (10 - 2) / 2 = 4
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->mass = 2.0;
        $a->radius = 1.0;
        $a->moveTo(-5.0, 0.0, 0.0);
        $a->setVelocity(1.0, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->mass = 1.0;
        $b->radius = 1.0;
        $b->moveTo(5.0, 0.0, 0.0);
        $b->setVelocity(-1.0, 0.0, 0.0);
        $scene->root->addChild($b);
        $hits = 0;
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            ++$hits;
            $this->assertEqualsWithDelta(4.0, $c->time, 1e-9, 'closes at the combined speed over the gap');
            $this->assertEqualsWithDelta([1.0, 0.0, 0.0], [$c->nx, $c->ny, $c->nz], 1e-9, 'head-on: the normal is along x');
            $this->assertEqualsWithDelta(-2.0, $c->normalSpeed, 1e-9, 'approaching at the sum of the speeds');
            // textbook 1D elastic collision, not the engine's Contact::impulse(): v1' = v1 - 2*m2/(m1+m2)*(v1-v2)
            [$v1, $v2] = [1.0, -1.0];
            [$m1, $m2] = [2.0, 1.0];
            $v1p = $v1 - 2.0 * $m2 / ($m1 + $m2) * ($v1 - $v2);
            $v2p = $v2 - 2.0 * $m1 / ($m1 + $m2) * ($v2 - $v1);
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $a->setVelocity($a->velocityX + $k * $c->nx, 0.0, 0.0);
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $b->setVelocity($b->velocityX + $k * $c->nx, 0.0, 0.0);
            $this->assertEqualsWithDelta($v1p, $a->velocityX, 1e-9, '-1/3 by hand');
            $this->assertEqualsWithDelta($v2p, $b->velocityX, 1e-9, '5/3 by hand');
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(5.0)));
        $this->assertSame(1, $hits);
        $world->dispose();
    }

    public function testEqualMassesHeadOnElasticFullySwapVelocities(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 0.5;
        $a->moveTo(-10.0, 0.0, 0.0);
        $a->setVelocity(3.0, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 0.5;
        $b->moveTo(10.0, 0.0, 0.0);
        $b->setVelocity(-2.0, 0.0, 0.0);
        $scene->root->addChild($b);
        $hits = 0;
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            ++$hits;
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $av = $a->velocityX + $k * $c->nx;
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $bv = $b->velocityX + $k * $c->nx;
            $this->assertEqualsWithDelta(-2.0, $av, 1e-9, 'equal masses: A takes B\'s velocity');
            $this->assertEqualsWithDelta(3.0, $bv, 1e-9, 'equal masses: B takes A\'s velocity');
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(5.0)));
        $this->assertSame(1, $hits);
        $world->dispose();
    }

    public function testGlancingCollisionMatchesAnIndependentlySolvedQuadratic(): void
    {
        // A at (-5, 0.3, 0) moving +1 along x, B at rest at the origin, both radius 0.5: they touch (centres 1
        // apart) when sqrt(dx^2 + 0.3^2) = 1, i.e. dx = sqrt(1 - 0.09); solved directly, not via Space's formula
        $dx = sqrt(1.0 - 0.3 ** 2);
        $txA = -$dx; // A's x at contact
        $t = ($txA - (-5.0)) / 1.0;
        $nx = ($dx) / 1.0;   // normal from A to B = (0 - txA, 0 - 0.3, 0) / distance(=1)
        $ny = -0.3 / 1.0;
        $expectedNormalSpeed = (0.0 - 1.0) * $nx + 0.0 * $ny;   // (vB - vA) . n

        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 0.5;
        $a->moveTo(-5.0, 0.3, 0.0);
        $a->setVelocity(1.0, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 0.5;
        $b->moveTo(0.0, 0.0, 0.0);
        $scene->root->addChild($b);
        $hits = 0;
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits, $t, $nx, $ny, $expectedNormalSpeed) {
            ++$hits;
            $this->assertEqualsWithDelta($t, $c->time, 1e-9);
            $this->assertEqualsWithDelta($nx, $c->nx, 1e-9);
            $this->assertEqualsWithDelta($ny, $c->ny, 1e-9);
            $this->assertEqualsWithDelta($expectedNormalSpeed, $c->normalSpeed, 1e-9);
            // equal masses, elastic, oblique: momentum and energy must still hold with the engine's own impulse
            $pxBefore = $a->mass * $a->velocityX + $b->mass * $b->velocityX;
            $eBefore = 0.5 * $a->mass * $a->velocityX ** 2 + 0.5 * $b->mass * $b->velocityX ** 2;
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $a->setVelocity($a->velocityX + $k * $c->nx, $a->velocityY + $k * $c->ny, 0.0);
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $b->setVelocity($b->velocityX + $k * $c->nx, $b->velocityY + $k * $c->ny, 0.0);
            $pxAfter = $a->mass * $a->velocityX + $b->mass * $b->velocityX;
            $eAfter = 0.5 * $a->mass * ($a->velocityX ** 2 + $a->velocityY ** 2) + 0.5 * $b->mass * ($b->velocityX ** 2 + $b->velocityY ** 2);
            $this->assertEqualsWithDelta($pxBefore, $pxAfter, 1e-9);
            $this->assertEqualsWithDelta($eBefore, $eAfter, 1e-9);
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(10.0)));
        $this->assertSame(1, $hits);
        $world->dispose();
    }

    public function testWallBounceTimeIsDistanceOverSpeed(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-10.0, -10.0, -10.0];
        [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [10.0, 10.0, 10.0];
        $ball = new Node3D();
        $ball->radius = 1.0;
        $ball->moveTo(0.0, 0.0, 0.0);
        $ball->setVelocity(5.0, 0.0, 0.0);
        $scene->root->addChild($ball);
        $hits = [];
        $world->onCollision(function (Node3D $rock, \Phame\Physics\Wall $wall, Contact $c) use (&$hits) {
            $hits[] = $c;
            $rock->velocityX = -$rock->velocityX;   // mirror it off, or it keeps "arriving" at the same wall
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(3.0)));
        $this->assertCount(1, $hits);
        $this->assertEqualsWithDelta((10.0 - 1.0) / 5.0, $hits[0]->time, 1e-9, 'reaches x = max - radius at v = 5');
        $this->assertSame(0, $hits[0]->b->axis);
        $this->assertSame(1, $hits[0]->b->side);
        $world->dispose();
    }

    public function testTetherTautTimeMatchesAnIndependentlySolvedQuadratic(): void
    {
        // A at -1 drifting at -0.5, B at +1 drifting at +0.5: 2 apart now, separating at 1 unit/s, so the slack
        // (length 4) runs out at t = (4 - 2) / 1 = 2
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 0.1;
        $a->moveTo(-1.0, 0.0, 0.0);
        $a->setVelocity(-0.5, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 0.1;
        $b->moveTo(1.0, 0.0, 0.0);
        $b->setVelocity(0.5, 0.0, 0.0);
        $scene->root->addChild($b);
        $world->physics($scene)->tether($a, $b, 4.0);
        $hits = 0;
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            ++$hits;
            $this->assertTrue($c->tether);
            $this->assertEqualsWithDelta(2.0, $c->time, 1e-9, 'gap(0) = 2, separating at 1/s, slack of 2 to go');
            $k = $c->sign($a) * $c->impulse(1.0) / $a->mass;
            $a->setVelocity($a->velocityX + $k * $c->nx, 0.0, 0.0);
            $k = $c->sign($b) * $c->impulse(1.0) / $b->mass;
            $b->setVelocity($b->velocityX + $k * $c->nx, 0.0, 0.0);
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(5.0)));
        $this->assertSame(1, $hits);
        $world->dispose();
    }

    public function testPointOnATurningNodeFollowsTheExactCircle(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $pivot = new Node3D();
        $pivot->spin(0.0, 0.0, 1.0);   // 1 rad/s about z
        $scene->root->addChild($pivot);
        $arm = new Node3D();
        $arm->moveTo(4.0, 0.0, 0.0);
        $pivot->addChild($arm);
        \phasync::run(function () use ($world, $arm) {
            $world->run(function (World $w) use ($arm) {
                for ($i = 0; $i < 50; ++$i) {
                    $w->tick(0.037);   // an irregular step, so the check is at arbitrary times, not just whole seconds
                    $t = $w->clock->time;
                    $m = $arm->getWorldMatrix();
                    $this->exactCircle($t, $m);
                }
            });
        });
        $world->dispose();
    }

    private function exactCircle(float $t, \Phame\Math\Matrix4 $m): void
    {
        $this->assertEqualsWithDelta(4.0 * cos($t), $m->m12, 1e-9);
        $this->assertEqualsWithDelta(4.0 * sin($t), $m->m13, 1e-9);
        $this->assertEqualsWithDelta(0.0, $m->m14, 1e-9);
    }
}
