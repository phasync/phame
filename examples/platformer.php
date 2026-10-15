<?php

// A small platformer: a TileMap level, gravity, jumping with coyote time, a moving platform, coins, a patrolling
// enemy, a following camera, death and restart, and a win screen. Each thing is a coroutine owned by its object.
// Run with: php examples/platformer.php   (arrows move, space jumps)
// PHAME_HEADLESS=1 lets a bot play and saves /tmp/phame-platformer.png
require __DIR__ . '/../vendor/autoload.php';

use Phame\Animation\Transitions;
use Phame\Async;
use Phame\Display\{BitmapText, Quad, Sprite, TileMap};
use Phame\Engine;
use Phame\Events\{Event, Key};

const T = 32;
const LEVEL = <<<MAP
    ##################################################
    #                                                #
    #                                         o o F  #
    #              o o o                    ######## #
    #             #######        o                   #
    #     o                     ###      =           #
    #   #####                                        #
    #                  o    E         E        o     #
    #           ###########################   ###    #
    #  P                                             #
    ##################################################
    MAP;

$headless = (bool) getenv('PHAME_HEADLESS');
$engine = new Engine(800, 352, 'Phame: platformer', headless: $headless);
$engine->stage->color = 0x182030;
$world = $engine->stage->addChild(new Sprite());
$hud = $engine->stage->addChild(new BitmapText('', null, 22));
$hud->x = $hud->y = 8;

/** Finds the tiles with this character, replaces them with a space, and returns their positions. */
function spawnPoints(TileMap $map, string $char): Generator
{
    for ($r = 0; $r < $map->rowCount; ++$r) {
        for ($c = 0; $c < $map->columns; ++$c) {
            if ($map->getTile($c, $r) === $char) {
                $map->setTile($c, $r, ' ');
                yield [$c * T, $r * T];
            }
        }
    }
}

function play(Engine $engine, Sprite $world, BitmapText $hud): Quad
{
    $world->removeChildren(dispose: true);
    $map = $world->addChild(new TileMap(LEVEL, T));
    $map->style('#', 0x506070)->style('o', 0xFFD040)->style('F', 0x40FF80);
    $coins = 0;
    $hud->text = 'Coins 0';

    $player = $world->addChild(new Quad(22, 28, 0xF0F0F0));
    [$player->x, $player->y] = spawnPoints($map, 'P')->current();

    $platforms = $world->addChild(new Sprite());
    foreach (spawnPoints($map, '=') as [$x, $y]) {       // a platform that rides back and forth
        $platform = $platforms->addChild(new Quad(T * 3, 10, 0xA08060));
        $platform->x = $x;
        $platform->y = $y;
        $platform->go(function () use ($platform) {
            while (true) {
                Async::tween($platform, 2.0, ['x' => $platform->x + T * 6], Transitions::EASE_IN_OUT);
                Async::tween($platform, 2.0, ['x' => $platform->x - T * 6], Transitions::EASE_IN_OUT);
            }
        });
    }

    foreach (spawnPoints($map, 'E') as [$x, $y]) {       // an enemy that walks until it meets a wall
        $enemy = $world->addChild(new Quad(26, 22, 0xFF4060));
        $enemy->x = $x;
        $enemy->y = $y + T - 22;
        $enemy->go(function () use ($enemy, $map, $player) {
            for ($dir = 1; ; ) {
                $dt = Async::awaitFrame();
                if ($map->move($enemy, $dir * 80 * $dt, 0) || $map->tileAt($enemy->x + ($dir > 0 ? 27 : -1), $enemy->y + 23) === ' ') {
                    $dir = -$dir;                                     // a wall, or the edge of the floor
                }
                if ($player->parent !== null && $enemy->overlaps($player)) {
                    $player->removeFromParent();
                }
            }
        });
    }

    // The player: run, jump with coyote time, ride platforms, collect coins, reach the flag.
    $player->go(function () use ($engine, $player, $map, $platforms, $hud, &$coins) {
        $vy = 0.0;
        $sinceGround = 99.0;
        $riding = null;
        $ridingX = 0.0;
        while (true) {
            $dt = Async::awaitFrame();
            $dx = $engine->isKeyDown(Key::RIGHT) - $engine->isKeyDown(Key::LEFT);
            $map->move($player, $dx * 200 * $dt, 0);

            $vy = min($vy + 1400 * $dt, 900);
            $hit = $map->move($player, 0, $vy * $dt);
            if ($riding !== null) {                                  // carried along by the platform we stand on
                $map->move($player, $riding->x - $ridingX, 0);
            }
            $riding = null;
            foreach ($platforms->getChildren() as $p) {              // platforms are not tiles: land on them by hand
                if ($vy >= 0 && $p->overlaps($player) && $player->y + 28 - $vy * $dt <= $p->y + 1) {
                    $player->y = $p->y - 28;
                    $hit |= TileMap::DOWN;
                    $riding = $p;
                    $ridingX = $p->x;
                }
            }
            if ($hit & (TileMap::DOWN | TileMap::UP)) {
                $vy = 0.0;
            }
            $sinceGround = $hit & TileMap::DOWN ? 0.0 : $sinceGround + $dt;
            if ($sinceGround < 0.1 && $engine->isKeyDown(Key::SPACE)) {
                $vy = -560.0;
                $sinceGround = 99.0;
            }
            if ($riding !== null) {
                $player->y += 0.01;                                  // stay in contact next frame
            }
            if ($map->take($player, 'o')) {
                $hud->text = 'Coins ' . ++$coins;
            }
            if ($map->touching($player, 'F')) {
                $hud->text = "You win with $coins coins!   (space)";
                $player->removeFromParent();
            }
            if ($player->y > $map->rowCount * T) {
                $player->removeFromParent();
            }
        }
    });

    // The camera: the world scrolls so that the player stays in the middle, without leaving the level.
    $world->go(function () use ($engine, $world, $player, $map) {
        while ($player->parent !== null) {
            $dt = Async::awaitFrame();
            $target = max($engine->stageWidth - $map->columns * T, min(0, $engine->stageWidth / 2 - $player->x));
            $world->x += ($target - $world->x) * min(1, 8 * $dt);
        }
    });
    return $player;
}

if ($headless) {
    $engine->maxFrames = 250;
    $engine->clock->forcedDelta = 1 / 60;
}
$engine->run(function () use ($engine, $world, $hud, $headless) {
    if ($headless) {
        $engine->go(function () use ($engine) {   // a bot: run right, jump now and then
            $engine->simulateKey(Key::RIGHT, true);
            while (true) {
                Async::wait(0.9);
                $engine->simulateKey(Key::SPACE, true);
                Async::wait(0.3);
                $engine->simulateKey(Key::SPACE, false);
            }
        });
    }
    while (true) {
        $player = play($engine, $world, $hud);
        Async::awaitEvent($player, Event::REMOVED_FROM_STAGE);
        if (!str_starts_with($hud->text, 'You win')) {
            $hud->text = 'Ouch!   (space)';
        }
        Async::awaitKey(Key::SPACE);
    }
});
if ($headless) {
    $engine->saveScreenshot('/tmp/phame-platformer.png');
    echo "saved /tmp/phame-platformer.png: {$hud->text}\n";
}
