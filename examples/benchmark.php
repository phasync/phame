<?php

// Measures what fits in a 60 fps frame (16.7 ms) on this machine, headless. For each scenario: game logic (input,
// juggler, coroutines), render (building and submitting batches) and GPU (waiting for the frame to finish) in ms.
// Run with: php examples/benchmark.php            (add -d opcache.enable_cli=1 -d opcache.jit=tracing
//                                                   -d opcache.jit_buffer_size=64M to compare with the JIT)
require __DIR__ . '/../vendor/autoload.php';

use Phame\Async;
use Phame\Display\{Image, ParticleSystem, Quad};
use Phame\Engine;
use Phame\Math\Matrix4;
use Phame\Scene\{Geometry, Material, Mesh, PointLight, Scene3D};
use Phame\Textures\Texture;

$engine = new Engine(800, 600, headless: true);
$engine->clock->forcedDelta = 1 / 60;
$texture = Texture::fromColor(16, 16, 0xFFFFFFFF);
$px = $engine->gl->alloc('uint8_t[4]');

/** Runs $frames frames of the scene $setup builds, and prints the average split. */
function measure(Engine $engine, string $label, \Closure $setup, int $frames = 120): void
{
    global $px;
    $engine->stage->removeChildren(true);
    $engine->maxFrames = $engine->clock->frame + 20 + $frames;   // 20 frames of warm-up
    $update = $render = $gpu = 0.0;
    $engine->run(function (Engine $engine) use ($setup, $frames, $px, &$update, &$render, &$gpu) {
        $setup($engine);
        Async::awaitFrame(20);
        for ($f = 0; $f < $frames; ++$f) {
            Async::awaitFrame();
            $update += $engine->updateTime;
            $render += $engine->renderTime;
            $t = hrtime(true);
            $engine->gl->readPixels(0, 0, 1, 1, Phame\GL\GL::RGBA, Phame\GL\GL::UNSIGNED_BYTE, $px);   // waits for the GPU
            $gpu += (hrtime(true) - $t) / 1e9;
        }
    });
    [$u, $r, $g] = [$update / $frames * 1e3, $render / $frames * 1e3, $gpu / $frames * 1e3];
    printf("%-46s %7.2f %7.2f %7.2f %8.2f ms %s\n", $label, $u, $r, $g, $u + $r + $g, $u + $r + $g <= 16.7 ? '' : ' > 60 fps budget');
}

printf("PHP %s, JIT %s\n\n%-46s %7s %7s %7s %11s\n", PHP_VERSION, (ini_get('opcache.jit') ?: 'off') !== 'off' && ini_get('opcache.enable_cli') ? ini_get('opcache.jit') : 'off', 'scenario', 'logic', 'render', 'gpu', 'total');

foreach ([1000, 5000, 10000, 20000] as $n) {
    measure($engine, "$n sprites, each moved by its own coroutine", function (Engine $engine) use ($n, $texture) {
        for ($i = 0; $i < $n; ++$i) {
            $s = $engine->stage->addChild(new Image($texture));
            $s->x = mt_rand(0, 800);
            $s->y = mt_rand(0, 600);
            $s->go(function () use ($s) {
                while (true) {
                    $dt = Async::awaitFrame();
                    $s->rotation += 2 * $dt;
                    $s->x = ($s->x + 60 * $dt) % 800;
                }
            });
        }
    });
}

foreach ([100, 300] as $n) {
    measure($engine, "bullet churn: $n spawns/frame, 1 s life", function (Engine $engine) use ($n) {
        $engine->go(function () use ($engine, $n) {
            while (true) {
                for ($i = 0; $i < $n; ++$i) {
                    $b = $engine->stage->addChild(new Quad(4, 10, 0xFFFF60));
                    $b->x = mt_rand(0, 800);
                    $b->y = 600;
                    $b->go(function () use ($b) {
                        for ($t = 0.0; $t < 1.0; $t += Async::awaitFrame()) {
                            $b->y -= 10;
                        }
                        $b->removeFromParent();
                    });
                }
                Async::awaitFrame();
            }
        });
    });
}

foreach ([2000, 10000] as $n) {
    measure($engine, "particles: $n live", function (Engine $engine) use ($n) {
        $p = $engine->stage->addChild(new ParticleSystem($n));
        $p->x = 400;
        $p->y = 300;
        $p->lifetime = 2.0;
        $p->emissionRate = $n / 2.0;
        $p->speed = 150;
        $p->spread = M_PI;
        $p->start();
    });
}

foreach ([100, 500] as $n) {
    measure($engine, "3D: $n lit, spinning meshes + 4 point lights", function (Engine $engine) use ($n) {
        $scene = $engine->stage->addChild(new Scene3D(800, 600));
        $scene->camera->setPosition(0, 8, 30);
        $scene->camera->lookAt(0, 0, 0);
        $box = Geometry::box();
        for ($i = 0; $i < $n; ++$i) {
            $m = new Mesh($box, new Material(mt_rand(0, 0xFFFFFF), specular: 0.5));
            $m->setPosition(mt_rand(-20, 20), mt_rand(-8, 8), mt_rand(-20, 10));
            $scene->root->addChild($m);
            $m->go(function () use ($m) {
                while (true) {
                    $m->rotationY += Async::awaitFrame();
                }
            });
        }
        for ($i = 0; $i < 4; ++$i) {
            $light = new PointLight(0xFFFFFF, 1.0, 15.0);
            $light->setPosition($i * 10 - 15, 4, 0);
            $scene->root->addChild($light);
        }
    });
}

$a = Matrix4::get()->compose(1, 2, 3, 0.1, 0.2, 0.3, 1, 1, 1);
$b = Matrix4::get()->compose(3, 2, 1, 0.3, 0.2, 0.1, 2, 2, 2);
$out = Matrix4::get();
$t = hrtime(true);
for ($i = 0; $i < 200000; ++$i) {
    $out->product($a, $b);
}
$product = (hrtime(true) - $t) / 200000 / 1e3;
$t = hrtime(true);
for ($i = 0; $i < 200000; ++$i) {
    $out->invertFrom($a);
}
printf("\npooled math: Matrix4::product %.2f µs, invertFrom %.2f µs\n", $product, (hrtime(true) - $t) / 200000 / 1e3);
