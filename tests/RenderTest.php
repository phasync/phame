<?php

namespace Phame\Tests;

use Phame\Display\{Image, Quad, Sprite};
use Phame\Events\TouchEvent;
use Phame\Rendering\BlendMode;
use Phame\Textures\Texture;

final class RenderTest extends GlTestCase
{
    public function testStageColorFillsTheFrame(): void
    {
        $this->engine->stage->color = 0x336699;
        $this->engine->render();
        $this->assertPixel([0x33, 0x66, 0x99, 255], 0, 0);
        $this->assertPixel([0x33, 0x66, 0x99, 255], 63, 47);
    }

    public function testQuadIsDrawnWithTopLeftOrigin(): void
    {
        $q = new Quad(10, 10);
        $q->color = 0xFF0000;
        $q->x = 4;
        $q->y = 2;
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 5, 3, message: 'inside, top left');
        $this->assertPixel([255, 0, 0, 255], 13, 11, message: 'inside, bottom right');
        $this->assertPixel([0, 0, 0, 255], 3, 3, message: 'left of the quad');
        $this->assertPixel([0, 0, 0, 255], 5, 1, message: 'above the quad: y grows downward');
        $this->assertPixel([0, 0, 0, 255], 15, 13, message: 'beyond the quad');
    }

    public function testNestedTransformsAreAppliedParentFirst(): void
    {
        $group = new Sprite();
        $group->x = 20;
        $group->y = 20;
        $group->scaleX = $group->scaleY = 2;
        $q = new Quad(5, 5);
        $q->color = 0x00FF00;
        $q->x = 5;
        $group->addChild($q);
        $this->engine->stage->addChild($group);
        $this->engine->render();
        // the quad covers x 30..40, y 20..30 on the stage
        $this->assertPixel([0, 255, 0, 255], 35, 25);
        $this->assertPixel([0, 0, 0, 255], 28, 25);
        $this->assertPixel([0, 0, 0, 255], 35, 32);
    }

    public function testAlphaBlendsWithTheBackgroundAndMultipliesDownTheTree(): void
    {
        $this->engine->stage->color = 0x000000;
        $group = new Sprite();
        $group->alpha = 0.5;
        $q = new Quad(10, 10);
        $q->color = 0xFFFFFF;
        $q->alpha = 0.5;
        $group->addChild($q);
        $this->engine->stage->addChild($group);
        $this->engine->render();
        $this->assertPixel([64, 64, 64, 255], 5, 5, 3, '0.5 * 0.5 white over black');
    }

    public function testAdditiveBlend(): void
    {
        $this->engine->stage->color = 0x404040;
        $q = new Quad(10, 10);
        $q->color = 0x202020;
        $q->blendMode = BlendMode::ADD;
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([0x60, 0x60, 0x60, 255], 5, 5);
    }

    public function testHiddenObjectsAreNotDrawn(): void
    {
        $q = new Quad(10, 10);
        $q->color = 0xFF0000;
        $q->visible = false;
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 5, 5);
        $q->visible = true;
        $q->alpha = 0.0;
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 5, 5);
    }

    public function testTextureOrientationAndRegions(): void
    {
        // 2x2 texture: top row red, green; bottom row blue, white
        $rgba = "\xff\x00\x00\xff\x00\xff\x00\xff\x00\x00\xff\xff\xff\xff\xff\xff";
        $tex = Texture::fromRgba(2, 2, $rgba, smooth: false);

        $image = new Image($tex);
        $image->width = 20;
        $image->height = 20;
        $this->engine->stage->addChild($image);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 5, 5, message: 'top left texel');
        $this->assertPixel([0, 255, 0, 255], 15, 5, message: 'top right texel');
        $this->assertPixel([0, 0, 255, 255], 5, 15, message: 'bottom left texel');
        $this->assertPixel([255, 255, 255, 255], 15, 15, message: 'bottom right texel');

        // regions share the GPU texture and sample only their texel
        $this->engine->stage->removeChild($image);
        $region = new Image($tex->region(1, 1, 1, 1));
        $region->width = 10;
        $region->height = 10;
        $region->x = 30;
        $region->y = 30;
        $this->engine->stage->addChild($region);
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 32, 32);
        $this->assertPixel([255, 255, 255, 255], 38, 38);
        $this->assertSame($tex->gpu, $tex->region(0, 1, 1, 1)->gpu);
    }

    public function testStraightAlphaTexturesArePremultipliedByTheShader(): void
    {
        $tex = Texture::fromColor(2, 2, 0x80FF0000);   // 50% red
        $image = new Image($tex);
        $image->width = 10;
        $image->height = 10;
        $this->engine->stage->addChild($image);
        $this->engine->render();
        $this->assertPixel([128, 0, 0, 255], 5, 5, 3);
    }

    public function testBatchingKeepsDrawCallsLowAndFlushesOnStateChange(): void
    {
        $tex = Texture::fromColor(2, 2, 0xFFFFFFFF);
        for ($i = 0; $i < 100; ++$i) {
            $q = new Quad(2, 2);
            $q->x = $i % 30;
            $this->engine->stage->addChild($q);
        }
        $this->engine->render();
        $this->assertSame(1, $this->engine->painter->drawCalls, '100 untextured quads in one call');
        $this->assertSame(100, $this->engine->painter->quadsDrawn);

        $this->engine->stage->addChild(new Image($tex));
        $this->engine->render();
        $this->assertSame(2, $this->engine->painter->drawCalls, 'the texture is a state change');
    }

    public function testMoreQuadsThanOneBatchHoldsAreAllDrawn(): void
    {
        $this->engine->stage->color = 0x000000;
        for ($i = 0; $i < 9000; ++$i) {
            $q = new Quad(64, 48);
            $q->color = 0x010101;
            $q->blendMode = BlendMode::ADD;
            $this->engine->stage->addChild($q);
        }
        $this->engine->render();
        $this->assertSame(9000, $this->engine->painter->quadsDrawn);
        $this->assertSame(2, $this->engine->painter->drawCalls);
        $this->assertPixel([255, 255, 255, 255], 10, 10, message: '9000 additive layers of 1/255 saturate');
    }

    public function testTouchDispatchHitsTheTopmostAndBubbles(): void
    {
        $group = new Sprite();
        $q = new Quad(20, 20);
        $q->x = 10;
        $q->y = 10;
        $group->addChild($q);
        $this->engine->stage->addChild($group);

        $log = [];
        $q->addEventListener(\Phame\Events\Event::TOUCH, function (TouchEvent $e) use (&$log) { $log[] = ['quad', $e->phase, $e->target]; });
        $group->addEventListener(\Phame\Events\Event::TOUCH, function (TouchEvent $e) use (&$log) { $log[] = ['group', $e->phase, $e->getLocation($e->currentTarget)]; });

        $this->engine->pointer(15, 15, TouchEvent::BEGAN, 1);
        $this->engine->pointer(16, 15, TouchEvent::MOVED, 1);
        $this->engine->pointer(16, 15, TouchEvent::ENDED, 1);

        $this->assertSame(['quad', 'began', $q], $log[0]);
        $this->assertSame(['group', 'began', [15.0, 15.0]], $log[1]);
        $this->assertSame('moved', $log[2][1]);
        $this->assertSame('ended', $log[4][1]);
        $this->assertCount(6, $log);
    }

    public function testDraggedTouchKeepsTargetingTheObjectItBeganOn(): void
    {
        $q = new Quad(10, 10);
        $this->engine->stage->addChild($q);
        $targets = [];
        $q->addEventListener(\Phame\Events\Event::TOUCH, function (TouchEvent $e) use (&$targets) { $targets[] = $e->phase; });
        $this->engine->pointer(5, 5, TouchEvent::BEGAN, 1);
        $this->engine->pointer(50, 40, TouchEvent::MOVED, 1);   // far outside the quad
        $this->engine->pointer(50, 40, TouchEvent::ENDED, 1);
        $this->assertSame(['began', 'moved', 'ended'], $targets);
    }

    public function testLetterboxMapsWindowToStageCoordinates(): void
    {
        $this->engine->dispose();
        // 64x48 stage in a 128x48 window: scale 1, bars of 32 pixels on each side
        $this->engine = $this->createEngine(64, 48, 128, 48);
        $q = new Quad(64, 48);
        $q->color = 0xFFFFFF;
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 10, 10, message: 'left bar');
        $this->assertPixel([255, 255, 255, 255], 64, 24, message: 'stage');
        $this->assertPixel([0, 0, 0, 255], 120, 10, message: 'right bar');
        $this->assertEqualsWithDelta([0.0, 24.0], $this->engine->windowToStage(32, 24), 1e-9);
        $this->assertEqualsWithDelta([64.0, 0.0], $this->engine->windowToStage(96, 0), 1e-9);
    }

    public function testScreenshotIsADecodablePng(): void
    {
        $this->engine->stage->color = 0x123456;
        $this->engine->render();
        $path = sys_get_temp_dir() . '/phame-test-' . getmypid() . '.png';
        try {
            $this->engine->saveScreenshot($path);
            $d = \Phame\Textures\Png::decode(file_get_contents($path));
            $this->assertSame([64, 48], [$d['width'], $d['height']]);
            $this->assertSame("\x12\x34\x56\xff", substr($d['rgba'], 0, 4));
        } finally {
            @unlink($path);
        }
    }
}
