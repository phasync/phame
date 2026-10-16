<?php

namespace PhameServer\Solar;

use Phame\Rendering\MeshShader;
use Phame\Scene\Geometry;
use Phame\Scene\Material;
use Phame\Scene\Mesh;
use Phame\Scene\Label;
use Phame\Scene\Node3D;
use Phame\Scene\PointLight;
use Phame\Scene\Scene3D;

/**
 * Builds the solar system node tree in a Scene3D: a Mesh for every drawn body (the Sun, a planet, a moon, an
 * asteroid), a plain Node3D for every structural one (an anchor, an orbit frame - Net\View shows these as transform
 * only, since nothing in SolarSystem gives them a Geometry). Sun at origin, planets as anchor nodes moving in
 * straight lines (refit on Event::EXPIRE), with planet bodies and moon orbit frames underneath.
 */
class SolarSystem
{
    private static ?Geometry $sphere = null;

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

    private static ?MeshShader $bodyShader = null;

    /**
     * A sphere of $radius, drawn in $color: shares one unit Geometry (scaled), as RocksGame's balls do. $shadowed
     * (the Sun, every planet, every moon - never one of the 300 asteroids) opts into the eclipse-capable shader
     * below; every Material is still its own object (so each body keeps its own colour - Net\View has no notion of
     * per-instance colour, only per-material), but the eclipse shader's GLSL is a few KB, and View's snapshot does
     * not chunk by byte size, only by node count - inlining it into all ~300 asteroids' materials once blew a single
     * snapshot chunk past Swerve's 128 KiB ceiling (and so past silently). Asteroids are far too small on screen for
     * the shadow to read anyway, so they get the plain (shaderless, still lit) material instead.
     */
    private static ?Material $asteroidMaterial = null;

    private static function body(float $radius, int $color, bool $emissive = false, bool $shadowed = true): Mesh
    {
        // every asteroid is the same colour and shaderless - one shared Material (not a fresh one per body) keeps
        // the snapshot small (see the shader-size note above) and lets the browser instance all 300 in one draw
        // call, exactly as it does for RocksGame's identical rocks.
        $material = $shadowed ? new Material(color: $color, emissive: $emissive ? $color : 0x000000, shader: self::bodyShader())
            : (self::$asteroidMaterial ??= new Material(color: $color));
        $mesh = new Mesh(self::$sphere ??= Geometry::sphere(1.0, 16, 10), $material);
        $mesh->radius = $radius;
        $mesh->setScale($radius);
        return $mesh;
    }

    /**
     * Lit by the nearest point light (the Sun) plus an eclipse shadow: the fraction of the Sun's disc covered by
     * the nearest few large bodies (phame.js's generic "occluder" uniforms - see client/phame.js's docblock and
     * docs/REMOTE-VIEW.md's note on this), darkening and reddening a body passing through another's shadow (the
     * Moon during a lunar eclipse). The Sun's own radius is baked in as a GLSL constant: PointLight carries no
     * physical size (it is a true point for ordinary lighting), so there is no uniform to read it from - the engine
     * could add one (`PointLight::$radius`, defaulting to 0) if another game ever needs a light's own angular size;
     * until then this is the one place that needs it, and it never changes.
     */
    private static function bodyShader(): MeshShader
    {
        // GLSL is strictly typed: embedding a whole number without a decimal point makes it an int literal, and
        // int/float has no implicit conversion - sprintf keeps this a float literal even though the value is whole.
        $sunRadius = sprintf('%.1f', Ephemeris::SUN_RADIUS);
        return self::$bodyShader ??= new MeshShader(<<<GLSL
            // area of the circular segment of a circle (radius r) cut by a chord at distance d1 from its centre
            float segment(float r, float d1) { return r*r*acos(clamp(d1/r, -1.0, 1.0)) - d1*sqrt(max(r*r - d1*d1, 0.0)); }
            float lensArea(float d, float r1, float r2) {
                float d1 = (d*d - r2*r2 + r1*r1) / (2.0*d);
                return segment(r1, d1) + segment(r2, d - d1);
            }
            // fraction of the sun's disc (angular radius r1) covered by an occluder (angular radius r2), separated by angle d
            float coverage(float d, float r1, float r2) {
                if (d >= r1 + r2) return 0.0;
                if (d <= abs(r1 - r2)) { float m = min(r1, r2); return (m * m) / (r1 * r1); }
                return clamp(lensArea(d, r1, r2) / (3.14159265 * r1 * r1), 0.0, 1.0);
            }
            vec4 shade() {
                vec3 n = normalize(gl_FrontFacing ? vNormal : -vNormal);
                float ndotl = max(dot(n, SUN), 0.0);
                float shadow = 0.0;
                if (vEmissive.r < 0.01 && uPointCount > 0) {
                    vec3 toSun = uPointPos[0] - vWorldPos; float dSun = max(length(toSun), 1.0);
                    float sunAngR = {$sunRadius} / dSun;
                    vec3 sunDir = toSun / dSun;
                    for (int i = 0; i < uOccCount; i++) {
                        vec3 toOcc = uOccPos[i] - vWorldPos; float dOcc = length(toOcc);
                        if (dOcc < vRadius * 1.2) continue;   // this body is its own "occluder" entry: skip
                        float occAngR = uOccRadius[i] / max(dOcc, 1.0);
                        float theta = acos(clamp(dot(sunDir, toOcc / dOcc), -1.0, 1.0));
                        shadow = max(shadow, coverage(theta, sunAngR, occAngR));
                    }
                }
                float lit = ndotl * (1.0 - shadow);
                vec3 base;
                if (vEmissive.r > 0.01) {
                    base = vColor * 2.4 + vEmissive;
                } else {
                    base = vColor * (0.035 + 0.965 * lit);
                    if (shadow > 0.2) base = mix(base, vec3(0.5, 0.2, 0.09) * (0.04 + 0.6 * ndotl), clamp((shadow - 0.2) / 0.8, 0.0, 1.0) * 0.75);
                    base *= 1.0 + 0.04 * sin(n.y * 16.0 + vRadius * 0.001);   // cheap banding, visible mainly on gas giants
                }
                return vec4(base, 1.0);
            }
            GLSL);
    }

    private function buildSystem(): void
    {
        // As for planets and asteroids: the radius must not land directly on a root child, or it joins collision
        // detection, so a motionless anchor at the origin carries the visible Sun as its child.
        $sunAnchor = new Node3D();
        $this->scene->root->addChild($sunAnchor);
        $sun = self::body(Ephemeris::SUN_RADIUS, 0xfff2cc, emissive: true);
        $sun->name = 'Sun';
        $sun->addChild(new Label('Sun'));
        $sunAnchor->addChild($sun);
        // every body's N.L lighting (and the eclipse shadow above) comes from this, not the scene's directional
        // sun - a real point light at the Sun's position casts correctly as the viewer flies anywhere in the system.
        $sunAnchor->addChild(new PointLight(color: 0xfff2cc, intensity: 1.0, range: 1.0e12));

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
        $this->scene->root->addChild($anchor);
        $anchor->addEventListener('expire', fn () => $this->refitPlanetAnchor($name, $anchor));
        $this->refitPlanetAnchor($name, $anchor);

        // Planet body: fixed axial tilt, spinning about its own (tilted) pole so the pole direction never moves.
        // The name belongs here, not on the anchor: every anchor stays unnamed (and undrawn, a plain Node3D),
        // matching how the Sun and every moon are named on their visible body.
        $body = self::body($this->getPlanetRadius($name), 0x8899aa);
        $body->name = $name;
        $body->setOrientation(...$this->getTiltQuaternion($name));
        [$wx, $wy, $wz] = $this->getSpinAxis($name);
        $rate = 2 * M_PI / $this->getRotationPeriod($name);
        $body->spin($wx * $rate, $wy * $rate, $wz * $rate);
        $body->addChild(new Label($name));
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

        $moonNode = self::body($this->getMoonRadius($name), 0x999990);
        $moonNode->name = $name;
        $moonNode->setOrientation(0.0, 1.0, 0.0, 0.0);  // tidally locked: fixed face toward the orbit frame's origin
        $moonNode->addChild(new Label($name));
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
            $body = self::body(5.0, 0x8a8278, shadowed: false);
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
