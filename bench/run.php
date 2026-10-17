<?php

/**
 * Throughput benchmark, not a correctness test: four general scenes, each simulated for 5 s of game time with
 * world->tick(), no rendering, no real-time sleeping. Run with `php bench/run.php` (php8.4, from this tree or a
 * worktree of an older commit - it only ever uses its own __DIR__, so copying it alone into a worktree works).
 *
 * Scenes 1-3 reuse the demo's own classes (server/src/RocksGame.php, Ragdoll.php) directly, built without Swerve or
 * the pub/sub loop: the server's constructor logic is replicated here instead of calling RocksGame's private
 * constructor, so nothing in server/src needed to change. Scene 4 (gravity) is Phase 1 only and does not exist
 * before commit 5db96ce; pass --skip-gravity to omit it against an older tree.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../server/src/RocksGame.php';   // Ball, Rock, Marble, Observer: not PSR-4 (one file, four classes)
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PhameServer\\')) {
        $path = __DIR__ . '/../server/src/' . substr($class, strlen('PhameServer\\')) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Phame\Physics\Contact;
use Phame\Physics\Wall;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PhameServer\Ball;
use PhameServer\Limb;
use PhameServer\Marble;
use PhameServer\Observer;
use PhameServer\Ragdoll;
use PhameServer\Rock;

/** User + system CPU time of this process, in milliseconds. */
function cpuMs(): float
{
    $u = getrusage();
    return ($u['ru_utime.tv_sec'] * 1e6 + $u['ru_utime.tv_usec'] + $u['ru_stime.tv_sec'] * 1e6 + $u['ru_stime.tv_usec']) / 1000.0;
}

/**
 * Runs $build() in a fresh world for $seconds of game time, in $slices sub-ticks (so gc_status() can be sampled
 * between them), counting every contact. Returns CPU ms, contacts, and GC activity: the busiest slice's runs and
 * collected (peak) alongside the total over the whole run (final).
 *
 * @return array{ms: float, events: int, peakRuns: int, finalRuns: int, peakCollected: int, finalCollected: int}
 */
function runScene(\Closure $build, float $seconds = 5.0, int $slices = 25): array
{
    // $build() returns a third element, $onStart: anything that itself starts coroutines (Ragdoll::launch(), via
    // World::go()) must run inside World::run()'s own driver, not before it - World::run() opens its own phasync
    // scope, and coroutines started outside that scope are never cancelled by it, which stalls phasync's deadlock
    // detector once enough of them pile up parked. Plain scene construction (bodies, no world->go()) has no such
    // requirement and can run outside run() as usual.
    [$world, $events, $onStart] = $build();
    [$peakRuns, $peakCollected, $totalRuns, $totalCollected] = [0, 0, 0, 0];
    $slice = $seconds / $slices;
    $t0 = null;
    \phasync::run(static function () use ($world, $onStart, $slice, $slices, &$t0, &$peakRuns, &$peakCollected, &$totalRuns, &$totalCollected) {
        $world->run(static function (World $w) use ($onStart, $slice, $slices, &$t0, &$peakRuns, &$peakCollected, &$totalRuns, &$totalCollected) {
            $onStart?->__invoke($w);
            $t0 = cpuMs();   // excludes one-time scene setup (including launch()) from the measured throughput
            $last = gc_status();
            for ($i = 0; $i < $slices; ++$i) {
                $w->tick($slice);
                $now = gc_status();
                $dRuns = $now['runs'] - $last['runs'];
                $dCollected = $now['collected'] - $last['collected'];
                $peakRuns = max($peakRuns, $dRuns);
                $peakCollected = max($peakCollected, $dCollected);
                $totalRuns += $dRuns;
                $totalCollected += $dCollected;
                $last = $now;
            }
        });
    });
    $ms = cpuMs() - $t0;
    $world->dispose();
    return ['ms' => $ms, 'events' => $events->count, 'peakRuns' => $peakRuns, 'finalRuns' => $totalRuns, 'peakCollected' => $peakCollected, 'finalCollected' => $totalCollected];
}

/** A shared mutable counter, since a closure can't increment a captured scalar it also returns by value. */
final class Counter
{
    public int $count = 0;
}

/** RocksGame's own box and placement, minus Swerve: 700 rocks, 12 marbles, same radii, speeds and seed. */
function placeRocksAndMarbles(Scene3D $scene, float $s): array
{
    mt_srand(7);
    $palette = [0x8a7f74, 0x9c8b77, 0x6f6a66, 0xb09a84, 0x7d7266, 0x5f5a58];
    $glass = [0x7fd6ff, 0xff8fb8, 0x9dff9a, 0xffd36e, 0xc59bff, 0x6ef0e0];
    $rocks = [];
    $marbles = 12;
    $total = 700 + $marbles;
    for ($i = 0; $i < $total; ++$i) {
        $marble = $i < $marbles;
        $color = $marble ? $glass[$i % count($glass)] : $palette[$i % count($palette)];
        $ball = $marble ? new Marble($color) : new Rock($color);
        $ball->radius = $marble ? mt_rand(330, 400) / 100 : mt_rand(50, 180) / 100;
        $ball->mass = ($marble ? 1.6 : 1.0) * $ball->radius ** 3;
        $span = (int) (($s - $ball->radius - 0.5) * 10);
        do {
            [$x, $y, $z] = [mt_rand(-$span, $span) / 10, mt_rand(-$span, $span) / 10, mt_rand(-$span, $span) / 10];
            $free = true;
            foreach ($rocks as $o) {
                if ((($o->x - $x) ** 2 + ($o->y - $y) ** 2 + ($o->z - $z) ** 2) < ($o->radius + $ball->radius + 0.2) ** 2) {
                    $free = false;
                    break;
                }
            }
        } while (!$free);
        $ball->moveTo($x, $y, $z);
        $speed = $marble ? 25 : 40;
        $ball->setVelocity(mt_rand(-$speed, $speed) / 10, mt_rand(-$speed, $speed) / 10, mt_rand(-$speed, $speed) / 10);
        $scene->root->addChild($ball);
        $rocks[] = $ball;
    }
    return $rocks;
}

/** @return array{World, Counter, Scene3D, list<Ball>} the pieces the three ball-based scenes below share */
function makeBoxWorld(): array
{
    $world = new World();
    $scene = $world->stage->addChild(new Scene3D(100, 100));
    $s = 40.0;
    [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-$s, -$s, -$s];
    [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [$s, $s, $s];
    $events = new Counter();
    $world->onCollision(function (Ball $a, Ball $b, Contact $contact) use ($events) {
        ++$events->count;
        $impulse = $contact->impulse(1.0);
        $a->bounced($contact, $impulse);
        $b->bounced($contact, $impulse);
    });
    $world->onCollision(function (Limb $limb, Ball $ball, Contact $contact) use ($events) {
        ++$events->count;
        $limb->doll->hit($limb, $ball, $contact);
    });
    $world->onCollision(function (Ball $ball, Wall $wall, Contact $contact) use ($events) {
        ++$events->count;
        $ball->hitWall($wall);
    });
    $rocks = placeRocksAndMarbles($scene, $s);
    return [$world, $events, $scene, $rocks];
}

/** RocksGame's own box, elastic (restitution 1), minus Swerve. */
function buildCrowdedBox(): array
{
    [$world, $events] = makeBoxWorld();
    return [$world, $events, null];
}

/** The crowded box plus 20 ragdolls thrown the way RocksGame::drop() does, from one fixed vantage point. */
function buildRagdolls(): array
{
    [$world, $events, $scene] = makeBoxWorld();
    $by = new Observer('bench', 0xffffff);
    $by->moveTo(0.0, 30.0, 150.0);
    [$lx, $ly, $lz] = [$by->lookX, $by->lookY, $by->lookZ];
    $n = sqrt($lx * $lx + $lz * $lz) ?: 1.0;
    $dolls = [];
    for ($i = 0; $i < 20; ++$i) {
        $doll = new Ragdoll($by, Ragdoll::TYPES[$i % count(Ragdoll::TYPES)]);
        foreach ($doll->nodes() as $node) {
            $node->published = true;
        }
        $scene->root->addChild($doll);
        $dolls[] = $doll;
    }
    // launch() calls World::go(): it must run inside World::run()'s own driver (see runScene()), not here
    $onStart = static function (World $world) use ($dolls, $by, $lx, $ly, $lz, $n) {
        foreach ($dolls as $doll) {
            $doll->launch(
                $world,
                [$by->x + $lx * 18.0, $by->y + $ly * 18.0, $by->z + $lz * 18.0],
                [$lx * 34.0, $ly * 34.0, $lz * 34.0],
                [-$lz / $n * 3.0 + $lx, $ly, $lx / $n * 3.0 + $lz],
            );
        }
    };
    return [$world, $events, $onStart];
}

/** No walls: 2000 bodies sparse over 10,000 units, log-uniform radii 0.01-100, 50 bullets at 500 units/s. */
function buildOpenWorld(): array
{
    $world = new World();
    $scene = $world->stage->addChild(new Scene3D(100, 100));   // default bounds: -INF..INF, so no walls
    $events = new Counter();
    $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use ($events) {
        ++$events->count;
        $j = $c->impulse(1.0);
        $ka = $c->sign($a) * $j / $a->mass;
        $a->setVelocity($a->velocityX + $ka * $c->nx, $a->velocityY + $ka * $c->ny, $a->velocityZ + $ka * $c->nz);
        $kb = $c->sign($b) * $j / $b->mass;
        $b->setVelocity($b->velocityX + $kb * $c->nx, $b->velocityY + $kb * $c->ny, $b->velocityZ + $kb * $c->nz);
    });
    mt_srand(11);
    $half = 5000.0;
    for ($i = 0; $i < 2000; ++$i) {
        $body = new Node3D();
        $body->confined = false;
        $body->radius = 0.01 * (100.0 / 0.01) ** (mt_rand() / mt_getrandmax());
        $body->mass = $body->radius ** 3;
        $body->moveTo(mt_rand((int) -$half, (int) $half), mt_rand((int) -$half, (int) $half), mt_rand((int) -$half, (int) $half));
        $body->setVelocity(mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10, mt_rand(-20, 20) / 10);
        $scene->root->addChild($body);
    }
    for ($i = 0; $i < 50; ++$i) {
        $bullet = new Node3D();
        $bullet->confined = false;
        $bullet->radius = 0.2;
        $bullet->mass = 0.01;
        $bullet->moveTo(mt_rand((int) -$half, (int) $half), mt_rand((int) -$half, (int) $half), mt_rand((int) -$half, (int) $half));
        [$dx, $dy, $dz] = [mt_rand(-100, 100), mt_rand(-100, 100), mt_rand(-100, 100)];
        $d = sqrt($dx * $dx + $dy * $dy + $dz * $dz) ?: 1.0;
        $bullet->setVelocity($dx / $d * 500.0, $dy / $d * 500.0, $dz / $d * 500.0);
        $scene->root->addChild($bullet);
    }
    return [$world, $events, null];
}

/** The crowded box again, with gravity on the scene root and a restitution under 1 (Phase 1 only). */
function buildGravityBox(): array
{
    $world = new World();
    $scene = $world->stage->addChild(new Scene3D(100, 100));
    $s = 40.0;
    [$scene->worldMinX, $scene->worldMinY, $scene->worldMinZ] = [-$s, -$s, -$s];
    [$scene->worldMaxX, $scene->worldMaxY, $scene->worldMaxZ] = [$s, $s, $s];
    // -9.8 piles 712 bodies into the floor within the 5 s window and hits the Zeno limit of today's engine (no
    // contact joints yet, Phase 4): every pair at rest keeps re-triggering ever-closer events, so game time nearly
    // stalls. -1.5 lets them settle only partway, a representative "gravity turned on" load, not a pile-up.
    $scene->root->gravityY = -1.5;
    $events = new Counter();
    $restitution = 0.85;   // under 1: settles, rather than keeping the energy forever like the elastic box
    $world->onCollision(function (Ball $a, Ball $b, Contact $contact) use ($events, $restitution) {
        ++$events->count;
        $impulse = $contact->impulse($restitution);
        $a->bounced($contact, $impulse);
        $b->bounced($contact, $impulse);
    });
    $world->onCollision(function (Ball $ball, Wall $wall, Contact $contact) use ($events, $restitution) {
        ++$events->count;
        match ($wall->axis) {
            0 => $ball->velocityX = -$restitution * $ball->velocityX,
            1 => $ball->velocityY = -$restitution * $ball->velocityY,
            default => $ball->velocityZ = -$restitution * $ball->velocityZ,
        };
    });
    placeRocksAndMarbles($scene, $s);
    return [$world, $events, null];
}

$scenes = [
    'crowded box' => buildCrowdedBox(...),
    'ragdolls' => buildRagdolls(...),
    'open world' => buildOpenWorld(...),
    'gravity' => buildGravityBox(...),
];

// one scene, one run: used only as a subprocess (see below), so that each measurement starts from a fresh process
// (memory, the cycle collector's buffers, opcache and JIT state) rather than inheriting the previous scene's
$scene = null;
$seconds = 5.0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--scene=')) {
        $scene = substr($arg, 8);
    } elseif (str_starts_with($arg, '--seconds=')) {
        $seconds = (float) substr($arg, 10);   // a short run for the smoke test (tests/BenchTest.php)
    }
}
if ($scene !== null) {
    if (!isset($scenes[$scene])) {
        fwrite(STDERR, "unknown scene: $scene\n");
        exit(1);
    }
    echo json_encode(runScene($scenes[$scene], $seconds)) . "\n";
    exit(0);
}

printf("%-14s %8s %8s %10s %12s %10s %14s\n", 'scene', 'run', 'ms', 'events', 'ev/s', 'gc runs', 'gc collected');
$php = PHP_BINARY;
$names = array_keys($scenes);
if (in_array('--skip-gravity', $argv, true)) {
    $names = array_values(array_diff($names, ['gravity']));
}
foreach ($names as $name) {
    $runs = [];
    for ($r = 1; $r <= 3; ++$r) {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg('--scene=' . $name);
        $out = shell_exec($cmd);
        $result = json_decode(trim((string) $out), true);
        if (!is_array($result)) {
            fwrite(STDERR, "scene '$name' run $r failed: " . $out . "\n");
            exit(1);
        }
        $runs[] = $result;
        printf(
            "%-14s %8d %8.1f %10d %12.1f %10s %14s\n",
            $name,
            $r,
            $result['ms'],
            $result['events'],
            $result['events'] / 5.0,
            "{$result['finalRuns']}(peak {$result['peakRuns']})",
            "{$result['finalCollected']}(peak {$result['peakCollected']})",
        );
    }
    $sorted = $runs;
    usort($sorted, static fn ($a, $b) => $a['ms'] <=> $b['ms']);
    $median = $sorted[1];
    printf(
        "%-14s %8s %8.1f %10d %12.1f %10s %14s\n\n",
        $name,
        'median',
        $median['ms'],
        $median['events'],
        $median['events'] / 5.0,
        "{$median['finalRuns']}(peak {$median['peakRuns']})",
        "{$median['finalCollected']}(peak {$median['peakCollected']})",
    );
}
