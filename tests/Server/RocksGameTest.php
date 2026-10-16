<?php

namespace Phame\Tests\Server;

require_once __DIR__ . '/../../server/vendor/autoload.php';

use PhameServer\RocksGame;
use PhameServer\SolarGame;
use PHPUnit\Framework\TestCase;

/**
 * The worker that wins the rocks lock also starts the solar game (see RocksGame::ensureRunning() and its
 * startCompanions()) - this regressed once when the ::start() call was simply dropped during a refactor, with
 * nothing to say so beyond "the solar demo never connects".
 */
final class RocksGameTest extends TestCase
{
    public function testWinningTheLockAlsoStartsEveryCompanionGame(): void
    {
        SolarGame::$launched = false;
        try {
            // SolarGame::start() sets $launched before it schedules its own forever-running coroutine (the part
            // this test is not trying to exercise), so it is already true by the time ::go() refuses to run
            // outside a phasync::run() of its own.
            (new \ReflectionMethod(RocksGame::class, 'startCompanions'))->invoke(null);
        } catch (\Throwable) {
            // expected outside a phasync::run(): irrelevant here, see above
        }
        $this->assertTrue(SolarGame::$launched, 'RocksGame::ensureRunning() must start the solar game alongside its own');
    }
}
