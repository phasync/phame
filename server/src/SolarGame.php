<?php

namespace PhameServer;

use PhameServer\Solar\SolarSystem;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use Swerve\Swerve;

/**
 * Wire protocol for the solar system demo. Observer mode only: a viewer joins, gets a snapshot of the node tree,
 * then binary batches of whatever refits. There is no viewer state server-side (no camera, no input beyond join),
 * so there is nothing to leave.
 *
 * Units: kilometres, seconds, ecliptic J2000 coordinates (see Solar\Ephemeris). Game time is seconds since the
 * game's start; `unix = epoch + game time`, with `epoch` (a Unix timestamp, float) given once in the snapshot and
 * `game time` given in every message after it — exactly the relationship Solar\Ephemeris and Solar\SolarSystem use
 * internally. A browser maps its own clock to the server's the same way Rocks does: ping/pong round trips (see
 * swerve.php) give it an offset to server time, and it renders at server time minus a small presentation delay.
 *
 * Channels (Swerve pub/sub):
 *   phame:solar:in            viewer -> game, JSON: {type: 'join', client}
 *   phame:solar:client:<id>   game -> that one viewer: the join snapshot, one or more parts (JSON)
 *   phame:solar:out           game -> every viewer: binary description batches
 *
 * Snapshot message (JSON, chunked to stay under 128 KiB):
 *   {type: 'snapshot', part, parts, t: <game time>, epoch: <unix>, nodes: [...]}
 * Each entry of `nodes` is a flat array:
 *   [id, parentId, name, radius, kind, t0, x, y, z, vx, vy, vz, qx, qy, qz, qw, wx, wy, wz, ax, ay, az]
 * - id: the node's identity (PHP's spl_object_id), stable for the game's lifetime.
 * - parentId: 0 for a node with no parent; every parent is listed before its children, so a client can build the
 *   tree in one pass without look-ahead.
 * - name: '' for an unnamed node (every asteroid, and the structural anchor/orbit-frame nodes); otherwise e.g.
 *   'Sun', 'Earth', 'Moon'.
 * - kind: one of SolarSystem::KIND_STAR, ::KIND_PLANET, ::KIND_MOON, ::KIND_ASTEROID, ::KIND_ANCHOR,
 *   ::KIND_ORBIT_FRAME. The two structural kinds (anchor, orbit frame) have radius 0 and are not drawn; they exist
 *   only to be the moving or rotating parent of what is (see Solar\SolarSystem's class docblock).
 * - the last 17 values are the node's motion description exactly as Phame\Scene\Node3D::describe() returns it:
 *   t0 (when this description took effect, game time), position (x, y, z), velocity (vx, vy, vz), orientation as a
 *   unit quaternion (qx, qy, qz, qw), angular velocity (wx, wy, wz), then acceleration (ax, ay, az) — always 0 here
 *   (nothing in this demo accelerates), but every record carries it, so a client need not special-case its absence.
 *   A client evaluates the same way the server does: position(t) = (x,y,z) + (vx,vy,vz) * (t - t0), orientation(t)
 *   turning about (wx,wy,wz) from (qx,qy,qz,qw) the same amount of time.
 *
 * Description batch (binary): a type byte (1) and the game time as a float64 (9 bytes), then one 76-byte record per
 * changed node: id (uint32), then the same 17 floats above as t0 (float64) followed by the remaining 16 values
 * (float32 each) — little-endian throughout, nothing to parse but a DataView. A node is only in a batch the moment
 * it refits (see SolarSystem's Event::EXPIRE handlers), so most of the ~600+ nodes are silent between their own
 * refits; planets refit roughly hourly, the Moon every few hours, other moons and asteroids about once a day.
 */
final class SolarGame
{
    /** How long descriptions collect before they go out together. Refits are sparse, so a longer window than Rocks'. */
    public const BATCH = 1.0;

    public readonly World $world;
    public readonly Scene3D $scene;
    private SolarSystem $system;
    /** @var list<array{Node3D, int, string, float, int}> every node and its [parentId, name, radius, kind], creation order */
    private array $nodes = [];
    /** @var list<int|float> the batch being collected, flat: id, then describe()'s 17 values, per changed node */
    private array $batch = [];
    private int $batched = 0;
    private object $batchStarted;
    private float $started;

    /**
     * Starts the game as a coroutine of the calling worker (the one that won the Rocks game's lock — see
     * RocksGame::ensureRunning(): one worker runs both games, one World each). Restarts it if it stops on its own;
     * the whole worker going away (and so the lock) is what moves both games to another worker.
     */
    public static function start(): void
    {
        \phasync::go(static function () {
            while (true) {
                try {
                    (new self())->run();
                } catch (\Throwable $e) {
                    error_log('phame solar game stopped: ' . $e);
                }
                \phasync::sleep(1.0);
            }
        });
    }

    private function __construct()
    {
        $this->started = microtime(true);
        $this->batchStarted = new \stdClass();
        $this->world = new World();
        $this->scene = $this->world->stage->addChild(new Scene3D(100, 100));
        $this->system = new SolarSystem($this->scene, $this->started);
        foreach ($this->system->nodes() as [$node, $parent, $kind]) {
            $node->published = true;
            $this->nodes[] = [$node, $parent !== null ? spl_object_id($parent) : 0, $node->name, $node->radius, $kind];
        }
    }

    private function run(): void
    {
        $this->world->run(function (World $world) {
            $world->go(function () {
                foreach (Swerve::subscribe('phame:solar:in') as $message) {
                    $request = json_decode($message, true);
                    if (($request['type'] ?? '') === 'join') {
                        $client = (string) ($request['client'] ?? '');
                        foreach ($this->snapshot() as $part) {
                            Swerve::publishRaw('phame:solar:client:' . $client, $part);
                        }
                    }
                }
            });
            // game time is real time since this moment: browsers sync to it through the epoch (see RocksGame::run())
            $this->started = microtime(true) - $world->clock->time;
            $world->go(function () {
                while (true) {
                    \phasync::awaitFlag($this->batchStarted);
                    \phasync::sleep(self::BATCH);
                    $this->flush();
                }
            });
            // no ticks: sleep until the next refit, publish what changed
            $world->runEvented(function (World $world) {
                $changed = $world->takeChangedDescriptions();
                if ($changed === []) {
                    return;
                }
                if ($this->batched === 0) {
                    \phasync::raiseFlag($this->batchStarted);
                }
                foreach ($changed as [$node, $description]) {
                    array_push($this->batch, spl_object_id($node), ...$description);
                    ++$this->batched;
                }
                if ($this->batched >= 1700) {   // a message is at most 128 KiB: 76 bytes each
                    $this->flush();
                }
            });
        });
    }

    private function flush(): void
    {
        if ($this->batched === 0) {
            return;
        }
        Swerve::publishRaw('phame:solar:out', pack('Ce' . str_repeat('Veg16', $this->batched), 1, $this->world->clock->time, ...$this->batch));
        $this->batch = [];
        $this->batched = 0;
    }

    /**
     * The whole node tree, in parts small enough for publish/subscribe (messages are at most 128 KiB).
     *
     * @return list<string>
     */
    private function snapshot(): array
    {
        $chunks = array_chunk($this->nodes, 150);
        $parts = [];
        foreach ($chunks as $i => $chunk) {
            $nodesOut = [];
            foreach ($chunk as [$node, $parentId, $name, $radius, $kind]) {
                $nodesOut[] = [spl_object_id($node), $parentId, $name, $radius, $kind, ...$node->describe()];
            }
            $parts[] = json_encode([
                'type' => 'snapshot', 'part' => $i, 'parts' => count($chunks),
                't' => $this->world->clock->time, 'epoch' => $this->started,
                'nodes' => $nodesOut,
            ]);
        }
        return $parts;
    }
}
