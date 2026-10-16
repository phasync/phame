<?php

namespace Phame\Tests\Verification;

use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\Tests\Verification\Oracle\Oracle;
use Phame\Tests\Verification\Oracle\SceneSpec;
use Phame\World;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 baseline: today's engine checked against an independent oracle (see docs/PHYSICS-PLAN.md, "Phase 0", and
 * docs/VERIFICATION.md for the error budgets used here). Every contact the oracle sees must show up in the engine at
 * the same time, with no extras, and sampled positions must agree.
 */
final class VerificationTest extends TestCase
{
    // free spheres and tethers are exact closed-form contacts on both sides: a tight budget
    private const TIME_EPS = 1e-4;
    private const POS_EPS = 1e-4;
    // a compound's part contacts are only exact to Space::PROBE_TOLERANCE (0.02), so the budget is that over the
    // slowest closing speed in these scenes (a few units/second): see docs/VERIFICATION.md
    private const PART_TIME_EPS = 0.05;

    public function testRandomSpheresInABoxMatchTheOracle(): void
    {
        $spec = SceneGenerator::spheresInBox(42, 8, 10.0);
        $this->assertAgreesWithOracle($spec, 6.0, self::TIME_EPS, self::POS_EPS);
    }

    public function testASecondSeedOfRandomSpheresAlsoMatches(): void
    {
        $spec = SceneGenerator::spheresInBox(777, 10, 9.0);
        $this->assertAgreesWithOracle($spec, 5.0, self::TIME_EPS, self::POS_EPS);
    }

    public function testATetheredChainMatchesTheOracle(): void
    {
        $spec = SceneGenerator::tetheredChain(5, 4, 2.0);
        $this->assertAgreesWithOracle($spec, 6.0, self::TIME_EPS, self::POS_EPS);
    }

    /** No walls, sparse over 2000 units, radii from 0.01 to 1000, a couple of 200+ unit/s bullets: see docs/VERIFICATION.md. */
    public function testOpenWorldScenesMatchTheOracle(): void
    {
        foreach ([1, 2, 3] as $seed) {
            $spec = SceneGenerator::openWorld($seed, 6, 2000.0, 2);
            $this->assertAgreesWithOracle($spec, 2.0, self::TIME_EPS, self::POS_EPS, "seed {$seed}");
        }
    }

    /**
     * Tunnelling: at dt = 0.05 a bullet of 500+ units/second moves 25+ units a step, far more than a 0.01-radius
     * target is wide, so a check that only samples the two ends of each step would see the gap positive both times
     * and miss it entirely (see Oracle::march()). A coarse step is used deliberately here to prove the fix.
     */
    public function testABulletThroughATinyBodyIsNeverMissedEvenAtACoarseStep(): void
    {
        foreach ([1, 2, 3] as $seed) {
            $spec = SceneGenerator::bulletThroughTinyBody($seed);
            $this->assertAgreesWithOracle($spec, 0.5, self::TIME_EPS, self::POS_EPS, "dead-centre seed {$seed}", 0.05);
        }
    }

    /** The grazing variant: the bullet only just clips the target instead of passing through its centre. */
    public function testABulletGrazingATinyBodyIsNeverMissedEvenAtACoarseStep(): void
    {
        foreach ([1, 2, 3] as $seed) {
            $spec = SceneGenerator::bulletThroughTinyBody($seed, graze: true);
            $this->assertAgreesWithOracle($spec, 0.5, self::TIME_EPS, self::POS_EPS, "graze seed {$seed}", 0.05);
        }
    }

    /** The same tunnelling risk against a compound's thin part, exercising the (looser) part speed bound. */
    public function testABulletThroughAThinCompoundPartIsNeverMissedEvenAtACoarseStep(): void
    {
        foreach ([1, 2, 3] as $seed) {
            $spec = SceneGenerator::bulletThroughThinCompoundPart($seed);
            $until = 0.5;
            $engine = Harness::run($spec, $until, 0.047);
            $oracle = new Oracle($spec);
            $oracle->run(0.05, $until);
            $cmp = Harness::compareEvents($engine['events'], $oracle->events, self::PART_TIME_EPS);
            $this->assertSame([], $cmp['missing'], "seed {$seed}: " . json_encode($cmp['missing']));
            $this->assertSame([], $cmp['extra'], "seed {$seed}: " . json_encode($cmp['extra']));
        }
    }

    /** Two bullets crossing paths, closing at over 1000 units/second combined: neither side's march() may skip past the other. */
    public function testTwoBulletsCrossingPathsAreNeverMissedEvenAtACoarseStep(): void
    {
        foreach ([1, 2, 3] as $seed) {
            $spec = SceneGenerator::twoBulletsCrossing($seed);
            $this->assertAgreesWithOracle($spec, 0.5, self::TIME_EPS, self::POS_EPS, "seed {$seed}", 0.05);
        }
    }

    /**
     * A compound's part contact is only exact to Space::PROBE_TOLERANCE (see docs/VERIFICATION.md): the engine
     * reports it up to that gap early, not at the true touch. A fast-spinning part moves far in that little time, so
     * the bounce it computes (from the part's position and velocity then) differs from the oracle's exact-touch
     * bounce by more than rounding - not a disagreement, an accepted consequence of PROBE_TOLERANCE. So this test
     * holds position agreement to the tight budget only up to the first contact; after it, only the contact itself
     * (time, within the probe's budget) is checked, not where the free body ends up.
     */
    public function testACompoundTreeWithTurningArmsMatchesTheOracle(): void
    {
        $spec = SceneGenerator::compoundWithTurningArms(3, 2, 3, 5.0);
        $until = 2.5;
        $engine = Harness::run($spec, $until, 0.047);
        $oracle = new Oracle($spec);
        $oracle->run(0.0131, $until);
        $notAtTheEdge = static fn (array $e) => $e['time'] < $until - 1e-6;
        $cmp = Harness::compareEvents(array_values(array_filter($engine['events'], $notAtTheEdge)), array_values(array_filter($oracle->events, $notAtTheEdge)), self::PART_TIME_EPS);
        $this->assertSame([], $cmp['missing'], 'contacts the oracle saw but the engine did not: ' . json_encode($cmp['missing']));
        $this->assertSame([], $cmp['extra'], 'contacts the engine reported that the oracle did not: ' . json_encode($cmp['extra']));
        $firstContact = min(array_column($oracle->events, 'time'));
        $before = array_filter($engine['samples'], static fn ($s) => $s[0] < $firstContact - 0.05);
        $posError = Harness::worstPositionError($before, $oracle);
        $this->assertLessThanOrEqual(self::POS_EPS, $posError, 'position before the part is ever touched');
    }

    /**
     * The oracle's own error (see docs/PHYSICS-PLAN.md, "Phase 0"): motion is exact between events (straight lines,
     * exact rotation matrices), so a step that is fine enough never moves the answer again; one that is too coarse
     * can miss a brief graze, which shows up as a position error that does not shrink. Checking convergence means
     * checking that halving the step again (dt/2 to dt/4) moves the answer much less than the previous halving did
     * (dt to dt/2), not that it is already exactly zero.
     */
    public function testTheOracleConvergesAsTheStepHalves(): void
    {
        $spec = SceneGenerator::spheresInBox(99, 5, 10.0);
        $until = 3.0;
        $times = [];
        for ($t = 0.0; $t <= $until; $t += 0.33) {
            $times[] = $t;
        }
        $ids = [];
        for ($i = 0, $n = count($spec->bodyX); $i < $n; ++$i) {
            $ids[] = "B{$i}";
        }
        $errorBetween = static function (Oracle $a, Oracle $b) use ($times, $ids): float {
            $worst = 0.0;
            foreach ($times as $t) {
                foreach ($ids as $id) {
                    [$ax, $ay, $az] = $a->positionAt($id, $t);
                    [$bx, $by, $bz] = $b->positionAt($id, $t);
                    $worst = max($worst, sqrt(($ax - $bx) ** 2 + ($ay - $by) ** 2 + ($az - $bz) ** 2));
                }
            }
            return $worst;
        };
        $coarse = new Oracle($spec);
        $coarse->run(0.04, $until);
        $half = new Oracle($spec);
        $half->run(0.02, $until);
        $quarter = new Oracle($spec);
        $quarter->run(0.01, $until);
        $errCoarseToHalf = $errorBetween($coarse, $half);
        $errHalfToQuarter = $errorBetween($half, $quarter);
        $this->assertLessThanOrEqual(max($errCoarseToHalf / 2, 1e-9), $errHalfToQuarter, 'halving the step again should move the answer much less than the previous halving did');
    }

    /** A turning joint that bounces off its limit (server/src/Joint.php's pattern): pure forward kinematics, no contacts. */
    public function testATurningJointBouncingAtItsLimitMatchesExactForwardKinematics(): void
    {
        [$ax, $ay, $az, $min, $max, $bounce, $rate0] = [0.0, 1.0, 0.0, -1.0, 1.0, 0.7, 2.0];
        $until = 6.0;

        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $root = new Node3D();
        $scene->root->addChild($root);
        $joint = new TestJoint($ax, $ay, $az, $min, $max, $bounce);
        $root->addChild($joint);
        $joint->setRate($rate0);
        $arm = new Node3D();
        $arm->moveTo(3.0, 0.0, 0.0);
        $joint->addChild($arm);

        $spec = new SceneSpec();
        $body = $spec->addBody(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, false, true);
        $node = $spec->addNode(true, $body, 0.0, 0.0, 0.0, 0.0);
        [$starts, $xs, $ys, $zs] = TestJoint::schedule($ax, $ay, $az, $min, $max, $bounce, $rate0, 0.0, $until);
        $spec->setNodeSchedule($node, $starts, $xs, $ys, $zs);
        $spec->addNode(false, $node, 3.0, 0.0, 0.0, 0.1);   // the arm's tip: radius is irrelevant, only its position is checked
        $oracle = new Oracle($spec);
        $oracle->run(0.005, $until);

        $worst = 0.0;
        \phasync::run(static function () use ($world, $joint, $arm, $until, $oracle, &$worst) {
            $world->run(static function (World $w) use ($joint, $arm, $until, $oracle, &$worst) {
                $step = 0.02;
                for ($t = 0.0; $t < $until - 1e-9;) {
                    $next = min($step, $joint->untilLimit(), $until - $t);
                    $w->tick($next);
                    $t += $next;
                    if (abs($joint->untilLimit()) < 1e-9) {
                        $joint->reachLimit();
                    }
                    $m = $arm->getWorldMatrix();
                    [$ox, $oy, $oz] = $oracle->positionAt('N1', $t);
                    $worst = max($worst, sqrt(($m->m12 - $ox) ** 2 + ($m->m13 - $oy) ** 2 + ($m->m14 - $oz) ** 2));
                }
            });
        });
        $world->dispose();
        $this->assertLessThan(1e-6, $worst, 'the same schedule, walked by the engine\'s own quaternions and by the oracle\'s matrices');
    }

    #[Group('soak')]
    public function testManyRandomSeedsAllAgreeWithTheOracle(): void
    {
        for ($seed = 1; $seed <= 30; ++$seed) {
            $spec = SceneGenerator::spheresInBox($seed, 12, 10.0);
            $this->assertAgreesWithOracle($spec, 8.0, self::TIME_EPS, self::POS_EPS, "seed {$seed}");
        }
    }

    #[Group('soak')]
    public function testLargerOpenWorldScenesAllAgreeWithTheOracle(): void
    {
        for ($seed = 1; $seed <= 5; ++$seed) {
            $spec = SceneGenerator::openWorld($seed, 20, 5000.0, 3);
            $this->assertAgreesWithOracle($spec, 5.0, self::TIME_EPS, self::POS_EPS, "seed {$seed}");
        }
    }

    private function assertAgreesWithOracle(SceneSpec $spec, float $until, float $timeEps, float $posEps, string $label = '', float $dt = 0.0131): void
    {
        // $dt deliberately not aligned with the generator's 0.1 grid, so a contact never lands exactly on a sample
        $engine = Harness::run($spec, $until, 0.047);
        $oracle = new Oracle($spec);
        $oracle->run($dt, $until);
        // right at $until, whether a contact counts as "before" or "just after" is a tie decided by float noise on
        // each side (two independent chains of arithmetic): neither side is wrong, so that sliver is excluded here
        $notAtTheEdge = static fn (array $e) => $e['time'] < $until - 1e-6;
        $cmp = Harness::compareEvents(array_values(array_filter($engine['events'], $notAtTheEdge)), array_values(array_filter($oracle->events, $notAtTheEdge)), $timeEps);
        $this->assertSame([], $cmp['missing'], "contacts the oracle saw but the engine did not $label: " . json_encode($cmp['missing']));
        $this->assertSame([], $cmp['extra'], "contacts the engine reported that the oracle did not $label: " . json_encode($cmp['extra']));
        $this->assertLessThanOrEqual($timeEps, $cmp['worstTimeError'], "contact time $label");
        $posError = Harness::worstPositionError($engine['samples'], $oracle);
        $this->assertLessThanOrEqual($posEps, $posError, "position $label");
    }
}
