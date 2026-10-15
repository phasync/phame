<?php
require __DIR__ . '/../vendor/autoload.php';

use Phame\Display\{Quad, Image, Sprite};
use Phame\Engine;
use Phame\Rendering\BlendMode;
use Phame\Textures\Texture;

$engine = new Engine(320, 240, headless: true);
$engine->stage->color = 0x203040;

// a 2x2 checker texture, scaled up with nearest filtering
$px = "\xff\x00\x00\xff" . "\x00\xff\x00\xff" . "\x00\x00\xff\xff" . "\xff\xff\x00\x80";
$tex = Texture::fromRgba(2, 2, $px, smooth: false);

$engine->stage->addChild($q = new Quad(100, 60, 0xff8000));
$q->x = 20; $q->y = 20;

$img = new Image($tex);
$img->width = 80; $img->height = 80;
$img->x = 200; $img->y = 40;
$engine->stage->addChild($img);

$group = new Sprite();
$group->x = 160; $group->y = 160; $group->rotation = M_PI / 6; $group->alpha = 0.7;
$a = new Quad(60, 40, 0xffffff); $b = new Quad(60, 40, 0x00ffff);
$b->x = 30; $b->y = 20; $b->blendMode = BlendMode::ADD;
$group->addChild($a); $group->addChild($b);
$group->pivotX = 30; $group->pivotY = 20;
$engine->stage->addChild($group);

$engine->renderFrame();
$engine->gl->checkError('after frame');
printf("draw calls: %d, quads: %d\n", $engine->painter->drawCalls, $engine->painter->quadsDrawn);
$engine->saveScreenshot(__DIR__ . '/../out/smoke.png');
echo "saved\n";
