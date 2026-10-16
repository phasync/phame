<?php

namespace PhameServer;

use PhameServer\Solar\SolarSystem;
use Phame\Net\View;
use Phame\Scene\Scene3D;
use Phame\World;
use Swerve\Swerve;

/**
 * Wire protocol for the solar system demo. Observer mode only: a viewer joins, gets a snapshot of the node tree,
 * then binary batches of whatever refits. There is no viewer state server-side (no camera, no input beyond join),
 * so there is nothing to leave. The wire format itself is Net\View's (see docs/REMOTE-VIEW.md); this class only
 * builds the system and relays joins to it.
 *
 * Units: kilometres, seconds, ecliptic J2000 coordinates (see Solar\Ephemeris). Game time is seconds since the
 * game's start; `unix = epoch + game time`, with `epoch` given once in the snapshot's scene metadata is not part of
 * View's own shape, so this game still needs its own small 'epoch' message - see run().
 *
 * Channels (Swerve pub/sub):
 *   phame:solar:in            viewer -> game, JSON: {type: 'join', client}
 *   phame:solar:client:<id>   game -> that one viewer: the epoch, then the join snapshot (Net\View)
 *   phame:solar:out           game -> every viewer: Net\View's binary description batches
 */
final class SolarGame
{
    /** How long descriptions collect before they go out together. Refits are sparse, so a longer window than Rocks'. */
    public const BATCH = 1.0;

    public readonly World $world;
    public readonly Scene3D $scene;
    public readonly View $view;
    private SolarSystem $system;
    private float $started;

    /**
     * Starts the game as a coroutine of the calling worker (the one that won the Rocks game's lock - see
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
        $this->world = new World();
        $this->scene = $this->world->stage->addChild(new Scene3D(100, 100));
        $this->view = $this->world->view($this->scene, new SwerveTransport(), 'phame:solar:out');
        $this->system = new SolarSystem($this->scene, $this->started);
    }

    private function run(): void
    {
        $this->world->run(function (World $world) {
            $world->go(function () {
                foreach (Swerve::subscribe('phame:solar:in') as $message) {
                    if (($request = json_decode($message, true)) === null || ($request['type'] ?? '') !== 'join') {
                        continue;
                    }
                    $client = (string) ($request['client'] ?? '');
                    Swerve::publishRaw('phame:solar:client:' . $client, json_encode(['v' => 1, 'type' => 'epoch', 'epoch' => $this->started]));
                    $this->view->join('phame:solar:client:' . $client);
                }
            });
            // game time is real time since this moment: browsers sync to it through the epoch (see RocksGame::run())
            $this->started = microtime(true) - $world->clock->time;
            $world->go(function () {
                while (true) {
                    \phasync::sleep(self::BATCH);
                    $this->view->publish();
                }
            });
            $world->runEvented();
        });
    }
}
