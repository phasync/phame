<?php

namespace Phame\Tests;

use Phame\Async;
use Phame\Display\MovieClip;
use Phame\Events\Event;
use Phame\Textures\{Texture, TextureAtlas};

final class MovieClipTest extends GlTestCase
{
    /** A 4 x 1 sheet of 8 x 8 cells: red, green, blue, white. */
    private function sheet(): TextureAtlas
    {
        $rgba = '';
        for ($y = 0; $y < 8; ++$y) {
            foreach (["\xFF\0\0\xFF", "\0\xFF\0\xFF", "\0\0\xFF\xFF", "\xFF\xFF\xFF\xFF"] as $px) {
                $rgba .= str_repeat($px, 8);
            }
        }
        return TextureAtlas::grid(Texture::fromRgba(32, 8, $rgba, smooth: false), 8, 8);
    }

    public function testGridCutsCellsAndFramesSortNaturally(): void
    {
        $atlas = $this->sheet();
        $this->assertCount(4, $atlas->frames());
        $atlas->add('run_10', 0, 0, 8, 8);
        $atlas->add('run_2', 8, 0, 8, 8);
        $frames = $atlas->frames('run_');
        $this->assertSame($atlas->get('run_2'), $frames[0], 'run_2 before run_10');
        $this->expectException(\OutOfBoundsException::class);
        $atlas->frames('jump_');
    }

    public function testGridRowNamesMakeOneAnimationPerRow(): void
    {
        $sheet = $this->sheet()->texture;   // 32 x 8: one row of four cells
        $atlas = TextureAtlas::grid($sheet, 8, 8, ['walk']);
        $this->assertCount(4, $atlas->frames('walk_'));
        $this->assertEqualsWithDelta(0.25, $atlas->get('walk_1')->u0, 1e-9);
    }

    public function testPlaysAtItsFrameRateInGameTimeAndLoops(): void
    {
        $clip = new MovieClip($this->sheet()->frames(), 10);
        $this->engine->stage->addChild($clip);
        $seen = [];
        $this->engine->maxFrames = 9;
        $this->engine->clock->forcedDelta = 0.05;   // two game frames per clip frame
        $this->engine->run(function () use ($clip, &$seen) {
            while (true) {
                Async::awaitFrame();
                $seen[] = $clip->currentFrame;
            }
        });
        // awaitFrame resumes after the frame's advance: frames at t = 0.05, 0.10, ...
        $this->assertSame([0, 1, 1, 2, 2, 3, 3, 0, 0], $seen);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 4, 4, message: 'frame 0 is the red cell');
    }

    public function testNonLoopingClipStopsOnTheLastFrameAndDispatchesComplete(): void
    {
        $clip = new MovieClip($this->sheet()->frames(), 20);
        $clip->loop = false;
        $this->engine->stage->addChild($clip);
        $completedAt = null;
        $this->engine->maxFrames = 10;
        $this->engine->clock->forcedDelta = 0.05;
        $this->engine->run(function ($engine) use ($clip, &$completedAt) {
            Async::awaitEvent($clip, Event::COMPLETE);
            $completedAt = $engine->clock->frame;
        });
        $this->assertSame(4, $completedAt, '4 frames at 20 fps take 0.2 s');
        $this->assertSame(3, $clip->currentFrame);
        $this->assertFalse($clip->playing);
        $clip->play();
        $this->assertSame(0, $clip->currentFrame, 'play() after the end starts over');
    }

    public function testSwitchingFramesAndStop(): void
    {
        $atlas = $this->sheet();
        $clip = new MovieClip($atlas->frames(), 10);
        $clip->currentFrame = 2;
        $this->assertSame($atlas->get('2'), $clip->texture);
        $clip->frames = [$atlas->get('3'), $atlas->get('0')];
        $this->assertSame(0, $clip->currentFrame, 'a new animation starts at its first frame');
        $this->assertSame(2, $clip->numFrames);
        $clip->currentFrame = 99;
        $this->assertSame(1, $clip->currentFrame, 'clamped');
        $clip->stop();
        $this->assertSame(0, $clip->currentFrame);
        $this->assertFalse($clip->playing);
    }

    public function testSparrowAtlasLoads(): void
    {
        $dir = sys_get_temp_dir() . '/phame-atlas-' . getmypid();
        @mkdir($dir);
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is needed to write the test PNG');
        }
        $im = imagecreatetruecolor(16, 8);
        imagefilledrectangle($im, 8, 0, 15, 7, imagecolorallocate($im, 0, 255, 0));
        imagepng($im, "$dir/sheet.png");
        file_put_contents("$dir/sheet.xml", '<TextureAtlas imagePath="sheet.png"><SubTexture name="a" x="0" y="0" width="8" height="8"/><SubTexture name="b" x="8" y="0" width="8" height="8"/></TextureAtlas>');
        $atlas = TextureAtlas::fromSparrow("$dir/sheet.xml");
        $this->assertSame(8.0, $atlas->get('b')->width);
        $this->assertEqualsWithDelta(0.5, $atlas->get('b')->u0, 1e-9);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
