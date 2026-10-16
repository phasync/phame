<?php

namespace Phame\Tests\Verification;

use Phame\Physics\Contact;
use Phame\Physics\Space;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 baseline: limit cases worked out by hand (see docs/PHYSICS-PLAN.md, "Phase 0"). Do not modify src/ to make
 * a failing case here pass; a genuine baseline failure is kept, marked #[Group('baseline-failure')], and reported.
 */
final class EdgeCaseTest extends TestCase
{
    public function testAGrazingContactAtExactTangencyIsStillDetected(): void
    {
        // A at (-5, 1, 0) moving +1 along x, B at rest at the origin, both radius 0.5: the straight line y = 1 passes
        // the origin at exactly the sum of the radii, a tangent (the quadratic's discriminant is exactly 0)
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 0.5;
        $a->moveTo(-5.0, 1.0, 0.0);
        $a->setVelocity(1.0, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 0.5;
        $b->moveTo(0.0, 0.0, 0.0);
        $scene->root->addChild($b);
        $hits = [];
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            $hits[] = $c;
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(10.0)));
        $this->assertCount(1, $hits, 'a tangent still counts as a touch (the double root of the quadratic)');
        $this->assertEqualsWithDelta(5.0, $hits[0]->time, 1e-9);
        $this->assertEqualsWithDelta(0.0, $hits[0]->normalSpeed, 1e-9, 'at a pure tangent, nothing closes along the normal');
        $world->dispose();
    }

    public function testTwoIndependentPairsCollidingAtTheExactSameInstantAreBothHandled(): void
    {
        // (A, B) 8 apart closing at 2/s, (C, D) the same, far away on another axis: both touch at t = (8 - 0) / 2? no
        // -- centres 10 apart, radii 1 each (sum 2), closing at 2/s: t = (10 - 2) / 2 = 4, for both pairs at once
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 1.0;
        $a->moveTo(-5.0, 0.0, 0.0);
        $a->setVelocity(1.0, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 1.0;
        $b->moveTo(5.0, 0.0, 0.0);
        $b->setVelocity(-1.0, 0.0, 0.0);
        $scene->root->addChild($b);
        $c = new Node3D();
        $c->radius = 1.0;
        $c->moveTo(0.0, -5.0, 200.0);
        $c->setVelocity(0.0, 1.0, 0.0);
        $scene->root->addChild($c);
        $d = new Node3D();
        $d->radius = 1.0;
        $d->moveTo(0.0, 5.0, 200.0);
        $d->setVelocity(0.0, -1.0, 0.0);
        $scene->root->addChild($d);
        $hits = [];
        $world->onCollision(function (Node3D $x, Node3D $y, Contact $c) use (&$hits) {
            $hits[] = $c->time;
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(10.0)));
        $this->assertCount(2, $hits, 'both pairs are reported, not one lost to the other');
        $this->assertEqualsWithDelta([4.0, 4.0], $hits, 1e-9);
        $world->dispose();
    }

    public function testSpheresAlreadyTouchingAndApproachingDoNotFireAnImmediateContact(): void
    {
        // centres exactly 1 apart (the sum of the radii), still closing: Space::sphereImpact() treats this as
        // "a contact just handled that left them approaching" and returns INF -- documented, not a bug
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 0.5;
        $a->moveTo(-0.5, 0.0, 0.0);
        $a->setVelocity(1.0, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 0.5;
        $b->moveTo(0.5, 0.0, 0.0);
        $b->setVelocity(-1.0, 0.0, 0.0);
        $scene->root->addChild($b);
        $hits = 0;
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            ++$hits;
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(5.0)));
        $this->assertSame(0, $hits, 'already touching: no new contact until they separate first');
        $world->dispose();
    }

    public function testStationarySpheresTouchingAtRestNeverCollide(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 0.5;
        $a->moveTo(-0.5, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 0.5;
        $b->moveTo(0.5, 0.0, 0.0);
        $scene->root->addChild($b);
        $hits = 0;
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            ++$hits;
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(100.0)));
        $this->assertSame(0, $hits, 'zero relative velocity: never approaching, never fires');
        $world->dispose();
    }

    public function testContactTimeAtCoordinatesOfOneMillionMatchesTheClosedForm(): void
    {
        // the same head-on case as ClosedFormTest, shifted out to 1e6: centres close from 10 to 2 at 2/s, t = 4
        $offset = 1_000_000.0;
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $a = new Node3D();
        $a->radius = 1.0;
        $a->moveTo($offset - 5.0, 0.0, 0.0);
        $a->setVelocity(1.0, 0.0, 0.0);
        $scene->root->addChild($a);
        $b = new Node3D();
        $b->radius = 1.0;
        $b->moveTo($offset + 5.0, 0.0, 0.0);
        $b->setVelocity(-1.0, 0.0, 0.0);
        $scene->root->addChild($b);
        $hits = [];
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            $hits[] = $c->time;
        });
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(5.0)));
        $this->assertCount(1, $hits);
        // float64 near 1e6 carries about 10 significant digits: ~1e-4 absolute precision is the best to expect
        $this->assertEqualsWithDelta(4.0, $hits[0], 1e-3, 'large coordinates do not throw off the contact time');
        $world->dispose();
    }

    public function testABallBouncingForSimulatedHoursStaysOnTheClosedFormTrajectory(): void
    {
        // a ball between walls 100 apart at 1 unit/s turns every 100 s: few bounces across 3 simulated hours, so a
        // closed-form triangle wave (period 200 s) is cheap to check against, and the run itself stays fast
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [0.0, -10.0, -10.0];
        [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [100.0, 10.0, 10.0];
        // radius 0 would not join the physics space at all (Node3D::$radius: "0: no collisions"), so the travel
        // range between the walls is 100 - 2 * 0.5 = 99
        $ball = new Node3D();
        $ball->radius = 0.5;
        $ball->moveTo(0.5, 0.0, 0.0);
        $ball->setVelocity(1.0, 0.0, 0.0);
        $scene->root->addChild($ball);
        $world->onCollision(function (Node3D $rock, \Phame\Physics\Wall $wall, Contact $c) {
            $rock->velocityX = -$rock->velocityX;
        });
        $totalTime = 3.0 * 3600.0;
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick($totalTime)));
        // a triangle wave of period 198 and amplitude 99, starting at 0 (x = 0.5) going up
        $phase = fmod($totalTime, 198.0);
        $expected = 0.5 + ($phase <= 99.0 ? $phase : 198.0 - $phase);
        $this->assertEqualsWithDelta($expected, $ball->x, 1e-6, 'no drift over three simulated hours of bouncing');
        $world->dispose();
    }

    #[Group('baseline-failure')]
    public function testAHeadOnApproachToACompoundPartIsReported(): void
    {
        // BASELINE FAILURE (today's engine, not modified to fix it -- see docs/PHYSICS-PLAN.md Phase 0): a compound
        // moving at a constant velocity, not spinning, with a part dead-centre in the path of an incoming body at a
        // constant relative speed. Space::compoundTime()'s step formula, ($gap - PROBE_TOLERANCE) / $speed, predicts
        // exactly when the gap will equal PROBE_TOLERANCE -- normally a slight overestimate of the true closing
        // speed leaves slack, so the next probe lands inside Space::touching()'s open window
        // (PROBE_TOLERANCE, 2*PROBE_TOLERANCE]. Here the bound is exact (no rotation, a straight line, no second
        // velocity component to pad it), so the probe lands exactly ON the excluded boundary (gap == tolerance),
        // touching() rejects it (<=), and then PROBE_PAUSE-sized steps (at this speed, 2x PROBE_TOLERANCE per step)
        // jump clean over the open window. The bodies pass straight through one another, unreported, which is a
        // reliability hole in event-driven compound detection -- confirmed by hand: the two spheres' centres
        // coincide exactly at t = 5 (see the arithmetic below), yet no contact is ever raised.
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $root = new Node3D();
        $root->compound = true;
        $root->radius = 5.0;
        $root->setVelocity(2.0, 0.0, 0.0);
        $part = new Node3D();
        $part->radius = 0.5;
        $part->moveTo(3.0, 0.0, 0.0);
        $root->addChild($part);
        $scene->root->addChild($root);
        $rock = new Node3D();
        $rock->radius = 0.5;
        $rock->moveTo(3.0, 10.0, 0.0);      // same x as the part, and the same x velocity: a dead-centre approach
        $rock->setVelocity(2.0, -2.0, 0.0);
        $scene->root->addChild($rock);
        $hits = [];
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$hits) {
            $hits[] = $c->time;
        });
        \phasync::run(static function () use ($world, &$hits) {
            $world->run(static function (World $w) use (&$hits) {
                while ($hits === [] && $w->clock->time < 8.0) {
                    $w->tick(0.5);
                }
            });
        });
        // by hand: rock->y(t) = 10 - 2t reaches the part's y = 0 at t = 5, where rock->x(t) = 3 + 2t = part->x(t) =
        // 2t + 3 for every t -- the centres coincide exactly at t = 5, well inside Space::PROBE_TOLERANCE * 2 on
        // either side; today's engine reports nothing
        // the surfaces meet when the centres are 1 apart: 10 - 2t = 1 at t = 4.5 (within the probe tolerance)
        $this->assertCount(1, $hits, 'the head-on approach must be reported');
        $this->assertEqualsWithDelta(4.5, $hits[0], 0.02);
        $world->dispose();
    }
}
