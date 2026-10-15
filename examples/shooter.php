<?php

// A complete arena shooter in the Phame style: every bullet, enemy and effect is a coroutine owned by its display
// object, so there are no entity lists, no update() methods and no cleanup code. Removing an object ends its
// coroutine; clearing the world ends them all.
// Run with: php examples/shooter.php   (arrows/WASD move, space restarts, P pauses)
// PHAME_HEADLESS=1 lets a bot play for 15 seconds (and press space after dying) and saves /tmp/phame-shooter.png
require __DIR__ . '/../vendor/autoload.php';

use Phame\Animation\Transitions;
use Phame\Audio\Sound;
use Phame\Async;
use Phame\Display\{BitmapText, ParticleSystem, Quad, Sprite};
use Phame\Engine;
use Phame\Events\{Event, Key};

const W = 800;
const H = 600;

$headless = (bool) getenv('PHAME_HEADLESS');
$engine = new Engine(W, H, 'Phame: shooter', headless: $headless);
$engine->stage->color = 0x10101C;

$world = $engine->stage->addChild(new Sprite());
$hud = $engine->stage->addChild(new BitmapText('', null, 22));
$hud->x = $hud->y = 10;
$sparks = $engine->stage->addChild(new ParticleSystem(1024));
$sparks->lifetime = 0.5;
$sparks->speed = 260;
$sparks->spread = M_PI;
$sparks->startColor = 0xFFC040;
$sparks->endColor = 0xFF2000;
$sparks->endAlpha = 0.0;

$score = 0;
$sfx = [   // generated, so the example needs no asset files
    'shot' => Sound::tone(900, 0.06, 'square', 300, 0.12),
    'boom' => Sound::tone(160, 0.35, 'noise', 40, 0.4),
    'dead' => Sound::tone(400, 0.8, 'saw', 60, 0.4),
];

function boom(ParticleSystem $sparks, Quad $at): void
{
    $sparks->x = $at->x + $at->width / 2;
    $sparks->y = $at->y + $at->height / 2;
    $sparks->burst(30);
}

function shake(Sprite $world, float $strength): void
{
    Engine::current()->go(function () use ($world, $strength) {
        for ($s = $strength; $s > 0.5; $s *= 0.8) {
            $world->x = mt_rand(-100, 100) / 100 * $s;
            $world->y = mt_rand(-100, 100) / 100 * $s;
            Async::awaitFrame();
        }
        $world->x = $world->y = 0;
    });
}

function floatingText(Sprite $world, string $text, float $x, float $y): void
{
    $label = $world->addChild(new BitmapText($text, null, 18, 0xFFE080));
    $label->x = $x;
    $label->y = $y;
    $label->go(function () use ($label) {
        Async::tween($label, 0.6, ['y' => $label->y - 40, 'alpha' => 0.0], Transitions::EASE_OUT);
        $label->removeFromParent();
    });
}

function play(Engine $engine, Sprite $world, ParticleSystem $sparks, BitmapText $hud, int &$score, array $sfx): Quad
{
    $world->removeChildren(dispose: true);   // ends every coroutine of the previous round
    $score = 0;
    $enemies = $world->addChild(new Sprite());
    $bullets = $world->addChild(new Sprite());
    $player = $world->addChild(new Quad(24, 24, 0x40FF80));
    $player->x = W / 2;
    $player->y = H - 60;

    // The player: move, and fire every 0.12 s. Two coroutines, each a straight line of logic.
    $player->go(function () use ($engine, $player) {
        while (true) {
            $dt = Async::awaitFrame();
            $dx = ($engine->isKeyDown(Key::RIGHT) || $engine->isKeyDown(Key::D)) - ($engine->isKeyDown(Key::LEFT) || $engine->isKeyDown(Key::A));
            $dy = ($engine->isKeyDown(Key::DOWN) || $engine->isKeyDown(Key::S)) - ($engine->isKeyDown(Key::UP) || $engine->isKeyDown(Key::W));
            $player->x = max(0, min(W - 24, $player->x + $dx * 320 * $dt));
            $player->y = max(0, min(H - 24, $player->y + $dy * 320 * $dt));
        }
    });
    $player->go(function () use ($player, $bullets, $enemies, $sparks, $world, $hud, &$score, $sfx) {
        while (true) {
            Async::wait(0.12);
            $sfx['shot']->play();
            $bullet = $bullets->addChild(new Quad(4, 10, 0xFFFF60));
            $bullet->x = $player->x + 10;
            $bullet->y = $player->y - 10;
            $bullet->go(function () use ($bullet, $enemies, $sparks, $world, $hud, &$score, $sfx) {
                // the engine moves the bullet; this coroutine sleeps until it hits something or leaves the screen
                $bullet->velocityY = -600;
                $enemy = Async::until(fn () => $bullet->y < -10 ? true : $bullet->firstOverlap($enemies));
                if ($enemy instanceof Quad && $enemy->parent !== null) {   // another bullet may have got it this frame
                    boom($sparks, $enemy);
                    $sfx['boom']->play();
                    floatingText($world, '+10', $enemy->x, $enemy->y);
                    shake($world, 4);
                    $score += 10;
                    $hud->text = "Score $score";
                    $enemy->removeFromParent();
                }
                $bullet->removeFromParent();
            });
        }
    });

    // Waves. Each enemy's behaviour is a coroutine; the wave waits until the sky is clear.
    $world->go(function () use ($enemies, $player, $world, $sparks, $hud, &$score, $sfx) {
        $behaviours = [
            'diver' => function (Quad $e, int $wave) {
                while (true) {
                    $e->y += (60 + 10 * $wave) * Async::awaitFrame();
                }
            },
            'weaver' => function (Quad $e, int $wave) {
                for ($t = 0.0; ; $t += $dt) {
                    $dt = Async::awaitFrame();
                    $e->y += 50 * $dt;
                    $e->x += cos($t * 3) * 150 * $dt;
                }
            },
            'hunter' => function (Quad $e, int $wave) use ($player) {
                while (true) {
                    Async::wait(0.8);                              // hover, then lunge at the player
                    Async::tween($e, 0.5, ['x' => $player->x, 'y' => $e->y + 90], Transitions::EASE_IN_OUT);
                }
            },
        ];
        $colors = ['diver' => 0xFF4060, 'weaver' => 0xC060FF, 'hunter' => 0xFF9020];
        for ($wave = 1; ; ++$wave) {
            $hud->text = "Score $score   Wave $wave";
            for ($i = 0; $i < 3 + $wave * 2; ++$i) {
                $kind = array_rand($behaviours);
                $enemy = $enemies->addChild(new Quad(26, 26, $colors[$kind]));
                $enemy->x = mt_rand(20, W - 46);
                $enemy->y = -30;
                $enemy->go(fn () => $behaviours[$kind]($enemy, $wave));
                Async::wait(0.4);
            }
            while ($enemies->numChildren > 0) {
                Async::awaitFrame();
                foreach ($enemies->getChildren() as $enemy) {
                    if ($enemy->y > H) {
                        $enemy->removeFromParent();
                    } elseif ($enemy->overlaps($player)) {
                        boom($sparks, $player);
                        $sfx['dead']->play();
                        shake($world, 12);
                        $player->removeFromParent();               // stops the player's coroutines
                        $hud->text = "GAME OVER   Score $score   (space)";
                        return;
                    }
                }
            }
            Async::wait(1.0);
        }
    });
    return $player;
}

if ($headless) {
    mt_srand(7);
    $engine->maxFrames = 900;
    $engine->clock->forcedDelta = 1 / 60;
}
$engine->run(function () use ($engine, $world, $sparks, $hud, &$score, $headless, $sfx) {
    $engine->go(function () use ($engine) {
        while (true) {
            Async::awaitKey(Key::P);
            $engine->clock->paused = !$engine->clock->paused;
        }
    });
    if ($headless) {
        $engine->go(function () use ($engine) {   // a bot that strafes, as a player would with the arrow keys
            while (true) {
                foreach ([Key::LEFT, Key::RIGHT] as $key) {
                    $engine->simulateKey($key, true);
                    Async::wait(mt_rand(5, 15) / 10);
                    $engine->simulateKey($key, false);
                }
                $engine->simulateKey(Key::SPACE, true);
                $engine->simulateKey(Key::SPACE, false);
            }
        });
    }
    while (true) {
        $player = play($engine, $world, $sparks, $hud, $score, $sfx);
        Async::awaitEvent($player, Event::REMOVED_FROM_STAGE);
        Async::awaitKey(Key::SPACE);
    }
});
if ($headless) {
    $engine->saveScreenshot('/tmp/phame-shooter.png');
    echo "score $score after {$engine->clock->frame} frames, saved /tmp/phame-shooter.png\n";
}
