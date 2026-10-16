<?php

namespace Phame\Tests\Verification;

use Phame\Physics\Contact;
use Phame\Physics\Wall;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\Tests\Verification\Oracle\Oracle;
use Phame\Tests\Verification\Oracle\SceneSpec;
use Phame\World;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 gate tests (see docs/PHYSICS-PLAN.md, "Phase 1"): acceleration in descriptions, a validity end, gravity as
 * a frame field. Written against the agreed API before the engine side lands (Node3D::setAcceleration(), a
 * $validUntil property with an 'expire' event, describe() growing three floats): every test here is expected to
 * fail - loudly, with an undefined-method error, not a wrong assertion - until it does. Run with --group phase1 once
 * told the engine side is ready; phpunit.xml excludes the group from the default run until then.
 *
 * Oracle/SceneSpec support for Phase 1 (constant acceleration on a body or node, a node's own constant velocity, the
 * findCrossing() speed bound widened by |Δv| + |Δa|·width - see Oracle\Oracle) is already in place and covered by
 * the Phase-0-only tests elsewhere in this directory (acceleration defaults to 0.0 everywhere there, so nothing
 * there depends on the engine side at all).
 */
#[Group('phase1')]
final class PhaseOneTest extends TestCase
{
    private const TIME_EPS = 1e-4;
    private const POS_EPS = 1e-4;
    /** A compound part's contact is reported inside the probe's zone, (1, 2]·PROBE_TOLERANCE from touching: up to
     * 2·0.02 / closing speed early (about 1 ms at the falling container's 39 units/s), until Phase 3 makes it exact. */
    private const PART_TIME_EPS = 0.002;
    /** A sanity cap for the hand-built scenes below (Harness::run()'s own tests already guard its two handlers): past this, a regression is re-triggering the same contact forever, and the test must fail cleanly instead of hanging. */
    private const MAX_EVENTS = 2000;

    private static function guard(array $list): void
    {
        if (count($list) >= self::MAX_EVENTS) {
            throw new \RuntimeException('more than ' . self::MAX_EVENTS . ' contacts: likely the same one re-triggering forever, not a real scene');
        }
    }

    public function testAProjectilesApexAndLandingTimeMatchTheClosedForm(): void
    {
        $g = 9.8;
        [$vx, $vy] = [10.0, 20.0];
        $apex = $vy / $g;
        $apexHeight = $vy * $vy / (2.0 * $g);

        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $scene->worldMinY = 0.0;
        $ball = new Node3D();
        $ball->radius = 1.0;
        $ball->moveTo(0.0, 0.0, 0.0);
        $ball->setVelocity($vx, $vy, 0.0);
        $ball->setAcceleration(0.0, -$g, 0.0);
        $scene->root->addChild($ball);
        $landed = [];
        $world->onCollision(function (Node3D $rock, Wall $wall, Contact $c) use (&$landed) {
            self::guard($landed);
            $landed[] = $c->time;
            $rock->velocityY = -$rock->velocityY;   // else it stays pressed into the floor: an immediate, endless re-contact
        });
        $apexSeen = null;
        \phasync::run(static function () use ($world, $ball, $apex, &$apexSeen) {
            $world->run(static function (World $w) use ($ball, $apex, &$apexSeen) {
                while ($w->clock->time < $apex) {
                    $w->tick(min(0.001, $apex - $w->clock->time));
                }
                $apexSeen = $ball->y;
            });
        });
        $this->assertEqualsWithDelta($apexHeight, $apexSeen, 1e-3, 'apex height = v_y² / 2g');
        \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(4.5)));   // well before the next, identical-period bounce
        $this->assertCount(1, $landed);
        // landing: its centre reaches r (the floor's radius-away height): v_y*t - ½g t² = r, later root
        $landingT = ($vy + sqrt($vy * $vy - 2.0 * $g * 1.0)) / $g;
        $this->assertEqualsWithDelta($landingT, $landed[0], 1e-6);
        $world->dispose();
    }

    /** A ball dropped onto a floor under gravity, bouncing with restitution e: each bounce's speed is e times the last, so each peak height is e² times the last (h·e^(2n)). */
    public function testABallBouncingUnderGravityHasHeightsScaledByRestitutionSquared(): void
    {
        $g = 9.8;
        $e = 0.6;
        $r = 0.5;
        $h0 = 10.0;   // the centre starts this high: it falls h0 - r before the first impact

        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $scene->worldMinY = 0.0;
        $ball = new Node3D();
        $ball->radius = $r;
        $ball->moveTo(0.0, $h0, 0.0);
        $ball->setAcceleration(0.0, -$g, 0.0);
        $scene->root->addChild($ball);
        $speeds = [];
        $world->onCollision(function (Node3D $rock, Wall $wall, Contact $c) use (&$speeds, $e) {
            self::guard($speeds);
            $speeds[] = -$rock->velocityY;   // the impact speed, downward, before the bounce
            $rock->velocityY = -$e * $rock->velocityY;
        });
        // restitution < 1 means infinitely many, ever-faster bounces genuinely pile up towards a finite time (an
        // inelastic ball under gravity bounces infinitely often before resting - not an engine bug, and not
        // something to dodge with a cap: resting contact is Phase 4, so this is a real Zeno tail on purpose). The
        // accumulation point is known in closed form: first impact at t1 = sqrt(2(h0-r)/g), speed v1 = g*t1; each
        // later bounce interval is e times the one before it, starting at T1 = 2*e*v1/g, so they sum to T1/(1-e)
        // after t1. Run to 90% of that - short of the pile-up, past several bounces - and check the heights,
        // and that there really were that many.
        $t1 = sqrt(2.0 * ($h0 - $r) / $g);
        $v1 = $g * $t1;
        $restAt = $t1 + (2.0 * $e * $v1 / $g) / (1.0 - $e);
        $until = $t1 + 0.9 * ($restAt - $t1);
        \phasync::run(static function () use ($world, $until) {
            $world->run(static function (World $w) use ($until) {
                while ($w->clock->time < $until - 1e-9) {
                    $w->tick(min(0.01, $until - $w->clock->time));
                }
            });
        });
        // the fraction of the tail (from t1 to restAt) left after N bounces is e^N: at 90% consumed, N = ln(0.1)/ln(e)
        $expectedBounces = (int) floor(log(0.1) / log($e));
        $this->assertGreaterThanOrEqual($expectedBounces, count($speeds), 'as many bounces as the closed form predicts by 90% of the way to resting');
        $this->assertEqualsWithDelta($v1, $speeds[0], 1e-6, 'first impact speed: free fall from h0 - r');
        for ($n = 1; $n < $expectedBounces; ++$n) {
            $this->assertEqualsWithDelta($e * $speeds[$n - 1], $speeds[$n], 1e-6, "bounce {$n}: speed scales by e, so height by e²");
        }
        $world->dispose();
    }

    /** Two bodies under the same acceleration collide exactly when they would without it: gravity cancels in their relative motion (the falling frame). */
    public function testTwoBodiesUnderTheSameGravityCollideAtTheSameTimeAsWithoutGravity(): void
    {
        $collide = function (float $ax, float $ay, float $az): float {
            $world = new World();
            $scene = $world->stage->addChild(new Scene3D(100, 100));
            $a = new Node3D();
            $a->radius = 1.0;
            $a->moveTo(-5.0, 0.0, 0.0);
            $a->setVelocity(1.0, 0.0, 0.0);
            $a->setAcceleration($ax, $ay, $az);
            $scene->root->addChild($a);
            $b = new Node3D();
            $b->radius = 1.0;
            $b->moveTo(5.0, 0.0, 0.0);
            $b->setVelocity(-1.0, 0.0, 0.0);
            $b->setAcceleration($ax, $ay, $az);
            $scene->root->addChild($b);
            $time = null;
            $hits = [];
            $world->onCollision(function (Node3D $x, Node3D $y, Contact $c) use (&$time, &$hits) {
                self::guard($hits);
                $hits[] = $c->time;
                $time ??= $c->time;
            });
            \phasync::run(static fn () => $world->run(static fn (World $w) => $w->tick(10.0)));
            $world->dispose();
            return $time;
        };
        $withoutGravity = $collide(0.0, 0.0, 0.0);
        $withGravity = $collide(0.0, -30.0, 5.0);   // an arbitrary, not purely-downward, shared acceleration
        $this->assertEqualsWithDelta(4.0, $withoutGravity, 1e-9, 'closes at the combined speed over the gap: (10 - 2) / 2');
        $this->assertEqualsWithDelta($withoutGravity, $withGravity, 1e-6, 'the same acceleration on both cancels out of their relative motion');
    }

    /**
     * Validity is local (docs/PHYSICS-PLAN.md, "Phase 1" gate): stressing a parent - accelerating, spinning - never
     * moves a child's own validUntil, and 'expire' fires there exactly once, at the first tick at or after it (not
     * something World::advance() travels the clock back to exactly, unlike a physics contact: validUntil only needs
     * a handler to refit the description by then).
     */
    public function testValidUntilIsLocalToTheNodeItIsSetOnAndExpireFiresOnce(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $parent = new Node3D();
        $scene->root->addChild($parent);
        $child = new Node3D();
        $parent->addChild($child);
        $child->validUntil = 2.0;
        $expired = [];
        $child->addEventListener('expire', function () use (&$expired, $world) {
            $expired[] = $world->clock->time;
        });
        // snapshots of the child's validUntil after every tick: static closures (World::run()'s convention
        // throughout this suite) cannot use $this, so these are checked below, once the loop is over
        $seen = [];
        \phasync::run(static function () use ($world, $parent, $child, &$seen) {
            $world->run(static function (World $w) use ($parent, $child, &$seen) {
                $t = 0.0;
                $i = 0;
                while ($t < 5.0) {
                    $step = min(0.013, 5.0 - $t);
                    $w->tick($step);
                    $t += $step;
                    // stress the parent hard, every tick: none of this may touch the child's own validUntil
                    $parent->setVelocity(sin($i) * 50.0, cos($i) * 50.0, 0.0);
                    $parent->spin(0.0, 0.0, 30.0 + $i);
                    $parent->setAcceleration(cos($i) * 200.0, sin($i) * 200.0, 0.0);
                    ++$i;
                    $seen[] = $child->validUntil;
                }
            });
        });
        // validUntil resets to INF once it fires (World::expiryChanged()): local to the child either way, it is
        // never anything else, and never moved or re-armed by anything the parent did
        foreach ($seen as $v) {
            $this->assertTrue($v === 2.0 || $v === INF, 'a parent\'s own motion never changes a child\'s validUntil');
        }
        $this->assertCount(1, $expired, 'expire fires exactly once');
        // not a physics event the clock travels back to exactly, just the first tick at or after validUntil
        // (World::advance(): validUntil is a "refit by then" marker, not something needing sub-tick precision)
        $this->assertGreaterThanOrEqual(2.0, $expired[0]);
        $this->assertLessThan(2.0 + 0.013 + 1e-9, $expired[0], 'no later than one tick past validUntil');
        $world->dispose();
    }

    /**
     * Gravity set on an ancestor (here, a compound acting as a falling container) reaches a part under it exactly as
     * the oracle predicts, without the part needing any acceleration of its own.
     */
    public function testGravityOnAFallingContainerReachesItsPartLikeTheOracle(): void
    {
        $g = 9.8;
        $spec = new SceneSpec();
        $container = $spec->addBody(0.0, 20.0, 0.0, 0.0, 0.0, 0.0, 5.0, 10.0, false, true, 0.0, 0.0, 0.0, 0.0, -$g, 0.0);
        $spec->addNode(true, $container, 3.0, 0.0, 0.0, 0.5);   // the part: no velocity or acceleration of its own
        // a free body on a straight line (no gravity of its own), timed to meet the falling part's x = 3 at t = 2
        $meetAt = 2.0;
        $partY = 20.0 - 0.5 * $g * $meetAt * $meetAt;   // where the (falling) part is then
        $projectileVY = 20.0;
        $spec->addBody(3.0, $partY - $projectileVY * $meetAt, 0.0, 0.0, $projectileVY, 0.0, 0.3, 1.0, false, false);
        $this->assertAgreesWithOracle($spec, 3.0, self::PART_TIME_EPS, self::POS_EPS, 'falling container', partContacts: true);
    }

    /** Two free bodies under different constant accelerations (one falling, one not) agree with the oracle. */
    public function testPairsWithOneBodyFallingAndOneNotAgreeWithTheOracle(): void
    {
        $spec = new SceneSpec();
        $spec->addBody(-10.0, 10.0, 0.0, 2.0, 0.0, 0.0, 0.5, 1.0, false, false);
        $spec->addBody(10.0, 10.0, 0.0, -2.0, 0.0, 0.0, 0.5, 1.0, false, false, 0.0, 0.0, 0.0, 0.0, -9.8, 0.0);
        $this->assertAgreesWithOracle($spec, 3.0, self::TIME_EPS, self::POS_EPS, 'one falling, one not');
    }

    /** Two free bodies under opposite constant accelerations agree with the oracle. */
    public function testPairsWithOppositeAccelerationsAgreeWithTheOracle(): void
    {
        $spec = new SceneSpec();
        $spec->addBody(-10.0, 0.0, 0.0, 2.0, 0.0, 0.0, 0.5, 1.0, false, false, 0.0, 0.0, 0.0, 0.0, 5.0, 0.0);
        $spec->addBody(10.0, 0.0, 0.0, -2.0, 0.0, 0.0, 0.5, 1.0, false, false, 0.0, 0.0, 0.0, 0.0, -5.0, 0.0);
        $this->assertAgreesWithOracle($spec, 3.0, self::TIME_EPS, self::POS_EPS, 'opposite accelerations');
    }

    /**
     * A near-graze between two differently-accelerated bodies, hand-built close to a true double root: along x, A
     * moves at a constant velocity and B's x-acceleration differs from A's, so their relative x position is a
     * parabola in t with a vertex (relative x velocity exactly 0) that this places at relative x = 0, by choosing
     * the starting separation. An exact double root - the constant transverse (y) offset set to exactly the sum of
     * the radii, so the spheres are exactly tangent at the vertex and never actually overlap - is a measure-zero
     * case that is ill-conditioned for any numerical method (the gap touches 0 but a sign change, which is what
     * both this oracle and a plain root search look for, may never be observed in floating point): offsetting the
     * transverse distance a hair under the radius sum keeps the double root's numerical character (a very low
     * effective closing rate, almost all of the relative speed tangential) while making it an unambiguous, if
     * brief, real contact.
     */
    public function testANearGrazeBetweenDifferentlyAcceleratedBodiesWithADoubleRootAgreesWithTheOracle(): void
    {
        [$vA, $vB, $aA, $aB] = [10.0, -10.0, 0.0, 3.0];
        $relV = $vB - $vA;
        $relA = $aB - $aA;
        $tStar = -$relV / $relA;   // the vertex of the relative x parabola: 20 / 3
        $x0 = 0.5 * $relV * $relV / $relA;   // so relative x is exactly 0 there (x0B - x0A)
        $r = 0.5;
        $spec = new SceneSpec();
        $spec->addBody(0.0, 0.0, 0.0, $vA, 0.0, 0.0, $r, 1.0, false, false, 0.0, 0.0, 0.0, $aA, 0.0, 0.0);
        $spec->addBody($x0, 2.0 * $r * 0.999, 0.0, $vB, 0.0, 0.0, $r, 1.0, false, false, 0.0, 0.0, 0.0, $aB, 0.0, 0.0);
        // the oracle alone, first: confirm the hand-built near-graze really is one (a single contact near t*)
        $oracle = new Oracle($spec);
        $oracle->run(0.01, $tStar + 5.0);
        $this->assertCount(1, $oracle->events, 'the hand-built near-graze is a real, single contact');
        $this->assertEqualsWithDelta($tStar, $oracle->events[0]['time'], 0.2, 'near the parabola\'s vertex');
        $this->assertAgreesWithOracle($spec, $tStar + 5.0, self::TIME_EPS, self::POS_EPS, 'near-graze close to a double root');
    }

    /** Gravity set once on the scene root and the same acceleration set on every body give identical trajectories and contacts (both checked against the same oracle). */
    public function testGravityOnTheRootAndTheSameAccelerationOnEveryBodyAreIdentical(): void
    {
        $spec = SceneGenerator::spheresInBox(21, 6, 10.0, 9.8);
        $until = 4.0;
        $dt = 0.0131;
        $oracle = new Oracle($spec);
        $oracle->run($dt, $until);

        $perBody = Harness::run($spec, $until, 0.047);
        $cmpPerBody = Harness::compareEvents($perBody['events'], $oracle->events, self::TIME_EPS);
        $this->assertSame([], $cmpPerBody['missing']);
        $this->assertSame([], $cmpPerBody['extra']);

        $zeroed = clone $spec;
        $zeroed->bodyAX = array_fill(0, count($spec->bodyX), 0.0);
        $zeroed->bodyAY = array_fill(0, count($spec->bodyX), 0.0);
        $zeroed->bodyAZ = array_fill(0, count($spec->bodyX), 0.0);
        $onRoot = Harness::run($zeroed, $until, 0.047, static function (Scene3D $scene) {
            // gravityX/Y/Z, not setAcceleration(): a field every descendant inherits, not the root's own motion
            // (Space predicts a direct child from its own local description, never composing the root's transform,
            // so setAcceleration() on the root would not reach the bodies the way this dedicated field does)
            $scene->root->gravityY = -9.8;
        });
        $cmpOnRoot = Harness::compareEvents($onRoot['events'], $oracle->events, self::TIME_EPS);
        $this->assertSame([], $cmpOnRoot['missing'], 'gravity on the root alone finds every contact the oracle does');
        $this->assertSame([], $cmpOnRoot['extra'], 'and no others');
        $this->assertLessThanOrEqual(self::POS_EPS, Harness::worstPositionError($onRoot['samples'], $oracle));
    }

    /** The box and open-world generators with gravity added (SceneGenerator::spheresInBox()/openWorld()'s $gravity). */
    public function testGravityAddedToTheBoxAndOpenWorldGeneratorsAgreesWithTheOracle(): void
    {
        $this->assertAgreesWithOracle(SceneGenerator::spheresInBox(31, 8, 10.0, 9.8), 4.0, self::TIME_EPS, self::POS_EPS, 'box with gravity');
        $this->assertAgreesWithOracle(SceneGenerator::openWorld(31, 6, 2000.0, 2, 9.8), 2.0, self::TIME_EPS, self::POS_EPS, 'open world with gravity');
    }

    private function assertAgreesWithOracle(SceneSpec $spec, float $until, float $timeEps, float $posEps, string $label = '', float $dt = 0.0131, bool $partContacts = false): void
    {
        $engine = Harness::run($spec, $until, 0.047);
        $oracle = new Oracle($spec);
        $oracle->run($dt, $until);
        $notAtTheEdge = static fn (array $e) => $e['time'] < $until - 1e-6;
        $cmp = Harness::compareEvents(array_values(array_filter($engine['events'], $notAtTheEdge)), array_values(array_filter($oracle->events, $notAtTheEdge)), $timeEps);
        $this->assertSame([], $cmp['missing'], "contacts the oracle saw but the engine did not $label: " . json_encode($cmp['missing']));
        $this->assertSame([], $cmp['extra'], "contacts the engine reported that the oracle did not $label: " . json_encode($cmp['extra']));
        $this->assertLessThanOrEqual($timeEps, $cmp['worstTimeError'], "contact time $label");
        $samples = $engine['samples'];
        if ($partContacts && $oracle->events !== []) {
            // a part contact is reported up to PART_TIME_EPS early, so the states after it differ by design: compare
            // positions only before the first one, as VerificationTest does
            $firstContact = min(array_column($oracle->events, 'time'));
            $samples = array_filter($samples, static fn ($s) => $s[0] < $firstContact - self::PART_TIME_EPS);
        }
        $posError = Harness::worstPositionError($samples, $oracle);
        $this->assertLessThanOrEqual($posEps, $posError, "position $label");
    }
}
