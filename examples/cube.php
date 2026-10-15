<?php

// A lit cube and a glass sphere in a Scene3D with 2D text-free HUD bar drawn over it. Coroutines spin the cube each
// frame (Async::awaitFrame + the global clock) and bob the sphere with a tween.
// Run with: php examples/cube.php   (PHAME_HEADLESS=1 renders 30 frames and saves /tmp/phame-cube.png)
require __DIR__ . '/../vendor/autoload.php';

use Phame\Animation\Transitions;
use Phame\Async;
use Phame\Display\Quad;
use Phame\Engine;
use Phame\Scene\{Geometry, Material, Mesh, Scene3D};

$headless = (bool) getenv('PHAME_HEADLESS');
$engine = new Engine(800, 600, 'Phame: cube', headless: $headless);
$engine->stage->color = 0x101018;

$scene = new Scene3D(800, 600);
$scene->background = 0x203050;
$engine->stage->addChild($scene);

$cube = new Mesh(Geometry::box(1.5, 1.5, 1.5), new Material(0xFF8040));
$scene->root->addChild($cube);

$glass = new Mesh(Geometry::sphere(0.6), new Material(0x40C0FF, alpha: 0.5));
$glass->x = 1.8;
$scene->root->addChild($glass);

$floor = new Mesh(Geometry::plane(8, 8), new Material(0x405040));
$floor->y = -1.2;
$scene->root->addChild($floor);

$bar = new Quad(800, 24);
$bar->color = 0x000000;
$bar->alpha = 0.6;
$engine->stage->addChild($bar);

if ($headless) {
    $engine->maxFrames = 30;
    $engine->clock->forcedDelta = 1 / 30;
}
$engine->run(function () use ($engine, $cube, $glass) {
    $engine->go(function () use ($cube, $engine) {
        while (true) {
            Async::awaitFrame();
            $cube->rotationY += $engine->clock->delta * 1.2;
            $cube->rotationX += $engine->clock->delta * 0.7;
        }
    });

    $engine->go(function () use ($glass) {
        while (true) {
            Async::tween($glass, 1.0, ['y' => 0.8], Transitions::EASE_IN_OUT);
            Async::tween($glass, 1.0, ['y' => -0.8], Transitions::EASE_IN_OUT);
        }
    });
});
if ($headless) {
    $engine->saveScreenshot('/tmp/phame-cube.png');
    echo "saved /tmp/phame-cube.png\n";
}
