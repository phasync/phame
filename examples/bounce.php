<?php

// A windowed demo: coroutines drive the animation with Async::tween/wait, and clicking spawns a new one.
// Run with: php examples/bounce.php   (PHAME_SDL2=/path/to/SDL2 if the library is not found)
require __DIR__ . '/../vendor/autoload.php';

use Phame\Animation\Transitions;
use Phame\Async;
use Phame\Display\Quad;
use Phame\Engine;
use Phame\Events\{Event, TouchEvent};

$engine = new Engine(800, 600, 'Phame: bounce');
$engine->stage->color = 0x202030;

$bounce = function (Quad $q) {
    $floor = 600 - $q->height;
    while (true) {
        Async::tween($q, 0.7, ['y' => $floor], Transitions::EASE_OUT_BOUNCE);
        Async::wait(0.2);
        Async::tween($q, 0.5, ['y' => 50.0], Transitions::EASE_OUT);
    }
};

$spawn = function (float $x) use ($engine, $bounce) {
    $q = new Quad(40, 40);
    $q->color = mt_rand(0x404040, 0xFFFFFF);
    $q->x = $x - 20;
    $q->y = 50;
    $engine->stage->addChild($q);
    $engine->go(fn () => $bounce($q));
};

$engine->stage->addEventListener(Event::TOUCH, function (TouchEvent $e) use ($spawn) {
    if ($e->phase === TouchEvent::BEGAN) {
        $spawn($e->stageX);
    }
});

$engine->run(function () use ($spawn) {
    foreach ([200, 400, 600] as $x) {
        $spawn($x);
        Async::wait(0.3);
    }
});
