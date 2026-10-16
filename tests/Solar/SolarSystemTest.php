<?php

namespace Phame\Tests\Solar;

require_once __DIR__ . '/../../server/vendor/autoload.php';

use Phame\Async;
use Phame\World;
use Phame\Scene\Scene3D;
use Phame\Scene\Node3D;
use PhameServer\Solar\SolarSystem;
use PhameServer\Solar\Ephemeris;
use PHPUnit\Framework\TestCase;

class SolarSystemTest extends TestCase
{
    private float $epoch;

    protected function setUp(): void
    {
        // Start at 2026-01-01 12:00:00 UTC
        $this->epoch = strtotime('2026-01-01 12:00:00 UTC');
    }

    /**
     * Drives a World at a fixed tick size while $body runs as a coroutine, synchronising to each tick with
     * Async::awaitFrame(). Assertion failures thrown inside World::go() are stashed on World::$failure rather than
     * propagating (see World::runGuarded()), so they are taken back out and rethrown here, or a failing assertion
     * would silently pass.
     */
    private function runTicked(float $tickSeconds, \Closure $body): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        \phasync::run(static function () use ($world, $scene, $body, $tickSeconds) {
            \phasync::go(static fn () => $world->run(function (World $w) use ($scene, $body, $tickSeconds) {
                $w->go(function () use ($w, $scene, $body) {
                    $body($w, $scene);
                    $w->stop();
                });
                while (!$w->stopped) {
                    $w->tick($tickSeconds);
                    \phasync::sleep(0);
                }
            }));
        });
        if ($failure = $world->takeFailure()) {
            $world->dispose();
            throw $failure;
        }
        $world->dispose();
    }

    public function testMoonPositionMatchesEphemeris(): void
    {
        $this->runTicked(7200.0, function (World $w, Scene3D $scene) {
            $system = new SolarSystem($scene, $this->epoch);
            $tolerance = 25.0;  // error budget (10 km) + slack for tree traversal and float rounding

            $testCount = 0;
            for ($i = 0; $i < 12; $i++) {
                Async::awaitFrame();
                $testCount += $this->verifyMoonPosition($scene, $w, $tolerance);
            }

            $this->assertGreaterThan(10, $testCount, 'Should have tested Moon position multiple times');
        });
    }

    private function verifyMoonPosition(Scene3D $scene, World $w, float $tolerance): int
    {
        $moonNode = $scene->root->findByName('Moon');
        $earthBody = $scene->root->findByName('Earth');   // the name is on the visible body, not its anchor
        if ($moonNode === null || $earthBody === null) {
            return 0;
        }

        $unix = $this->epoch + $w->clock->time;
        [$mx_eph, $my_eph, $mz_eph] = Ephemeris::moonPositionGeocentric($unix);

        [$mx, $my, $mz] = $this->worldPosition($moonNode);
        [$ex, $ey, $ez] = $this->worldPosition($earthBody);
        $dx = ($mx - $ex) - $mx_eph;
        $dy = ($my - $ey) - $my_eph;
        $dz = ($mz - $ez) - $mz_eph;
        $dist = sqrt($dx*$dx + $dy*$dy + $dz*$dz);

        $this->assertLessThan($tolerance, $dist, "Moon position mismatch at t={$w->clock->time}: {$dist} km");
        return 1;
    }

    public function testEarthAxisStaysFixed(): void
    {
        $this->runTicked(604800.0, function (World $w, Scene3D $scene) {
            $system = new SolarSystem($scene, $this->epoch);

            $bodyNode = $scene->root->findByName('Earth');   // the name is on the visible body, not its anchor
            $this->assertNotNull($bodyNode, 'Earth body not found');

            [$initialPx, $initialPy, $initialPz] = $this->poleDirection($bodyNode);

            for ($i = 0; $i < 52; ++$i) {
                Async::awaitFrame();  // one week per tick
                [$px, $py, $pz] = $this->poleDirection($bodyNode);
                $this->assertEqualsWithDelta($initialPx, $px, 1e-6, "Axis X changed at week $i");
                $this->assertEqualsWithDelta($initialPy, $py, 1e-6, "Axis Y changed at week $i");
                $this->assertEqualsWithDelta($initialPz, $pz, 1e-6, "Axis Z changed at week $i");
            }
        });
    }

    /** The body's pole direction (its local +z axis), unaffected by the spin phase that keeps changing the quaternion. */
    private function poleDirection(Node3D $node): array
    {
        $qx = $node->orientationX;
        $qy = $node->orientationY;
        $qz = $node->orientationZ;
        $qw = $node->orientationW;
        return [
            2.0 * ($qx * $qz + $qy * $qw),
            2.0 * ($qy * $qz - $qx * $qw),
            1.0 - 2.0 * ($qx * $qx + $qy * $qy),
        ];
    }

    public function testNodeTreeStructure(): void
    {
        $this->runTicked(1.0, function (World $w, Scene3D $scene) {
            $system = new SolarSystem($scene, $this->epoch);

            foreach (['Mercury', 'Venus', 'Earth', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune'] as $planet) {
                $body = $scene->root->findByName($planet);   // the name is on the visible body, not its anchor
                $this->assertNotNull($body, "$planet body not found");
                $this->assertGreaterThan(0, $body->radius, "$planet body radius not set");
                $anchor = $body->parent;
                $this->assertNotNull($anchor, "$planet anchor not found");
                $this->assertSame('', $anchor->name, "$planet anchor should be unnamed");
            }

            $this->assertNotNull($scene->root->findByName('Moon'), 'Moon not found');

            foreach (['Io', 'Europa', 'Ganymede', 'Callisto'] as $moon) {
                $this->assertNotNull($scene->root->findByName($moon), "$moon not found");
            }
        });
    }

    public function testEarthPositionAccuracy(): void
    {
        $this->runTicked(86400.0, function (World $w, Scene3D $scene) {
            $system = new SolarSystem($scene, $this->epoch);
            $tolerance = 25.0;

            for ($i = 0; $i < 7; $i++) {
                Async::awaitFrame();

                $earthBody = $scene->root->findByName('Earth');   // the name is on the visible body, not its anchor
                $this->assertNotNull($earthBody, 'Earth body not found');

                $unix = $this->epoch + $w->clock->time;
                [$ex_eph, $ey_eph, $ez_eph] = array_slice(Ephemeris::planetPositionVelocity('Earth', $unix), 0, 3);

                [$ex, $ey, $ez] = $this->worldPosition($earthBody);
                $dx = $ex - $ex_eph;
                $dy = $ey - $ey_eph;
                $dz = $ez - $ez_eph;
                $dist = sqrt($dx*$dx + $dy*$dy + $dz*$dz);

                $this->assertLessThan($tolerance, $dist, "Earth position error at t={$w->clock->time}: {$dist} km");
            }
        });
    }

    public function testPhobosPositionAccuracy(): void
    {
        $this->runTicked(3600.0, function (World $w, Scene3D $scene) {
            $system = new SolarSystem($scene, $this->epoch);
            $tolerance = 25.0;

            $testCount = 0;
            for ($i = 0; $i < 24; $i++) {
                Async::awaitFrame();

                $mars = $scene->root->findByName('Mars');   // the name is on the visible body, not its anchor
                $phobos = $scene->root->findByName('Phobos');
                $this->assertNotNull($mars);
                $this->assertNotNull($phobos);

                [$mx, $my, $mz] = $this->worldPosition($mars);
                [$phx, $phy, $phz] = $this->worldPosition($phobos);
                $px_tree = $phx - $mx;
                $py_tree = $phy - $my;
                $pz_tree = $phz - $mz;

                [$px_eph, $py_eph, $pz_eph] = $this->phobosReference($w->clock->time);

                $dx = $px_tree - $px_eph;
                $dy = $py_tree - $py_eph;
                $dz = $pz_tree - $pz_eph;
                $dist = sqrt($dx*$dx + $dy*$dy + $dz*$dz);

                $this->assertLessThan($tolerance, $dist, "Phobos position error at t={$w->clock->time}: {$dist} km");
                $this->assertEqualsWithDelta(9376.0, sqrt($px_tree**2 + $py_tree**2 + $pz_tree**2), 100.0, "Phobos orbital distance wrong at t={$w->clock->time}");
                $testCount++;
            }

            $this->assertGreaterThan(10, $testCount, 'Should have tested Phobos multiple times');
        });
    }

    /** Phobos' circular, slightly inclined orbit around Mars (independent of SolarSystem, for comparison). */
    private function phobosReference(float $gameTime): array
    {
        $n = 2 * M_PI / Ephemeris::PHOBOS_PERIOD;
        $inclination = 1.093 * M_PI / 180.0;
        $angle = fmod($n * $gameTime, 2 * M_PI);
        $x0 = Ephemeris::PHOBOS_SEMI_MAJOR * cos($angle);
        $y0 = Ephemeris::PHOBOS_SEMI_MAJOR * sin($angle);
        return [$x0, $y0 * cos($inclination), $y0 * sin($inclination)];
    }

    public function testTreeMatchesEphemerisAroundRefits(): void
    {
        // Moon, Earth and Phobos world positions from the node tree stay within the error budget (plus slack) of
        // the direct Ephemeris values at random times, including right before and after each refit.
        $this->runTicked(1800.0, function (World $w, Scene3D $scene) {
            $system = new SolarSystem($scene, $this->epoch);
            $tolerance = 25.0;
            $checks = 0;

            for ($i = 0; $i < 60; $i++) {
                // 30 minutes a step, much shorter than most refit intervals: over the run this samples the tree at
                // many phases relative to a refit, including right before one (about to expire) and right after.
                Async::awaitFrame();

                // the name is on each visible body, not its anchor
                $earth = $scene->root->findByName('Earth');
                $moon = $scene->root->findByName('Moon');
                $mars = $scene->root->findByName('Mars');
                $phobos = $scene->root->findByName('Phobos');
                $t = $w->clock->time;
                $unix = $this->epoch + $t;

                [$earthX, $earthY, $earthZ] = $this->worldPosition($earth);
                [$ex, $ey, $ez] = array_slice(Ephemeris::planetPositionVelocity('Earth', $unix), 0, 3);
                $this->assertLessThan($tolerance, $this->dist3($earthX, $earthY, $earthZ, $ex, $ey, $ez), "Earth mismatch at t={$t}");

                [$mx, $my, $mz] = Ephemeris::moonPositionGeocentric($unix);
                [$moonX, $moonY, $moonZ] = $this->worldPosition($moon);
                $this->assertLessThan($tolerance, $this->dist3($moonX - $earthX, $moonY - $earthY, $moonZ - $earthZ, $mx, $my, $mz), "Moon mismatch at t={$t}");

                [$marsX, $marsY, $marsZ] = $this->worldPosition($mars);
                [$px, $py, $pz] = $this->phobosReference($t);
                [$phobosX, $phobosY, $phobosZ] = $this->worldPosition($phobos);
                $this->assertLessThan($tolerance, $this->dist3($phobosX - $marsX, $phobosY - $marsY, $phobosZ - $marsZ, $px, $py, $pz), "Phobos mismatch at t={$t}");
                $checks++;
            }

            $this->assertGreaterThan(50, $checks);
        });
    }

    private function dist3(float $x1, float $y1, float $z1, float $x2, float $y2, float $z2): float
    {
        return sqrt(($x1 - $x2) ** 2 + ($y1 - $y2) ** 2 + ($z1 - $z2) ** 2);
    }

    /** World-space position: moons are nested under a planet anchor and orbit frame, so ->x/y/z alone is not enough. */
    private function worldPosition(Node3D $node): array
    {
        $m = $node->getWorldMatrix();
        return [$m->m12, $m->m13, $m->m14];
    }

    public function testNearestNamed(): void
    {
        $this->runTicked(1.0, function (World $w, Scene3D $scene) {
            $system = new SolarSystem($scene, $this->epoch);

            $earth = $scene->root->findByName('Earth');   // the name is on the visible body, not its anchor
            $this->assertNotNull($earth, 'Earth body not found');
            [$ex, $ey, $ez] = $this->worldPosition($earth);
            $this->assertSame('Earth', $system->nearestNamed($ex, $ey, $ez), 'Earth should be nearest to itself');

            $moon = $scene->root->findByName('Moon');
            $this->assertNotNull($moon, 'Moon not found');
            [$mx, $my, $mz] = $this->worldPosition($moon);
            $this->assertSame('Moon', $system->nearestNamed($mx, $my, $mz), 'Moon should be nearest to itself');

            $titan = $scene->root->findByName('Titan');
            $this->assertNotNull($titan, 'Titan not found');
            [$tx, $ty, $tz] = $this->worldPosition($titan);
            $this->assertSame('Titan', $system->nearestNamed($tx, $ty, $tz), 'Titan should be nearest to itself');
        });
    }
}
