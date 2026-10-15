<?php

// Shaders, lights and filters together: a specular cube under an orbiting point light, a wave-displaced water
// plane written as a MeshShader, fog, a bloom-filtered HUD, particles and bitmap text. All motion is coroutines.
// Run with: php examples/showcase.php   (PHAME_HEADLESS=1 renders 90 frames and saves /tmp/phame-showcase.png)
require __DIR__ . '/../vendor/autoload.php';

use Phame\Async;
use Phame\Display\{BitmapText, ParticleSystem, Quad, Sprite};
use Phame\Engine;
use Phame\Filters\BloomFilter;
use Phame\Rendering\MeshShader;
use Phame\Scene\{Geometry, Material, Mesh, PointLight, Scene3D};

$headless = (bool) getenv('PHAME_HEADLESS');
$engine = new Engine(800, 600, 'Phame: showcase', headless: $headless);
$engine->stage->color = 0x000000;

$scene = new Scene3D(800, 600);
$scene->background = 0x0A1020;
$scene->ambient = 0x202838;
$scene->lightColor = 0x405070;
$scene->lightDirection->set(-0.4, -1.0, -0.3);
$scene->fogColor = 0x0A1020;
$scene->fogNear = 8;
$scene->fogFar = 30;
$scene->camera->setPosition(0, 3.2, 7);
$scene->camera->lookAt(0, 0.2, 0);
$engine->stage->addChild($scene);

$water = new Mesh(Geometry::plane(40, 40, 64), new Material(0x2060C0, specular: 1.0, shininess: 90, shader: new MeshShader(<<<'GLSL'
    vec3 displace(vec3 p, vec3 n, vec2 uv) {
        p.y += sin(p.x * 1.1 + uTime * 1.6) * 0.3 + sin(p.z * 1.4 - uTime * 1.2) * 0.25;
        return p;
    }
    vec4 shade() {
        vec3 n = normalize(flatNormal());
        vec3 base = mix(vec3(0.02, 0.08, 0.3), vec3(0.1, 0.35, 0.7), n.y * n.y * n.y);
        return vec4(base * sceneLight(n) + sceneSpecular(n), 1.0);
    }
    GLSL)));
$water->y = -1.2;
$scene->root->addChild($water);

$cube = new Mesh(Geometry::box(1.6, 1.6, 1.6), new Material(0xE06020, specular: 0.9, shininess: 48));
$scene->root->addChild($cube);

$orb = new Mesh(Geometry::sphere(0.18), new Material(0xFFE0A0, emissive: 0xFFE0A0));
$scene->root->addChild($orb);
$light = new PointLight(0xFFD080, 3.0, 10.0);
$scene->root->addChild($light);

$fire = new ParticleSystem(256);
$fire->seed(4);
$fire->x = 720;
$fire->y = 570;
$fire->emissionRate = 90;
$fire->lifetime = 1.4;
$fire->speed = 70;
$fire->spread = 0.5;
$fire->gravityY = -30;
$fire->startSize = 18;
$fire->endSize = 3;
$fire->startColor = 0xFFC040;
$fire->endColor = 0xC01000;
$fire->endAlpha = 0.0;
$fire->start();
$engine->stage->addChild($fire);

$hud = new Sprite();
$title = new BitmapText('PHAME', null, 56, 0xFFE8B0);
$title->x = 28;
$title->y = 20;
$sub = new BitmapText("pooled math, fibers, shaders\nbloom \u{00E6}\u{00F8}\u{00E5}", null, 20, 0x80C0FF);
$sub->x = 30;
$sub->y = 84;
$hud->addChild($title);
$hud->addChild($sub);
$hud->filter = new BloomFilter(1.2, 10, 0.55);
$engine->stage->addChild($hud);

if ($headless) {
    $engine->maxFrames = 90;
    $engine->clock->forcedDelta = 1 / 30;
}
$engine->run(function () use ($engine, $cube, $orb, $light) {
    $engine->go(function () use ($engine, $cube) {
        while (true) {
            Async::awaitFrame();
            $cube->rotationY += $engine->clock->delta * 0.9;
            $cube->rotationX += $engine->clock->delta * 0.5;
        }
    });
    $engine->go(function () use ($engine, $orb, $light) {
        while (true) {
            Async::awaitFrame();
            $t = $engine->clock->time * 1.4;
            $light->setPosition(cos($t) * 2.4, 1.0 + sin($t * 2) * 0.5, sin($t) * 2.4);
            $orb->setPosition($light->x, $light->y, $light->z);
        }
    });
});
if ($headless) {
    $engine->saveScreenshot('/tmp/phame-showcase.png');
    echo "saved /tmp/phame-showcase.png\n";
}
