<?php

namespace PhameServer\Solar;

use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;

/**
 * Builds the solar system node tree in a Scene3D.
 * Sun at origin, planets as anchor nodes moving in straight lines (refit on Event::EXPIRE),
 * with planet bodies and moon orbit frames underneath.
 */
class SolarSystem
{
    private Scene3D $scene;
    private float $epoch;  // unix timestamp for t=0
    private const ERROR_BUDGET = 10.0;  // km tolerance for position error
    private const MAX_REFIT_INTERVAL = 86400.0;  // 1 day maximum
    /** @var array<string, Node3D> planet anchors, keyed by name */
    private array $planets = [];
    /** @var array<string, Node3D> moon bodies, keyed by name */
    private array $moons = [];

    public function __construct(Scene3D $scene, float $epoch)
    {
        $this->scene = $scene;
        $this->epoch = $epoch;
        $this->buildSystem();
    }

    private function buildSystem(): void
    {
        foreach (['Mercury', 'Venus', 'Earth', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune'] as $planet) {
            $this->addPlanet($planet);
        }
        $this->addAsteroids();
    }

    private function addPlanet(string $name): void
    {
        // Anchor: a non-rotating node moving in a straight line (a chord to its position at the window's end),
        // refit on Event::EXPIRE with an interval sized from the error budget.
        $anchor = new Node3D();
        $anchor->name = $name;
        $this->scene->root->addChild($anchor);
        $anchor->addEventListener('expire', fn () => $this->refitPlanetAnchor($name, $anchor));
        $this->refitPlanetAnchor($name, $anchor);

        // Planet body: fixed axial tilt, spinning about its own (tilted) pole so the pole direction never moves.
        $body = new Node3D();
        $body->radius = $this->getPlanetRadius($name);
        $body->setOrientation(...$this->getTiltQuaternion($name));
        [$wx, $wy, $wz] = $this->getSpinAxis($name);
        $rate = 2 * M_PI / $this->getRotationPeriod($name);
        $body->spin($wx * $rate, $wy * $rate, $wz * $rate);
        $anchor->addChild($body);

        match ($name) {
            'Earth' => $this->addMoon('Moon', $anchor, 'Earth'),
            'Jupiter' => array_map(fn ($m) => $this->addMoon($m, $anchor, 'Jupiter'), ['Io', 'Europa', 'Ganymede', 'Callisto']),
            'Saturn' => $this->addMoon('Titan', $anchor, 'Saturn'),
            'Mars' => array_map(fn ($m) => $this->addMoon($m, $anchor, 'Mars'), ['Phobos', 'Deimos']),
            'Neptune' => $this->addMoon('Triton', $anchor, 'Neptune'),
            default => null,
        };

        $this->planets[$name] = $anchor;
    }

    private function refitPlanetAnchor(string $planet, Node3D $anchor): void
    {
        $t0 = $this->clock();
        $unix0 = $this->epoch + $t0;
        [$x0, $y0, $z0, $vx0, $vy0, $vz0] = Ephemeris::planetPositionVelocity($planet, $unix0);

        // Δt = sqrt(8ε/a), a = centripetal acceleration v²/r: a chord over this window sags at most ε at its midpoint.
        $r = sqrt($x0 * $x0 + $y0 * $y0 + $z0 * $z0);
        $v = sqrt($vx0 * $vx0 + $vy0 * $vy0 + $vz0 * $vz0);
        $a = ($v * $v) / $r;
        $interval = min(self::MAX_REFIT_INTERVAL, sqrt(8.0 * self::ERROR_BUDGET / max(1e-9, $a)));

        // The chord itself: a straight line from here to the true position at the window's end, not the tangent.
        [$x1, $y1, $z1] = array_slice(Ephemeris::planetPositionVelocity($planet, $unix0 + $interval), 0, 3);

        $anchor->moveTo($x0, $y0, $z0);
        $anchor->setVelocity(($x1 - $x0) / $interval, ($y1 - $y0) / $interval, ($z1 - $z0) / $interval);
        $anchor->validUntil = $t0 + $interval;
    }

    /**
     * Adds a moon: an orbit-frame node (oriented to the instantaneous orbital plane, spinning at the current
     * angular rate) carrying the moon body as a tidally-locked child at its orbital radius. Refit on Event::EXPIRE.
     */
    private function addMoon(string $name, Node3D $parentAnchor, string $planet): void
    {
        $orbitFrame = new Node3D();
        $parentAnchor->addChild($orbitFrame);

        $moonNode = new Node3D();
        $moonNode->name = $name;
        $moonNode->radius = $this->getMoonRadius($name);
        $moonNode->setOrientation(0.0, 1.0, 0.0, 0.0);  // tidally locked: fixed face toward the orbit frame's origin
        $orbitFrame->addChild($moonNode);

        $orbitFrame->addEventListener('expire', fn () => $this->refitMoon($name, $planet, $orbitFrame, $moonNode));
        $this->refitMoon($name, $planet, $orbitFrame, $moonNode);

        $this->moons[$name] = $moonNode;
    }

    private function refitMoon(string $name, string $planet, Node3D $orbitFrame, Node3D $moonNode): void
    {
        $t0 = $this->clock();
        [$px, $py, $pz, $vx, $vy, $vz] = $this->moonStateVector($name, $t0);
        $dist = sqrt($px * $px + $py * $py + $pz * $pz);

        // Orthonormal basis for the orbit frame: x toward the moon, z along the orbital-plane normal (pos x vel).
        $xx = $px / $dist; $xy = $py / $dist; $xz = $pz / $dist;
        $nx = $py * $vz - $pz * $vy;
        $ny = $pz * $vx - $px * $vz;
        $nz = $px * $vy - $py * $vx;
        $nlen = sqrt($nx * $nx + $ny * $ny + $nz * $nz) ?: 1.0;
        $zx = $nx / $nlen; $zy = $ny / $nlen; $zz = $nz / $nlen;
        $yx = $zy * $xz - $zz * $xy;
        $yy = $zz * $xx - $zx * $xz;
        $yz = $zx * $xy - $zy * $xx;

        $orbitFrame->setOrientation(...$this->basisToQuaternion($xx, $xy, $xz, $yx, $yy, $yz, $zx, $zy, $zz));
        $moonNode->moveTo($dist, 0.0, 0.0);
        $rate = $nlen / ($dist * $dist);  // instantaneous angular rate about the orbit normal
        // spin()'s axis is in the parent's space (the anchor's), not the orbit frame's own just-rotated axes: use
        // the orbit normal (zx, zy, zz) as already expressed there, not the local (0, 0, 1) the orientation maps to it.
        $orbitFrame->spin($rate * $zx, $rate * $zy, $rate * $zz);

        $interval = $this->computeMoonRefitInterval($name, $t0, $dist, $rate, $xx, $xy, $xz, $yx, $yy, $yz);
        $orbitFrame->validUntil = $t0 + $interval;
    }

    /**
     * Interval until the orbit frame's spin (an exact match for a circular orbit) drifts more than ε from the true
     * position; found by halving from an initial guess. Circular moons never drift, so they just use the day cap.
     */
    private function computeMoonRefitInterval(string $name, float $t0, float $dist, float $rate, float $xx, float $xy, float $xz, float $yx, float $yy, float $yz): float
    {
        if ($name !== 'Moon') {
            return self::MAX_REFIT_INTERVAL;
        }

        $interval = 3600.0;
        while (true) {
            $angle = $rate * $interval;
            $c = cos($angle); $s = sin($angle);
            $tx = $dist * ($c * $xx + $s * $yx);
            $ty = $dist * ($c * $xy + $s * $yy);
            $tz = $dist * ($c * $xz + $s * $yz);

            [$ax, $ay, $az] = $this->moonStateVector($name, $t0 + $interval);
            $error = sqrt(($tx - $ax) ** 2 + ($ty - $ay) ** 2 + ($tz - $az) ** 2);
            if ($error <= self::ERROR_BUDGET || $interval <= 60.0) {
                break;
            }
            $interval /= 2.0;
        }

        return min(self::MAX_REFIT_INTERVAL, $interval);
    }

    /** Position and velocity (km, km/s) of a moon relative to its planet, in ecliptic coordinates. */
    private function moonStateVector(string $name, float $gameTime): array
    {
        if ($name === 'Moon') {
            $unix = $this->epoch + $gameTime;
            [$x, $y, $z] = Ephemeris::moonPositionGeocentric($unix);
            $dt = 60.0;
            [$x2, $y2, $z2] = Ephemeris::moonPositionGeocentric($unix + $dt);
            return [$x, $y, $z, ($x2 - $x) / $dt, ($y2 - $y) / $dt, ($z2 - $z) / $dt];
        }

        [$period, $semiMajor, $inclination] = $this->getMoonElements($name);
        $n = 2 * M_PI / $period;
        $angle = fmod($n * $gameTime, 2 * M_PI);
        $x0 = $semiMajor * cos($angle);
        $y0 = $semiMajor * sin($angle);
        $vx0 = -$semiMajor * $n * sin($angle);
        $vy0 = $semiMajor * $n * cos($angle);
        $ci = cos($inclination); $si = sin($inclination);

        // Tilt the flat circular orbit about the x-axis by the inclination.
        return [$x0, $y0 * $ci, $y0 * $si, $vx0, $vy0 * $ci, $vy0 * $si];
    }

    private function getMoonElements(string $name): array
    {
        return match ($name) {
            'Io' => [Ephemeris::IO_PERIOD, Ephemeris::IO_SEMI_MAJOR, 0.036 * M_PI / 180.0],
            'Europa' => [Ephemeris::EUROPA_PERIOD, Ephemeris::EUROPA_SEMI_MAJOR, 0.009 * M_PI / 180.0],
            'Ganymede' => [Ephemeris::GANYMEDE_PERIOD, Ephemeris::GANYMEDE_SEMI_MAJOR, 0.002 * M_PI / 180.0],
            'Callisto' => [Ephemeris::CALLISTO_PERIOD, Ephemeris::CALLISTO_SEMI_MAJOR, 0.281 * M_PI / 180.0],
            'Titan' => [Ephemeris::TITAN_PERIOD, Ephemeris::TITAN_SEMI_MAJOR, 0.0288 * M_PI / 180.0],
            'Phobos' => [Ephemeris::PHOBOS_PERIOD, Ephemeris::PHOBOS_SEMI_MAJOR, 1.093 * M_PI / 180.0],
            'Deimos' => [Ephemeris::DEIMOS_PERIOD, Ephemeris::DEIMOS_SEMI_MAJOR, 0.0024 * M_PI / 180.0],
            'Triton' => [Ephemeris::TRITON_PERIOD, Ephemeris::TRITON_SEMI_MAJOR, 156.865 * M_PI / 180.0],
            default => throw new \ValueError("Unknown moon: $name"),
        };
    }

    /** Rotation matrix (columns x, y, z, each a unit vector) to a unit quaternion [qx, qy, qz, qw]. */
    private function basisToQuaternion(float $xx, float $xy, float $xz, float $yx, float $yy, float $yz, float $zx, float $zy, float $zz): array
    {
        $trace = $xx + $yy + $zz;
        if ($trace > 0.0) {
            $s = sqrt($trace + 1.0) * 2.0;
            return [($yz - $zy) / $s, ($zx - $xz) / $s, ($xy - $yx) / $s, 0.25 * $s];
        }
        if ($xx > $yy && $xx > $zz) {
            $s = sqrt(1.0 + $xx - $yy - $zz) * 2.0;
            return [0.25 * $s, ($yx + $xy) / $s, ($zx + $xz) / $s, ($yz - $zy) / $s];
        }
        if ($yy > $zz) {
            $s = sqrt(1.0 + $yy - $xx - $zz) * 2.0;
            return [($yx + $xy) / $s, 0.25 * $s, ($zy + $yz) / $s, ($zx - $xz) / $s];
        }
        $s = sqrt(1.0 + $zz - $xx - $yy) * 2.0;
        return [($zx + $xz) / $s, ($zy + $yz) / $s, 0.25 * $s, ($xy - $yx) / $s];
    }

    private function addAsteroids(): void
    {
        // ~300 asteroids between 2.2 and 3.3 AU, as straight-line nodes refit by the same error-budget rule.
        // Each asteroid's (a, lon0, lat) is drawn once from the seeded stream and then fixed for its lifetime.
        $seed = 12345;
        for ($i = 0; $i < 300; ++$i) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $a = (2.2 + (($seed % 1000) / 1000.0) * 1.1) * Ephemeris::AU_TO_KM;  // semi-major axis, km

            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $lon0 = (($seed % 360000) / 1000.0) * M_PI / 180.0;

            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $lat = ((($seed % 200000) / 1000.0) - 100.0) * M_PI / 180.0 * 0.1;  // small inclination

            // A non-rotating anchor (as for planets) carrying a visual-only body: the body's radius must not land
            // directly on a root child, or every one of the 300 asteroids joins the scene's collision detection.
            $asteroid = new Node3D();
            $this->scene->root->addChild($asteroid);
            $body = new Node3D();
            $body->radius = 5.0;
            $asteroid->addChild($body);
            $asteroid->addEventListener('expire', fn () => $this->refitAsteroid($asteroid, $a, $lon0, $lat));
            $this->refitAsteroid($asteroid, $a, $lon0, $lat);
        }
    }

    private function refitAsteroid(Node3D $asteroid, float $a, float $lon0, float $lat): void
    {
        $t0 = $this->clock();
        [$x0, $y0, $z0, $vx0, $vy0, $vz0] = $this->asteroidState($a, $lon0, $lat, $t0);
        $r = sqrt($x0 * $x0 + $y0 * $y0 + $z0 * $z0);
        $v = sqrt($vx0 * $vx0 + $vy0 * $vy0 + $vz0 * $vz0);
        $acc = ($v * $v) / $r;
        $interval = min(self::MAX_REFIT_INTERVAL, sqrt(8.0 * self::ERROR_BUDGET / max(1e-9, $acc)));

        // The chord to the true position at the window's end, not the tangent.
        [$x1, $y1, $z1] = $this->asteroidState($a, $lon0, $lat, $t0 + $interval);

        $asteroid->moveTo($x0, $y0, $z0);
        $asteroid->setVelocity(($x1 - $x0) / $interval, ($y1 - $y0) / $interval, ($z1 - $z0) / $interval);
        $asteroid->validUntil = $t0 + $interval;
    }

    /** A circular, slightly inclined asteroid orbit's position and velocity (km, km/s) at $gameTime. */
    private function asteroidState(float $a, float $lon0, float $lat, float $gameTime): array
    {
        $n = sqrt(1.327e11 / ($a * $a * $a));  // mean motion
        $lon = $lon0 + $n * $gameTime;

        $r = $a / cos($lat);
        $x = $r * cos($lon) * cos($lat);
        $y = $r * sin($lon) * cos($lat);
        $z = $r * sin($lat);

        $v = $n * $a;
        $vx = -$v * sin($lon) * cos($lat);
        $vy = $v * cos($lon) * cos($lat);
        $vz = 0.0;

        return [$x, $y, $z, $vx, $vy, $vz];
    }

    private function getPlanetRadius(string $name): float
    {
        return match ($name) {
            'Mercury' => Ephemeris::MERCURY_RADIUS,
            'Venus' => Ephemeris::VENUS_RADIUS,
            'Earth' => Ephemeris::EARTH_RADIUS,
            'Mars' => Ephemeris::MARS_RADIUS,
            'Jupiter' => Ephemeris::JUPITER_RADIUS,
            'Saturn' => Ephemeris::SATURN_RADIUS,
            'Uranus' => Ephemeris::URANUS_RADIUS,
            'Neptune' => Ephemeris::NEPTUNE_RADIUS,
            default => 0.0,
        };
    }

    private function getMoonRadius(string $name): float
    {
        return match ($name) {
            'Moon' => Ephemeris::MOON_RADIUS,
            'Io' => 1821.6,
            'Europa' => 1560.8,
            'Ganymede' => 2634.1,
            'Callisto' => 2410.3,
            'Titan' => 2574.7,
            'Phobos' => 11.27,
            'Deimos' => 6.2,
            'Triton' => 1353.4,
            default => 0.0,
        };
    }

    private function getTilt(string $name): float
    {
        return match ($name) {
            'Mercury' => Ephemeris::MERCURY_TILT,
            'Venus' => Ephemeris::VENUS_TILT,
            'Earth' => Ephemeris::EARTH_TILT,
            'Mars' => Ephemeris::MARS_TILT,
            'Jupiter' => Ephemeris::JUPITER_TILT,
            'Saturn' => Ephemeris::SATURN_TILT,
            'Uranus' => Ephemeris::URANUS_TILT,
            'Neptune' => Ephemeris::NEPTUNE_TILT,
            default => 0.0,
        };
    }

    /** Rotation (about the ecliptic-frame x-axis) from the ecliptic pole to the planet's axial tilt. */
    private function getTiltQuaternion(string $name): array
    {
        $half = $this->getTilt($name) / 2.0;
        return [sin($half), 0.0, 0.0, cos($half)];
    }

    /**
     * The planet's own pole direction (unit vector, ecliptic frame): spinning about this (rather than the
     * untilted ecliptic pole) keeps the axis itself fixed while the body turns. A tilt beyond 90° (Venus, Uranus)
     * points the axis toward the southern side, which alone makes the spin look retrograde from the north.
     */
    private function getSpinAxis(string $name): array
    {
        $tilt = $this->getTilt($name);
        return [0.0, -sin($tilt), cos($tilt)];
    }

    private function getRotationPeriod(string $name): float
    {
        return match ($name) {
            'Mercury' => Ephemeris::MERCURY_ROTATION,
            'Venus' => Ephemeris::VENUS_ROTATION,
            'Earth' => Ephemeris::EARTH_ROTATION,
            'Mars' => Ephemeris::MARS_ROTATION,
            'Jupiter' => Ephemeris::JUPITER_ROTATION,
            'Saturn' => Ephemeris::SATURN_ROTATION,
            'Uranus' => Ephemeris::URANUS_ROTATION,
            'Neptune' => Ephemeris::NEPTUNE_ROTATION,
            default => 86400.0,
        };
    }

    private function clock(): float
    {
        return $this->scene->root->clock?->time ?? 0.0;
    }

    /** Find the nearest named body (planet or moon) to a point in world space. */
    public function nearestNamed(float $x, float $y, float $z): string
    {
        $minDist = INF;
        $nearest = '';

        // Moons are nested under their planet's anchor and orbit frame, so $node->x/y/z (parent-relative) is not
        // enough: use the world matrix's translation to compare every body in the same (Sun-centered) space.
        foreach ($this->planets + $this->moons as $name => $node) {
            $world = $node->getWorldMatrix();
            $dx = $world->m12 - $x;
            $dy = $world->m13 - $y;
            $dz = $world->m14 - $z;
            $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = $name;
            }
        }

        return $nearest;
    }
}
