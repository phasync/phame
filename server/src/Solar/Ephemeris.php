<?php

namespace PhameServer\Solar;

/**
 * Ephemeris calculations: pure functions for heliocentric planet positions, geocentric lunar position.
 * Units: km, seconds, J2000 ecliptic coordinates (x toward vernal equinox, z toward ecliptic pole).
 * Epoch unix time -> seconds since J2000.0.
 */
final class Ephemeris
{
    // J2000 epoch in unix time: 2000-01-01 12:00:00 UTC = JD 2451545.0 (TT - UTC was 64.184s at that date, so this
    // is ~64s off the true TT instant; close enough given our km-level error budget, and it keeps the time argument
    // consistent with plain unix/UTC timestamps elsewhere in the engine).
    public const EPOCH_J2000 = 946728000;

    // Planet radii (km)
    public const MERCURY_RADIUS = 2439.7;
    public const VENUS_RADIUS = 6051.8;
    public const EARTH_RADIUS = 6371.0;
    public const MARS_RADIUS = 3389.5;
    public const JUPITER_RADIUS = 69911.0;
    public const SATURN_RADIUS = 58232.0;
    public const URANUS_RADIUS = 25362.0;
    public const NEPTUNE_RADIUS = 24622.0;

    // Moon properties
    public const MOON_RADIUS = 1737.4;
    public const MOON_ORBITAL_PERIOD = 27.321661; // days (sidereal)

    // Other moon orbital elements (circular orbits, in km)
    public const IO_PERIOD = 1.769 * 86400.0;      // seconds
    public const IO_SEMI_MAJOR = 421700.0;
    public const EUROPA_PERIOD = 3.551 * 86400.0;
    public const EUROPA_SEMI_MAJOR = 671100.0;
    public const GANYMEDE_PERIOD = 7.155 * 86400.0;
    public const GANYMEDE_SEMI_MAJOR = 1070400.0;
    public const CALLISTO_PERIOD = 16.689 * 86400.0;
    public const CALLISTO_SEMI_MAJOR = 1882600.0;

    public const TITAN_PERIOD = 15.945 * 86400.0;
    public const TITAN_SEMI_MAJOR = 1222000.0;

    public const PHOBOS_PERIOD = 0.31891 * 86400.0;
    public const PHOBOS_SEMI_MAJOR = 9376.0;
    public const DEIMOS_PERIOD = 1.2624 * 86400.0;
    public const DEIMOS_SEMI_MAJOR = 23463.0;

    public const TRITON_PERIOD = 5.877 * 86400.0;
    public const TRITON_SEMI_MAJOR = 354800.0;

    // Axial tilts (ecliptic coordinates, radians)
    public const MERCURY_TILT = 0.0349;        // ~2.0°
    public const VENUS_TILT = 3.0958;          // ~177.4° (retrograde)
    public const EARTH_TILT = 0.4090;          // ~23.44°
    public const MARS_TILT = 0.4397;           // ~25.2°
    public const JUPITER_TILT = 0.0549;        // ~3.1°
    public const SATURN_TILT = 0.4660;         // ~26.7°
    public const URANUS_TILT = 1.7054;         // ~97.8° (retrograde)
    public const NEPTUNE_TILT = 0.4901;        // ~28.1°

    // Sidereal rotation periods (seconds)
    public const MERCURY_ROTATION = 5067031.0;    // 58.646 days
    public const VENUS_ROTATION = 21294960.0;     // 243.025 days (retrograde)
    public const EARTH_ROTATION = 86164.1;        // sidereal day
    public const MARS_ROTATION = 88642.663;       // 1.0259 days
    public const JUPITER_ROTATION = 35730.0;      // 0.41 days (equatorial)
    public const SATURN_ROTATION = 37800.0;       // 0.44 days (equatorial)
    public const URANUS_ROTATION = 62063.0;       // 0.718 days (retrograde)
    public const NEPTUNE_ROTATION = 57996.0;      // 0.6713 days

    // JPL Keplerian elements for approximate positions (Standish, valid 1800-2050)
    // a (AU), e, I (deg), L (deg), long_perihelion (deg), long_ascending_node (deg)
    // and their rates per century
    // Mercury
    public const MERCURY_A = 0.38709927;
    public const MERCURY_A_RATE = 0.00000037;
    public const MERCURY_E = 0.20563593;
    public const MERCURY_E_RATE = 0.00001906;
    public const MERCURY_I = 7.00497902;
    public const MERCURY_I_RATE = -0.00594749;
    public const MERCURY_L = 252.25032350;
    public const MERCURY_L_RATE = 149472.67411175;
    public const MERCURY_LONG_PERIHELION = 77.45779628;
    public const MERCURY_LONG_PERIHELION_RATE = 0.16047689;
    public const MERCURY_LONG_NODE = 48.33076593;
    public const MERCURY_LONG_NODE_RATE = -0.12534081;

    // Venus
    public const VENUS_A = 0.72333566;
    public const VENUS_A_RATE = 0.00000390;
    public const VENUS_E = 0.00677672;
    public const VENUS_E_RATE = -0.00004107;
    public const VENUS_I = 3.39467605;
    public const VENUS_I_RATE = -0.00078890;
    public const VENUS_L = 181.97909950;
    public const VENUS_L_RATE = 58517.81538729;
    public const VENUS_LONG_PERIHELION = 131.60246718;
    public const VENUS_LONG_PERIHELION_RATE = 0.00268329;
    public const VENUS_LONG_NODE = 76.67984255;
    public const VENUS_LONG_NODE_RATE = -0.27769418;

    // Earth
    public const EARTH_A = 1.00000261;
    public const EARTH_A_RATE = 0.00000562;
    public const EARTH_E = 0.01671123;
    public const EARTH_E_RATE = -0.00004392;
    public const EARTH_I = -0.00005531;
    public const EARTH_I_RATE = -0.01294668;
    public const EARTH_L = 100.46645683;
    public const EARTH_L_RATE = 35999.37604864;
    public const EARTH_LONG_PERIHELION = 102.93734808;
    public const EARTH_LONG_PERIHELION_RATE = 0.32327364;
    public const EARTH_LONG_NODE = 0.0;
    public const EARTH_LONG_NODE_RATE = 0.0;

    // Mars
    public const MARS_A = 1.52371034;
    public const MARS_A_RATE = 0.00001847;
    public const MARS_E = 0.09339410;
    public const MARS_E_RATE = 0.00007882;
    public const MARS_I = 1.84969142;
    public const MARS_I_RATE = -0.00813131;
    public const MARS_L = 355.43299958;
    public const MARS_L_RATE = 19140.30268499;
    public const MARS_LONG_PERIHELION = 336.60541766;
    public const MARS_LONG_PERIHELION_RATE = 0.44441088;
    public const MARS_LONG_NODE = 49.55953891;
    public const MARS_LONG_NODE_RATE = -0.29257343;

    // Jupiter
    public const JUPITER_A = 5.20288700;
    public const JUPITER_A_RATE = -0.00011607;
    public const JUPITER_E = 0.04838624;
    public const JUPITER_E_RATE = -0.00013253;
    public const JUPITER_I = 1.30439695;
    public const JUPITER_I_RATE = -0.00183714;
    public const JUPITER_L = 34.39644051;
    public const JUPITER_L_RATE = 3034.74612775;
    public const JUPITER_LONG_PERIHELION = 14.72847983;
    public const JUPITER_LONG_PERIHELION_RATE = 0.21252668;
    public const JUPITER_LONG_NODE = 100.47390909;
    public const JUPITER_LONG_NODE_RATE = -0.60212770;

    // Saturn
    public const SATURN_A = 9.53667594;
    public const SATURN_A_RATE = -0.00125060;
    public const SATURN_E = 0.05386179;
    public const SATURN_E_RATE = -0.00050991;
    public const SATURN_I = 2.48599187;
    public const SATURN_I_RATE = 0.00193609;
    public const SATURN_L = 49.95424795;
    public const SATURN_L_RATE = 1222.49362201;
    public const SATURN_LONG_PERIHELION = 92.59887831;
    public const SATURN_LONG_PERIHELION_RATE = -0.41897216;
    public const SATURN_LONG_NODE = 113.66242448;
    public const SATURN_LONG_NODE_RATE = -0.28313458;

    // Uranus
    public const URANUS_A = 19.18916464;
    public const URANUS_A_RATE = -0.00196176;
    public const URANUS_E = 0.04725744;
    public const URANUS_E_RATE = -0.00004397;
    public const URANUS_I = 0.77263783;
    public const URANUS_I_RATE = -0.00242235;
    public const URANUS_L = 313.23810451;
    public const URANUS_L_RATE = 428.48202785;
    public const URANUS_LONG_PERIHELION = 170.95427630;
    public const URANUS_LONG_PERIHELION_RATE = 0.40805281;
    public const URANUS_LONG_NODE = 74.01692503;
    public const URANUS_LONG_NODE_RATE = -0.04180453;

    // Neptune
    public const NEPTUNE_A = 30.07010021;
    public const NEPTUNE_A_RATE = 0.00026291;
    public const NEPTUNE_E = 0.00859048;
    public const NEPTUNE_E_RATE = 0.00005105;
    public const NEPTUNE_I = 1.77004431;
    public const NEPTUNE_I_RATE = 0.00035372;
    public const NEPTUNE_L = -55.12002969;
    public const NEPTUNE_L_RATE = 218.45945325;
    public const NEPTUNE_LONG_PERIHELION = 44.96476227;
    public const NEPTUNE_LONG_PERIHELION_RATE = -0.32241464;
    public const NEPTUNE_LONG_NODE = 131.78422574;
    public const NEPTUNE_LONG_NODE_RATE = 0.00483297;

    // AU to km
    public const AU_TO_KM = 149597870.7;

    /**
     * Planet heliocentric position and velocity at unix time.
     * Returns [x, y, z, vx, vy, vz] in ecliptic coordinates (km, km/s).
     */
    public static function planetPositionVelocity(string $planet, float $unix): array
    {
        $t = ($unix - self::EPOCH_J2000) / (36525.0 * 86400.0);  // centuries from J2000

        $elements = match ($planet) {
            'Mercury' => self::keplerianElements(self::MERCURY_A, self::MERCURY_A_RATE, self::MERCURY_E, self::MERCURY_E_RATE,
                self::MERCURY_I, self::MERCURY_I_RATE, self::MERCURY_L, self::MERCURY_L_RATE,
                self::MERCURY_LONG_PERIHELION, self::MERCURY_LONG_PERIHELION_RATE,
                self::MERCURY_LONG_NODE, self::MERCURY_LONG_NODE_RATE, $t),
            'Venus' => self::keplerianElements(self::VENUS_A, self::VENUS_A_RATE, self::VENUS_E, self::VENUS_E_RATE,
                self::VENUS_I, self::VENUS_I_RATE, self::VENUS_L, self::VENUS_L_RATE,
                self::VENUS_LONG_PERIHELION, self::VENUS_LONG_PERIHELION_RATE,
                self::VENUS_LONG_NODE, self::VENUS_LONG_NODE_RATE, $t),
            'Earth' => self::keplerianElements(self::EARTH_A, self::EARTH_A_RATE, self::EARTH_E, self::EARTH_E_RATE,
                self::EARTH_I, self::EARTH_I_RATE, self::EARTH_L, self::EARTH_L_RATE,
                self::EARTH_LONG_PERIHELION, self::EARTH_LONG_PERIHELION_RATE,
                self::EARTH_LONG_NODE, self::EARTH_LONG_NODE_RATE, $t),
            'Mars' => self::keplerianElements(self::MARS_A, self::MARS_A_RATE, self::MARS_E, self::MARS_E_RATE,
                self::MARS_I, self::MARS_I_RATE, self::MARS_L, self::MARS_L_RATE,
                self::MARS_LONG_PERIHELION, self::MARS_LONG_PERIHELION_RATE,
                self::MARS_LONG_NODE, self::MARS_LONG_NODE_RATE, $t),
            'Jupiter' => self::keplerianElements(self::JUPITER_A, self::JUPITER_A_RATE, self::JUPITER_E, self::JUPITER_E_RATE,
                self::JUPITER_I, self::JUPITER_I_RATE, self::JUPITER_L, self::JUPITER_L_RATE,
                self::JUPITER_LONG_PERIHELION, self::JUPITER_LONG_PERIHELION_RATE,
                self::JUPITER_LONG_NODE, self::JUPITER_LONG_NODE_RATE, $t),
            'Saturn' => self::keplerianElements(self::SATURN_A, self::SATURN_A_RATE, self::SATURN_E, self::SATURN_E_RATE,
                self::SATURN_I, self::SATURN_I_RATE, self::SATURN_L, self::SATURN_L_RATE,
                self::SATURN_LONG_PERIHELION, self::SATURN_LONG_PERIHELION_RATE,
                self::SATURN_LONG_NODE, self::SATURN_LONG_NODE_RATE, $t),
            'Uranus' => self::keplerianElements(self::URANUS_A, self::URANUS_A_RATE, self::URANUS_E, self::URANUS_E_RATE,
                self::URANUS_I, self::URANUS_I_RATE, self::URANUS_L, self::URANUS_L_RATE,
                self::URANUS_LONG_PERIHELION, self::URANUS_LONG_PERIHELION_RATE,
                self::URANUS_LONG_NODE, self::URANUS_LONG_NODE_RATE, $t),
            'Neptune' => self::keplerianElements(self::NEPTUNE_A, self::NEPTUNE_A_RATE, self::NEPTUNE_E, self::NEPTUNE_E_RATE,
                self::NEPTUNE_I, self::NEPTUNE_I_RATE, self::NEPTUNE_L, self::NEPTUNE_L_RATE,
                self::NEPTUNE_LONG_PERIHELION, self::NEPTUNE_LONG_PERIHELION_RATE,
                self::NEPTUNE_LONG_NODE, self::NEPTUNE_LONG_NODE_RATE, $t),
            default => throw new \ValueError("Unknown planet: $planet"),
        };

        return self::orbitalToCartesian($elements);
    }

    // Meeus ch. 47 Table 47.A: periodic terms for Σl (longitude, unit 1e-6 deg) and Σr (distance, unit 1e-3 km).
    // Columns: D, M, M', F, Σl coefficient, Σr coefficient.
    private const MOON_LR_TERMS = [
        [0, 0, 1, 0, 6288774, -20905355], [2, 0, -1, 0, 1274027, -3699111],
        [2, 0, 0, 0, 658314, -2955968], [0, 0, 2, 0, 213618, -569925],
        [0, 1, 0, 0, -185116, 48888], [0, 0, 0, 2, -114332, -3149],
        [2, 0, -2, 0, 58793, 246158], [2, -1, -1, 0, 57066, -152138],
        [2, 0, 1, 0, 53322, -170733], [2, -1, 0, 0, 45758, -204586],
        [0, 1, -1, 0, -40923, -129620], [1, 0, 0, 0, -34720, 108743],
        [0, 1, 1, 0, -30383, 104755], [2, 0, 0, -2, 15327, 10321],
        [0, 0, 1, 2, -12528, 0], [0, 0, 1, -2, 10980, 79661],
        [4, 0, -1, 0, 10675, -34782], [0, 0, 3, 0, 10034, -23210],
        [4, 0, -2, 0, 8548, -21636], [2, 1, -1, 0, -7888, 24208],
        [2, 1, 0, 0, -6766, 30824], [1, 0, -1, 0, -5163, -8379],
        [1, 1, 0, 0, 4987, -16675], [2, -1, 1, 0, 4036, -12831],
        [2, 0, 2, 0, 3994, -10445], [4, 0, 0, 0, 3861, -11650],
        [2, 0, -3, 0, 3665, 14403], [0, 1, -2, 0, -2689, -7003],
        [2, 0, -1, 2, -2602, 0], [2, -1, -2, 0, 2390, 10056],
        [1, 0, 1, 0, -2348, 6322], [2, -2, 0, 0, 2236, -9884],
        [0, 1, 2, 0, -2120, 5751], [0, 2, 0, 0, -2069, 0],
        [2, -2, -1, 0, 2048, -4950], [2, 0, 1, -2, -1773, 4130],
        [2, 0, 0, 2, -1595, 0], [4, -1, -1, 0, 1215, -3958],
        [0, 0, 2, 2, -1110, 0], [3, 0, -1, 0, -892, 3258],
        [2, 1, 1, 0, -810, 2616], [4, -1, -2, 0, 759, -1897],
        [0, 2, -1, 0, -713, -2117], [2, 2, -1, 0, -700, 2354],
        [2, 1, -2, 0, 691, 0], [2, -1, 0, -2, 596, 0],
        [4, 0, 1, 0, 549, -1423], [0, 0, 4, 0, 537, -1117],
        [4, -1, 0, 0, 520, -1571], [1, 0, -2, 0, -487, -1739],
        [2, 1, 0, -2, -399, 0], [0, 0, 2, -2, -381, -4421],
        [1, 1, 1, 0, 351, 0], [3, 0, -2, 0, -340, 0],
        [4, 0, -3, 0, 330, 0], [2, -1, 2, 0, 327, 0],
        [0, 2, 1, 0, -323, 1165], [1, 1, -1, 0, 299, 0],
        [2, 0, 3, 0, 294, 0], [2, 0, -1, -2, 0, 8752],
    ];

    // Meeus ch. 47 Table 47.B: periodic terms for Σb (latitude, unit 1e-6 deg). Columns: D, M, M', F, coefficient.
    private const MOON_B_TERMS = [
        [0, 0, 0, 1, 5128122], [0, 0, 1, 1, 280602], [0, 0, 1, -1, 277693],
        [2, 0, 0, -1, 173237], [2, 0, -1, 1, 55413], [2, 0, -1, -1, 46271],
        [2, 0, 0, 1, 32573], [0, 0, 2, 1, 17198], [2, 0, 1, -1, 9266],
        [0, 0, 2, -1, 8822], [2, -1, 0, -1, 8216], [2, 0, -2, -1, 4324],
        [2, 0, 1, 1, 4200], [2, 1, 0, -1, -3359], [2, -1, -1, 1, 2463],
        [2, -1, 0, 1, 2211], [2, -1, -1, -1, 2065], [0, 1, -1, -1, -1870],
        [4, 0, -1, -1, 1828], [0, 1, 0, 1, -1794], [0, 0, 0, 3, -1749],
        [0, 1, -1, 1, -1565], [1, 0, 0, 1, -1491], [0, 1, 1, 1, -1475],
        [0, 1, 1, -1, -1410], [0, 1, 0, -1, -1344], [1, 0, 0, -1, -1335],
        [0, 0, 3, 1, 1107], [4, 0, 0, -1, 1021], [4, 0, -1, 1, 833],
        [0, 0, 1, -3, 777], [4, 0, -2, 1, 671], [2, 0, 0, -3, 607],
        [2, 0, 2, -1, 596], [2, -1, 1, -1, 491], [2, 0, -2, 1, -451],
        [0, 0, 3, -1, 439], [2, 0, 2, 1, 422], [2, 0, -3, -1, 421],
        [2, 1, -1, 1, -366], [2, 1, 0, 1, -351], [4, 0, 0, 1, 331],
        [2, -1, 1, 1, 315], [2, -2, 0, -1, 302], [0, 0, 1, 3, -283],
        [2, 1, 1, -1, -229], [1, 1, 0, -1, 223], [1, 1, 0, 1, 223],
        [0, 1, -2, -1, -220], [2, 1, -1, -1, -220], [1, 0, 1, 1, -185],
        [2, -1, -2, -1, 181], [0, 1, 2, 1, -177], [4, 0, -2, -1, 176],
        [4, -1, -1, -1, 166], [1, 0, 1, -1, -164], [4, 0, 1, -1, 132],
        [1, 0, -1, -1, -119], [4, -1, 0, -1, 115], [2, -2, 0, 1, 107],
    ];

    /** General precession in longitude since J2000, arcsec per Julian century (IAU; adequate for ecliptic latitude). */
    private const GENERAL_PRECESSION = 5029.0966;

    /**
     * Moon's ecliptic longitude, latitude and distance at unix time, mean equinox of date (Meeus ch. 47, full
     * 47.A/47.B tables). Used internally; exposed for validation against Meeus' worked example 47.a.
     * Returns [lambda_deg, beta_deg, distance_km].
     */
    public static function moonEclipticOfDate(float $unix): array
    {
        $t = ($unix - self::EPOCH_J2000) / (36525.0 * 86400.0);  // Julian centuries from J2000.0 (TT)
        $t2 = $t * $t;
        $t3 = $t2 * $t;
        $t4 = $t3 * $t;

        // Mean arguments (degrees), Meeus 47.1-47.5
        $Lp = 218.3164477 + 481267.88123421 * $t - 0.0015786 * $t2 + $t3 / 538841.0 - $t4 / 65194000.0;
        $D = 297.8501921 + 445267.1114034 * $t - 0.0018819 * $t2 + $t3 / 545868.0 - $t4 / 113065000.0;
        $M = 357.5291092 + 35999.0502909 * $t - 0.0001536 * $t2 + $t3 / 24490000.0;
        $Msp = 134.9633964 + 477198.8675055 * $t + 0.0087414 * $t2 + $t3 / 69699.0 - $t4 / 14712000.0;
        $F = 93.2720950 + 483202.0175233 * $t - 0.0036539 * $t2 - $t3 / 3526000.0 + $t4 / 863310000.0;

        $D_rad = deg2rad(fmod($D, 360.0));
        $M_rad = deg2rad(fmod($M, 360.0));
        $Msp_rad = deg2rad(fmod($Msp, 360.0));
        $F_rad = deg2rad(fmod($F, 360.0));

        // Eccentricity correction for terms with Sun's mean anomaly (Earth's orbit grows less eccentric with time).
        $E = 1.0 - 0.002516 * $t - 0.0000074 * $t2;

        $sigmaL = 0.0;
        $sigmaR = 0.0;
        foreach (self::MOON_LR_TERMS as [$d, $mc, $mpc, $fc, $lCoeff, $rCoeff]) {
            $arg = $d * $D_rad + $mc * $M_rad + $mpc * $Msp_rad + $fc * $F_rad;
            $e = $mc === 0 ? 1.0 : ($mc === 1 || $mc === -1 ? $E : $E * $E);
            $sigmaL += $lCoeff * $e * sin($arg);
            $sigmaR += $rCoeff * $e * cos($arg);
        }

        $sigmaB = 0.0;
        foreach (self::MOON_B_TERMS as [$d, $mc, $mpc, $fc, $bCoeff]) {
            $arg = $d * $D_rad + $mc * $M_rad + $mpc * $Msp_rad + $fc * $F_rad;
            $e = $mc === 0 ? 1.0 : ($mc === 1 || $mc === -1 ? $E : $E * $E);
            $sigmaB += $bCoeff * $e * sin($arg);
        }

        // Additive planetary perturbation terms (Meeus p. 338), degrees.
        $A1 = deg2rad(fmod(119.75 + 131.849 * $t, 360.0));
        $A2 = deg2rad(fmod(53.09 + 479264.290 * $t, 360.0));
        $A3 = deg2rad(fmod(313.45 + 481266.484 * $t, 360.0));
        $Lp_rad = deg2rad(fmod($Lp, 360.0));

        $sigmaL += 3958.0 * sin($A1) + 1962.0 * sin($Lp_rad - $F_rad) + 318.0 * sin($A2);
        $sigmaB += -2235.0 * sin($Lp_rad) + 382.0 * sin($A3) + 175.0 * sin($A1 - $F_rad)
            + 175.0 * sin($A1 + $F_rad) + 127.0 * sin($Lp_rad - $Msp_rad) - 115.0 * sin($Lp_rad + $Msp_rad);

        $lambda_deg = fmod($Lp + $sigmaL / 1000000.0, 360.0);
        if ($lambda_deg < 0.0) {
            $lambda_deg += 360.0;
        }
        $beta_deg = $sigmaB / 1000000.0;
        $distance = 385000.56 + $sigmaR / 1000.0;

        return [$lambda_deg, $beta_deg, $distance, $t];
    }

    /**
     * Geocentric lunar position at unix time.
     * Returns [x, y, z] in J2000 ecliptic coordinates (km), relative to Earth's center.
     * Uses the full Meeus ch. 47 lunar theory, converted from the mean equinox of date to J2000 by removing the
     * accumulated general precession in longitude (adequate for our error budget; latitude is left as-is).
     */
    public static function moonPositionGeocentric(float $unix): array
    {
        [$lambda_deg, $beta_deg, $distance, $t] = self::moonEclipticOfDate($unix);

        $lambda_j2000 = deg2rad($lambda_deg - self::GENERAL_PRECESSION * $t / 3600.0);
        $beta = deg2rad($beta_deg);

        $cos_beta = cos($beta);
        $x = $distance * cos($lambda_j2000) * $cos_beta;
        $y = $distance * sin($lambda_j2000) * $cos_beta;
        $z = $distance * sin($beta);

        return [$x, $y, $z];
    }

    private static function keplerianElements(float $a0, float $a_rate, float $e0, float $e_rate,
        float $i0, float $i_rate, float $l0, float $l_rate, float $w0, float $w_rate,
        float $omega0, float $omega_rate, float $t): array
    {
        $a = ($a0 + $a_rate * $t) * self::AU_TO_KM;
        $e = $e0 + $e_rate * $t;
        $i = deg2rad($i0 + $i_rate * $t);
        $l = deg2rad($l0 + $l_rate * $t);
        $w = deg2rad($w0 + $w_rate * $t);
        $omega = deg2rad($omega0 + $omega_rate * $t);

        // Mean anomaly
        $m = $l - $w;
        $m = fmod($m, 2 * M_PI);
        if ($m < 0) $m += 2 * M_PI;

        // Solve Kepler equation: E - e*sin(E) = M
        $E = $m;
        for ($iter = 0; $iter < 5; ++$iter) {
            $E = $m + $e * sin($E);
        }

        // True anomaly
        $nu = 2 * atan2(sqrt(1 + $e) * sin($E / 2), sqrt(1 - $e) * cos($E / 2));

        // Distance
        $r = $a * (1 - $e * cos($E));

        return [$a, $e, $i, $omega, $w, $nu, $r];
    }

    private static function orbitalToCartesian(array $elements): array
    {
        [$a, $e, $i, $omega, $w, $nu, $r] = $elements;

        // Orbital frame position
        $x_orb = $r * cos($nu);
        $y_orb = $r * sin($nu);
        $z_orb = 0.0;

        // Rotate to ecliptic frame: w (argument of perihelion), i (inclination), omega (ascending node)
        $cw = cos($w);
        $sw = sin($w);
        $ci = cos($i);
        $si = sin($i);
        $co = cos($omega);
        $so = sin($omega);

        $x = ($cw * $co - $sw * $so * $ci) * $x_orb + (-$sw * $co - $cw * $so * $ci) * $y_orb;
        $y = ($cw * $so + $sw * $co * $ci) * $x_orb + (-$sw * $so + $cw * $co * $ci) * $y_orb;
        $z = $sw * $si * $x_orb + $cw * $si * $y_orb;

        // Velocity (approximate: tangent to orbit)
        $n = sqrt(1.327e11 / ($a * $a * $a)); // mean motion, km/s (G*M_sun)
        $vx_orb = -$n * $a * sin($nu) / sqrt(1 - $e * $e);
        $vy_orb = $n * $a * (1 + $e * cos($nu)) / sqrt(1 - $e * $e);

        $vx = ($cw * $co - $sw * $so * $ci) * $vx_orb + (-$sw * $co - $cw * $so * $ci) * $vy_orb;
        $vy = ($cw * $so + $sw * $co * $ci) * $vx_orb + (-$sw * $so + $cw * $co * $ci) * $vy_orb;
        $vz = $sw * $si * $vx_orb + $cw * $si * $vy_orb;

        return [$x, $y, $z, $vx, $vy, $vz];
    }
}
