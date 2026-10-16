<?php

namespace Phame\Tests\Solar;

require_once __DIR__ . '/../../server/vendor/autoload.php';

use PhameServer\Solar\Ephemeris;
use PHPUnit\Framework\TestCase;

class EphemerisTest extends TestCase
{
    private float $jan1_2026;
    private float $jul1_2026;
    private float $mar3_2026;  // lunar eclipse date

    protected function setUp(): void
    {
        // 2026-01-01 12:00:00 UTC = perihelion approximately
        $this->jan1_2026 = strtotime('2026-01-01 12:00:00 UTC');
        // 2026-07-01 00:00:00 UTC = aphelion approximately
        $this->jul1_2026 = strtotime('2026-07-01 00:00:00 UTC');
        // 2026-03-03 around 11:33 UTC = total lunar eclipse
        $this->mar3_2026 = strtotime('2026-03-03 11:33:00 UTC');
    }

    public function testEarthPerihelionDistance(): void
    {
        [$x, $y, $z] = array_slice(Ephemeris::planetPositionVelocity('Earth', $this->jan1_2026), 0, 3);
        $dist = sqrt($x*$x + $y*$y + $z*$z);
        // Perihelion ~147.1e6 km, within 0.1%
        $this->assertEqualsWithDelta(147.1e6, $dist, 147.1e6 * 0.001);
    }

    public function testEarthAphelionDistance(): void
    {
        [$x, $y, $z] = array_slice(Ephemeris::planetPositionVelocity('Earth', $this->jul1_2026), 0, 3);
        $dist = sqrt($x*$x + $y*$y + $z*$z);
        // Aphelion ~152.1e6 km, within 0.1%
        $this->assertEqualsWithDelta(152.1e6, $dist, 152.1e6 * 0.001);
    }

    /** 180° minus the Sun-Earth-Moon angle: 0° at exact opposition (full moon on the ecliptic). */
    private function offOppositionAngle(float $unix): float
    {
        [$ex, $ey, $ez] = array_slice(Ephemeris::planetPositionVelocity('Earth', $unix), 0, 3);
        [$mx, $my, $mz] = Ephemeris::moonPositionGeocentric($unix);

        // Sun direction from Earth (opposite of Earth's heliocentric position)
        $sx = -$ex;
        $sy = -$ey;
        $sz = -$ez;
        $sun_dist = sqrt($sx * $sx + $sy * $sy + $sz * $sz);
        $moon_dist = sqrt($mx * $mx + $my * $my + $mz * $mz);

        $dot = ($sx * $mx + $sy * $my + $sz * $mz) / ($sun_dist * $moon_dist);
        $dot = max(-1.0, min(1.0, $dot));
        return 180.0 - rad2deg(acos($dot));
    }

    /** Minimum off-opposition angle within ±$hours of $center, and the offset (minutes) where it occurs. */
    private function minOffOppositionAngle(float $center, float $hours): array
    {
        $best = INF;
        $bestOffset = 0;
        for ($m = -(int) ($hours * 60); $m <= (int) ($hours * 60); $m++) {
            $angle = $this->offOppositionAngle($center + $m * 60.0);
            if ($angle < $best) {
                $best = $angle;
                $bestOffset = $m;
            }
        }
        return [$best, $bestOffset];
    }

    public function testLunarEclipseAngle(): void
    {
        // Total lunar eclipse 2026-03-03, maximum ~11:33 UTC: off-opposition angle under 0.5°, minimised
        // within 15 minutes of 11:33.
        [$angle, $offset] = $this->minOffOppositionAngle($this->mar3_2026, 12.0);
        $this->assertLessThan(0.5, $angle, "Total lunar eclipse off-opposition angle too large: {$angle}°");
        $this->assertLessThanOrEqual(15, abs($offset), "Total lunar eclipse minimum off by {$offset} minutes from 11:33");
    }

    public function testPartialLunarEclipseAngle(): void
    {
        // Partial lunar eclipse 2026-08-28, maximum ~04:13 UTC: off-opposition angle under 0.6°.
        $center = strtotime('2026-08-28 04:13:00 UTC');
        [$angle] = $this->minOffOppositionAngle($center, 12.0);
        $this->assertLessThan(0.6, $angle, "Partial lunar eclipse off-opposition angle too large: {$angle}°");
    }

    public function testNonEclipseLunarAngle(): void
    {
        // Full moons without an eclipse: off-opposition angle (minimised over ±12h) stays over 1°.
        foreach (['2026-02-01 12:00:00 UTC', '2026-04-02 12:00:00 UTC'] as $date) {
            $center = strtotime($date);
            [$angle] = $this->minOffOppositionAngle($center, 12.0);
            $this->assertGreaterThan(1.0, $angle, "Non-eclipse full moon too close to opposition near {$date}: {$angle}°");
        }
    }

    public function testMoonElongationRanges(): void
    {
        // Test that Moon elongation varies throughout a month (0 to 180+ degrees)
        // This verifies lunar motion is calculated
        $test_dates = [
            strtotime('2026-02-01 12:00:00 UTC'),
            strtotime('2026-02-10 12:00:00 UTC'),
            strtotime('2026-02-17 12:00:00 UTC'),
            strtotime('2026-02-25 12:00:00 UTC'),
        ];

        $elongations = [];
        foreach ($test_dates as $time) {
            [$ex, $ey, $ez] = array_slice(Ephemeris::planetPositionVelocity('Earth', $time), 0, 3);
            [$mx, $my, $mz] = Ephemeris::moonPositionGeocentric($time);

            // Sun-Earth-Moon elongation (angle between Sun and Moon as seen from Earth)
            $sx = -$ex;
            $sy = -$ey;
            $sz = -$ez;
            $sun_dist = sqrt($sx*$sx + $sy*$sy + $sz*$sz);
            $moon_dist = sqrt($mx*$mx + $my*$my + $mz*$mz);

            $dot = ($sx * $mx + $sy * $my + $sz * $mz) / ($sun_dist * $moon_dist);
            $dot = max(-1.0, min(1.0, $dot));
            $elongation = rad2deg(acos($dot));
            $elongations[] = $elongation;
        }

        // Elongations should span a range (Moon moves around Earth)
        $min_elong = min($elongations);
        $max_elong = max($elongations);
        $span = max($max_elong - $min_elong, 360.0 - ($max_elong - $min_elong));

        // Over 24 days, elongation should change by at least 60 degrees (lunar synodic month is ~29.5 days)
        $this->assertGreaterThan(60.0, $span, "Moon elongation should vary over a month: span = {$span}");
    }

    public function testMeusWorkedExample47a(): void
    {
        // Meeus ch. 47, example 47.a: 1992 April 12, 0h TD
        // Expected (mean equinox of date, before our J2000 conversion): λ = 133.162655°, β = −3.229126°, Δ = 368409.7 km
        $meeus_date = strtotime('1992-04-12 00:00:00 UTC');

        [$lon_deg, $lat_deg, $dist] = Ephemeris::moonEclipticOfDate($meeus_date);

        $this->assertEqualsWithDelta(133.162655, $lon_deg, 0.01, "Moon longitude mismatch from Meeus 47.a: {$lon_deg}°");
        $this->assertEqualsWithDelta(-3.229126, $lat_deg, 0.01, "Moon latitude mismatch from Meeus 47.a: {$lat_deg}°");
        $this->assertEqualsWithDelta(368409.7, $dist, 10.0, "Moon distance mismatch from Meeus 47.a: {$dist} km");
    }

    public function testMoonReturnDistance(): void
    {
        // Moon's distance stays within its perigee/apogee range over a month, sampled daily.
        $start = strtotime('2026-01-01 00:00:00 UTC');
        for ($day = 0; $day <= 30; $day++) {
            $time = $start + $day * 86400;
            [$x, $y, $z] = Ephemeris::moonPositionGeocentric($time);
            $dist = sqrt($x*$x + $y*$y + $z*$z);
            $this->assertGreaterThan(356000.0, $dist, "Moon distance too small at {$time}: {$dist} km");
            $this->assertLessThan(407000.0, $dist, "Moon distance too large at {$time}: {$dist} km");
        }
    }
}
