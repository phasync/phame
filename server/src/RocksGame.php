<?php

namespace PhameServer;

use Phame\Net\View;
use Phame\Physics\{Contact, Wall};
use Phame\Rendering\MeshShader;
use Phame\Scene\{Geometry, Material, Mesh, Scene3D};
use Phame\World;
use Swerve\Swerve;

/** Something round that bounces: its class decides how it reacts to what the engine reports. A Mesh, so Net\View
 * shows it like any other node - its own radius sizes both the physics sphere and the drawn one (via setScale()). */
abstract class Ball extends Mesh
{
    private static ?Geometry $sphere = null;
    /** @var array<class-string, MeshShader> one MeshShader per concrete class. */
    private static array $shaders = [];
    /** @var array<string, Material> one Material per (class, colour) pair, not one per ball: Net\View sends a
     * Material's fields - including its shader's GLSL, a few KB - inline, every time that *Material object* is new
     * (see docs/REMOTE-VIEW.md), regardless of whether its ->shader happens to be shared; a distinct Material per
     * ball (needed, in View's model, for a per-ball colour) still meant the same GLSL repeated up to 700 times, a
     * single snapshot chunk running to a megabyte against Swerve's 128 KiB ceiling (silently: the oversized publish
     * just throws, which looks identical to the game never starting). The palette is only 6 colours - every rock
     * (or marble) of the same colour now shares one Material, the node's own radius/position staying independent. */
    private static array $materials = [];

    public function __construct(int $color)
    {
        $shader = self::$shaders[static::class] ??= static::material();
        $material = self::$materials[static::class . '#' . $color] ??= new Material(color: $color, shader: $shader);
        parent::__construct(self::$sphere ??= Geometry::sphere(1.0), $material);
    }

    abstract public static function material(): MeshShader;

    public function bounced(Contact $contact, float $impulse): void
    {
        $k = $contact->sign($this) * $impulse / $this->mass;
        $this->setVelocity($this->velocityX + $k * $contact->nx, $this->velocityY + $k * $contact->ny, $this->velocityZ + $k * $contact->nz);
    }

    public function hitWall(Wall $wall): void
    {
        $vx = $wall->axis === 0 ? -$this->velocityX : $this->velocityX;
        $vy = $wall->axis === 1 ? -$this->velocityY : $this->velocityY;
        $vz = $wall->axis === 2 ? -$this->velocityZ : $this->velocityZ;
        // the walls take some energy from a rock a boulder sent flying, so the box calms down again
        $speed = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
        $k = $speed > 7.0 ? max(7.0 / $speed, 0.8) : 1.0;
        $this->setVelocity($vx * $k, $vy * $k, $vz * $k);
    }
}

final class Rock extends Ball
{
    /** How rocks look: Phame's shade() contract, the same GLSL a local engine would use. */
    public static function material(): MeshShader
    {
        return new MeshShader(<<<'GLSL'
            vec4 shade() {
                vec3 n = normalize(vNormal);
                vec3 p = vLocal * 2.6 + vSeed * 31.0;                 // detail lives on the rock, so it tumbles with it
                float h = fbm(p), e = 0.02;
                vec3 grad = vec3(fbm(p + vec3(e, 0, 0)), fbm(p + vec3(0, e, 0)), fbm(p + vec3(0, 0, e))) - h;
                float crater = smoothstep(0.62, 0.7, noise(vLocal * 4.0 + vSeed * 7.0));
                vec3 bump = normalize(n - grad / e * 0.55);              // bumps and grain
                float cavity = smoothstep(0.25, 0.75, h) * (1.0 - 0.5 * crater);
                vec3 albedo = vColor * (0.65 + 0.55 * h) * mix(vec3(1.0), vec3(0.75, 0.68, 0.6), crater) * (0.85 + 0.3 * fract(vSeed * 13.7));
                vec3 view = normalize(uEye - vWorldPos);
                float diffuse = max(dot(bump, SUN), 0.0);
                vec3 sky = mix(vec3(0.03, 0.025, 0.02), vec3(0.08, 0.1, 0.18), bump.y * 0.5 + 0.5);
                float spec = pow(max(dot(reflect(-SUN, bump), view), 0.0), 24.0) * 0.12;
                float rim = pow(1.0 - max(dot(bump, view), 0.0), 3.0);
                return vec4(albedo * (sky * (0.6 + 0.6 * cavity) + vec3(1.6, 1.4, 1.15) * diffuse * (0.55 + 0.45 * cavity)) + spec + vec3(0.18, 0.26, 0.5) * rim * 0.5, 1.0);
            }
            GLSL);
    }
}

/** Someone watching: a camera in the world, moved by that viewer's input. It takes no part in collisions (no
 * physics radius is set - only its visual scale). */
final class Observer extends Mesh
{
    private static ?Geometry $body = null;
    private static ?MeshShader $shader = null;
    /** @var array<int, Material> one per colour (see Ball's $materials for why this matters). */
    private static array $materials = [];
    /** When (Unix time) the viewer's socket last spoke for them: a camera that goes quiet has gone. */
    public float $heard;
    /** Where the camera looks (a unit vector): down its -z, turned by its orientation. */
    public float $lookX = 0.0;
    public float $lookY = 0.0;
    public float $lookZ = -1.0;
    public float $droppedAt = 0.0;
    public int $ragdolls = 0;

    public function __construct(public readonly string $client, int $color)
    {
        $shader = self::$shader ??= self::material();
        $material = self::$materials[$color] ??= new Material(color: $color, shader: $shader);
        parent::__construct(self::$body ??= Geometry::box(1.2, 0.84, 1.0), $material);
        $this->heard = microtime(true);
    }

    /**
     * The viewer moved their camera: it teleports there and stays. Input is uneven, so a velocity guessed from it would
     * overshoot on every change of pace; a controlled object glides only when the game gives it momentum.
     */
    public function moved(array $position, array $orientation): void
    {
        $this->moveTo(...array_map('floatval', $position));
        $this->setVelocity(0.0, 0.0, 0.0);
        [$x, $y, $z, $w] = array_map('floatval', $orientation);
        $this->setOrientation($x, $y, $z, $w);
        [$this->lookX, $this->lookY, $this->lookZ] = [-2.0 * ($x * $z + $w * $y), -2.0 * ($y * $z - $w * $x), -(1.0 - 2.0 * ($x * $x + $y * $y))];
    }

    public static function material(): MeshShader
    {
        return new MeshShader(<<<'GLSL'
            vec4 shade() {
                vec3 n = normalize(vNormal);
                vec3 view = normalize(uEye - vWorldPos);
                float rim = pow(1.0 - max(dot(n, view), 0.0), 2.0);
                vec3 body = vColor * (0.25 + 0.75 * max(dot(n, SUN), 0.0)) + vColor * rim * 1.5;
                return vec4(body + vColor * 0.35, 1.0);
            }
            GLSL);
    }
}

/** A glass marble: larger and heavier than most rocks. */
final class Marble extends Ball
{
    /** Glass: Fresnel reflection of the environment, a tinted interior with swirls, a sharp glint. */
    public static function material(): MeshShader
    {
        return new MeshShader(<<<'GLSL'
            vec4 shade() {
                vec3 n = normalize(vWorldPos - vCenter);                 // a perfect sphere
                vec3 view = normalize(uEye - vWorldPos);
                float cosine = max(dot(n, view), 0.0);
                float fresnel = 0.04 + 0.96 * pow(1.0 - cosine, 5.0);
                vec3 inside = refract(-view, n, 1.0 / 1.5);
                vec3 q = (vWorldPos - vCenter) / vRadius + inside * 0.9;
                float swirl = smoothstep(0.55, 0.75, fbm(q * 2.2 + vSeed * 17.0)) * smoothstep(1.05, 0.2, length(q));
                vec3 transmitted = (environment(inside) + vec3(0.12)) * mix(vec3(1.0), vColor, 0.75) * exp(-2.0 * cosine * 0.25) + vColor * (0.25 + swirl * 2.2);
                vec3 c = mix(transmitted, environment(reflect(-view, n)), fresnel);
                c += vec3(2.2, 1.9, 1.5) * pow(max(dot(reflect(-SUN, n), view), 0.0), 900.0);
                return vec4(c, clamp(0.72 + fresnel * 0.28 + swirl * 0.3, 0.0, 1.0));
            }
            GLSL);
    }
}

/**
 * Rocks floating weightless in a box, colliding. Runs in one worker as a phasync service; viewers anywhere talk to it
 * through swerve's publish/subscribe: joins on phame:rocks:in, then a Net\View\Viewer and whatever it is shown (see
 * docs/REMOTE-VIEW.md) - this class has no wire format of its own any more.
 */
final class RocksGame
{
    public const SIZE = 40.0;
    public const ROCKS = 700;
    public const MARBLES = 12;
    private static ?self $instance = null;
    /** @var resource|null the lock that makes this worker the game's only owner */
    private static $lock = null;
    private static bool $claiming = false;

    public readonly World $world;
    public readonly Scene3D $scene;
    public readonly View $view;
    /** @var array<int, Ball> */
    private array $rocks = [];
    /** @var array<int, Ragdoll> the ragdolls in flight */
    private array $ragdolls = [];
    /** How many were thrown: the next is the next type. */
    private int $thrown = 0;
    /** @var array<string, Observer> by client */
    private array $observers = [];
    /** @var array<string, \Phame\Net\Viewer> by client */
    private array $viewers = [];
    private float $started;

    /** Every other game this worker runs alongside this one, once it wins the election - a separate method so a
     * test can call it on its own, without a lock file or a Swerve server, and catch a forgotten ::start() here. */
    private static function startCompanions(): void
    {
        SolarGame::start();
    }

    /**
     * Makes sure that exactly one worker runs the game: each worker tries a lock now and then, and the one holding it
     * runs the game. When that worker goes (a recycle, a crash), the lock is released and another takes over.
     */
    public static function ensureRunning(): void
    {
        if (self::$claiming) {
            return;
        }
        self::$claiming = true;
        \phasync::service(static function () {
            while (self::$instance === null) {
                $lock = fopen(sys_get_temp_dir() . '/phame-rocks.lock', 'c');
                if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                    self::$lock = $lock;   // held for the life of this worker
                    try {
                        self::$instance = new self();
                        self::startCompanions();
                        self::$instance->run();
                    } catch (\Throwable $e) {   // log it, and let another worker (or this one) start a new game
                        error_log('phame rocks game stopped: ' . $e);
                        self::$instance = null;
                        flock($lock, LOCK_UN);
                        fclose($lock);
                        self::$lock = null;
                        \phasync::sleep(1.0);
                        continue;
                    }
                    return;
                }
                if ($lock !== false) {
                    fclose($lock);
                }
                \phasync::sleep(1.0);
            }
        });
    }

    /** The moment (Unix time) the running game started: game time is real time since then. */
    public float $epoch {
        get => $this->started;
    }

    private function __construct()
    {
        $this->started = microtime(true);
        $this->world = new World();
        $this->scene = $this->world->stage->addChild(new Scene3D(100, 100));
        $this->view = $this->world->view($this->scene, new SwerveTransport(), 'phame:rocks:out');
        $s = self::SIZE;
        [$this->scene->worldMinX, $this->scene->worldMinY, $this->scene->worldMinZ] = [-$s, -$s, -$s];
        [$this->scene->worldMaxX, $this->scene->worldMaxY, $this->scene->worldMaxZ] = [$s, $s, $s];
        $this->scene->environment = <<<'GLSL'
            vec3 environment(vec3 d) {   // deep space: a nebula band, a warm sun, a cool fill
                float up = d.y * 0.5 + 0.5;
                vec3 c = mix(vec3(0.010, 0.012, 0.025), vec3(0.05, 0.07, 0.14), pow(up, 1.5));
                c += vec3(0.20, 0.12, 0.30) * pow(max(0.0, 1.0 - abs(d.y + 0.15) * 2.2), 3.0) * fbm(d * 3.0);
                c += vec3(1.8, 1.55, 1.2) * pow(max(dot(d, SUN), 0.0), 220.0);
                c += vec3(0.25, 0.35, 0.6) * pow(max(dot(d, normalize(vec3(0.6, 0.2, -0.7))), 0.0), 18.0);
                return c;
            }
            GLSL;
        $this->world->onCollision(function (Ball $a, Ball $b, Contact $contact) {
            $impulse = $contact->impulse(1.0);   // elastic: they keep their energy, so they keep colliding
            $a->bounced($contact, $impulse);
            $b->bounced($contact, $impulse);
        });
        $this->world->onCollision(function (Limb $limb, Ball $ball, Contact $contact) {
            $limb->doll->hit($limb, $ball, $contact);
        });
        $this->world->onCollision(function (Ball $ball, Wall $wall, Contact $contact) {
            $ball->hitWall($wall);
        });
        mt_srand(7);
        $palette = [0x8a7f74, 0x9c8b77, 0x6f6a66, 0xb09a84, 0x7d7266, 0x5f5a58];
        $glass = [0x7fd6ff, 0xff8fb8, 0x9dff9a, 0xffd36e, 0xc59bff, 0x6ef0e0];
        for ($i = 0; $i < self::MARBLES + self::ROCKS; ++$i) {
            $marble = $i < self::MARBLES;   // placed first, while there is room
            $radius = $marble ? mt_rand(330, 400) / 100 : mt_rand(50, 180) / 100;
            $color = $marble ? $glass[$i % count($glass)] : $palette[$i % count($palette)];
            $ball = $marble ? new Marble($color) : new Rock($color);
            $ball->radius = $radius;
            $ball->setScale($radius);
            $ball->mass = ($marble ? 1.6 : 1.0) * $radius ** 3;   // glass is denser than these rocks
            $span = (int) ((self::SIZE - $radius - 0.5) * 10);
            do {
                [$x, $y, $z] = [mt_rand(-$span, $span) / 10, mt_rand(-$span, $span) / 10, mt_rand(-$span, $span) / 10];
                $free = true;
                foreach ($this->rocks as $o) {
                    if ((($o->x - $x) ** 2 + ($o->y - $y) ** 2 + ($o->z - $z) ** 2) < ($o->radius + $radius + 0.2) ** 2) {
                        $free = false;
                        break;
                    }
                }
            } while (!$free);
            $ball->moveTo($x, $y, $z);
            $speed = $marble ? 25 : 40;
            $ball->setVelocity(mt_rand(-$speed, $speed) / 10, mt_rand(-$speed, $speed) / 10, mt_rand(-$speed, $speed) / 10);
            $this->scene->root->addChild($ball);
            $this->rocks[spl_object_id($ball)] = $ball;
        }
    }

    private function run(): void
    {
        $this->world->run(function (World $world) {
            $world->go(function () {   // through the world: a failure here stops the game visibly, it does not vanish
                foreach (Swerve::subscribe('phame:rocks:in') as $message) {
                    $this->world->sync();   // the world's clock stood at its last event: what a player does starts now
                    $request = json_decode($message, true);
                    $client = (string) ($request['client'] ?? '');
                    if (isset($this->observers[$client])) {
                        $this->observers[$client]->heard = microtime(true);
                    }
                    switch ($request['type'] ?? '') {
                        case 'join':
                            $this->observers[$client] ??= $this->observe($client);
                            break;
                        case 'camera':
                            $this->observers[$client]?->moved($request['p'], $request['q']);
                            break;
                        case 'leave':
                            $this->leave($client);
                            break;
                        case 'drop':
                            if (isset($this->observers[$client])) {
                                $this->drop($this->observers[$client]);
                            }
                            break;
                    }
                }
            });
            // a leave can be lost (publish/subscribe is at most once, and a crashed worker says nothing), but each socket
            // speaks for its viewer every 2 seconds while it lives: a camera quiet for longer than that has gone
            $world->go(function () {
                while (true) {
                    \phasync::sleep(2.0);
                    foreach ($this->observers as $client => $observer) {
                        if (microtime(true) - $observer->heard > 6.0) {
                            $this->world->sync();
                            $this->leave($client);
                        }
                    }
                }
            });
            // game time is real time since this moment: browsers sync to it through the epoch, so it must match the
            // world's clock exactly (setting the world up took time that the clock did not count)
            $this->started = microtime(true) - $world->clock->time;
            // View::publish() batches what changed since it was last called; no ticks, just whenever something does
            $world->runEvented(function () {
                $this->view->publish();
            });
        });
    }

    /**
     * A viewer throws a ragdoll, the next type in turn: from in front of their camera, the way it looks, tumbling head
     * over heels and flailing. It is gone again after 10 seconds.
     */
    private function drop(Observer $by): void
    {
        $now = microtime(true);
        // each ragdoll in flight costs the world some 7 ms a second (probing its limbs); 20 thrown into one spot
        // still keep up, 30 make the world fall behind for a moment where they cross the rocks together
        if ($now - $by->droppedAt < 0.1 || $by->ragdolls >= 10 || count($this->ragdolls) >= 20) {
            return;
        }
        $by->droppedAt = $now;
        ++$by->ragdolls;
        $doll = new Ragdoll($by, Ragdoll::TYPES[$this->thrown++ % count(Ragdoll::TYPES)]);
        [$lx, $ly, $lz] = [$by->lookX, $by->lookY, $by->lookZ];
        $n = sqrt($lx * $lx + $lz * $lz) ?: 1.0;   // the camera's right: look x world up
        $this->scene->root->addChild($doll);
        $doll->launch(
            $this->world,
            [$by->x + $lx * 18.0, $by->y + $ly * 18.0, $by->z + $lz * 18.0],
            [$lx * 34.0, $ly * 34.0, $lz * 34.0],
            [-$lz / $n * 3.0 + $lx, $ly, $lx / $n * 3.0 + $lz],   // head over heels, and a little about the way it flies
        );
        $this->ragdolls[spl_object_id($doll)] = $doll;
        $this->world->go(function () use ($doll) {
            \phasync::sleep(10.0);
            $this->world->sync();
            unset($this->ragdolls[spl_object_id($doll)]);
            $doll->dispose();   // the game owns it: taken apart, freed as soon as this coroutine lets go
            --$doll->by->ragdolls;
        });
    }

    private function leave(string $client): void
    {
        if (!isset($this->observers[$client])) {
            return;
        }
        $observer = $this->observers[$client];
        unset($this->observers[$client]);
        $this->view->leave($this->viewers[$client]);
        unset($this->viewers[$client]);
        $observer->dispose();
        error_log("phame rocks (worker " . getmypid() . "): viewer $client left, " . count($this->observers) . ' watching');
    }

    /** A new viewer's camera: in the world for everyone to see, until they leave. Joins the View too. */
    private function observe(string $client): Observer
    {
        $colors = [0xff6b6b, 0x5ce1e6, 0xffd166, 0x9b8cff, 0x6bff95, 0xff9cf0];
        $observer = new Observer($client, $colors[count($this->observers) % count($colors)]);
        $observer->moveTo(0, 30, 150);
        $this->scene->root->addChild($observer);
        Swerve::publishRaw('phame:rocks:client:' . $client, json_encode(['v' => View::PROTOCOL_VERSION, 'type' => 'epoch', 'epoch' => $this->started]));
        $this->viewers[$client] = $this->view->join('phame:rocks:client:' . $client);
        error_log("phame rocks (worker " . getmypid() . "): viewer $client joined, " . (count($this->observers) + 1) . ' watching');
        return $observer;
    }
}
