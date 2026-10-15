<?php

// Headless sprite benchmark: how many moving textured sprites fit in a 60 fps frame budget (16.7 ms).
require __DIR__ . '/../vendor/autoload.php';

use Phame\Display\Image;
use Phame\Engine;
use Phame\Textures\Texture;

$engine = new Engine(800, 600, headless: true);
$texture = Texture::fromColor(16, 16, 0xFFFFFFFF);
$px = $engine->gl->alloc('uint8_t[4]');

foreach ([1000, 5000, 10000, 20000] as $count) {
    $engine->stage->removeChildren();
    $sprites = [];
    for ($i = 0; $i < $count; ++$i) {
        $s = new Image($texture);
        $s->pivotX = $s->pivotY = 8;
        $s->x = mt_rand(0, 800);
        $s->y = mt_rand(0, 600);
        $engine->stage->addChild($s);
        $sprites[] = $s;
    }
    $frames = 30;
    $start = hrtime(true);
    for ($f = 0; $f < $frames; ++$f) {
        foreach ($sprites as $s) {
            $s->rotation += 0.05;
        }
        $engine->renderFrame();
        $engine->gl->readPixels(0, 0, 1, 1, Phame\GL\GL::RGBA, Phame\GL\GL::UNSIGNED_BYTE, $px);   // waits for the GPU
    }
    $ms = (hrtime(true) - $start) / 1e6 / $frames;
    printf("%6d sprites: %6.2f ms/frame (%4.0f fps), %d draw call(s)\n", $count, $ms, 1000 / $ms, $engine->painter->drawCalls);
}
