<?php

namespace Phame\Tests;

use Phame\Display\{Image, Quad, Sprite};
use Phame\Scene\{Geometry, Material, Mesh, Scene3D};
use Phame\Textures\{RenderTexture, Texture};

final class RenderTextureTest extends GlTestCase
{
    private function quad(float $w, float $h, int $color, float $x = 0, float $y = 0): Quad
    {
        $q = new Quad($w, $h);
        $q->color = $color;
        $q->x = $x;
        $q->y = $y;
        return $q;
    }

    private function texel(string $rgba, int $w, int $x, int $y): array
    {
        return array_values(unpack('C4', $rgba, ($y * $w + $x) * 4));
    }

    public function testDrawsWithTopLeftOriginAndTransparentBackground(): void
    {
        $rt = new RenderTexture(16, 16);
        $rt->draw($this->quad(4, 4, 0xFF0000, 2, 2));
        $px = $rt->readPixels();
        $this->assertSame([255, 0, 0, 255], $this->texel($px, 16, 3, 3));
        $this->assertSame(0, $this->texel($px, 16, 12, 12)[3], 'outside the quad stays transparent');
        $this->assertSame(0, $this->texel($px, 16, 3, 13)[3], 'y grows downward: bottom is empty');
    }

    public function testShowingTheTextureOnStageIsNotFlipped(): void
    {
        $rt = new RenderTexture(16, 16);
        $rt->draw($this->quad(16, 4, 0x00FF00));
        $image = new Image($rt->texture);
        $image->x = 10;
        $image->y = 10;
        $this->engine->stage->addChild($image);
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 12, 11, message: 'the green strip is at the top of the image');
        $this->assertPixel([0, 0, 0, 255], 12, 20, message: 'the rest is transparent');
    }

    public function testRegionOfTheTextureStaysUpright(): void
    {
        $rt = new RenderTexture(16, 16);
        $rt->draw($this->quad(16, 4, 0x00FF00));
        $bottom = new Image($rt->texture->region(0, 8, 16, 8));
        $top = new Image($rt->texture->region(0, 0, 16, 8));
        $top->x = 20;
        $this->engine->stage->addChild($bottom);
        $this->engine->stage->addChild($top);
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 2, 2, message: 'lower half has no green');
        $this->assertPixel([0, 255, 0, 255], 22, 2, message: 'upper half starts with the strip');
        $this->assertPixel([0, 0, 0, 255], 22, 6, message: 'strip is 4 points tall');
    }

    public function testStageIsUntouchedAndStateRestoredAfterDrawing(): void
    {
        $this->engine->stage->color = 0x202020;
        $rt = new RenderTexture(8, 8);
        $rt->draw($this->quad(8, 8, 0xFFFFFF));
        $this->engine->stage->addChild($this->quad(6, 6, 0x0000FF, 30, 30));
        $this->engine->render();
        $this->assertPixel([0x20, 0x20, 0x20, 255], 2, 2, message: 'the render texture did not draw to the window');
        $this->assertPixel([0, 0, 255, 255], 32, 32, message: 'drawing works at stage scale afterwards');
    }

    public function testClearFalseAccumulates(): void
    {
        $rt = new RenderTexture(8, 8);
        $rt->draw($this->quad(4, 4, 0xFF0000));
        $rt->draw($this->quad(4, 4, 0x0000FF, 4, 4), false);
        $px = $rt->readPixels();
        $this->assertSame([255, 0, 0, 255], $this->texel($px, 8, 1, 1));
        $this->assertSame([0, 0, 255, 255], $this->texel($px, 8, 6, 6));
    }

    public function testSemiTransparentContentIsPremultipliedAndReadBackStraight(): void
    {
        $rt = new RenderTexture(4, 4);
        $q = $this->quad(4, 4, 0xFF0000);
        $q->alpha = 0.5;
        $rt->draw($q);
        $px = $this->texel($rt->readPixels(), 4, 1, 1);
        $this->assertEqualsWithDelta(255, $px[0], 2);
        $this->assertEqualsWithDelta(128, $px[3], 2);
    }

    public function testRendersNestedRenderTexturesAndScale(): void
    {
        $inner = new RenderTexture(4, 4);
        $inner->draw($this->quad(4, 4, 0xFF00FF));
        $outer = new RenderTexture(8, 8, 2.0);
        $this->assertSame(16, $outer->pixelWidth);
        $img = new Image($inner->texture);
        $img->x = 4;
        $img->y = 4;
        $outer->draw($img);
        $px = $outer->readPixels();
        $this->assertSame([255, 0, 255, 255], $this->texel($px, 16, 12, 12));
        $this->assertSame(0, $this->texel($px, 16, 3, 3)[3]);
    }

    public function testScene3DRendersIntoATextureWithDepth(): void
    {
        $scene = new Scene3D(32, 32);
        $scene->background = 0x000000;
        $near = new Mesh(Geometry::box(1, 1, 1), new Material(0xFF0000, unlit: true));
        $far = new Mesh(Geometry::box(3, 3, 1), new Material(0x0000FF, unlit: true));
        $near->z = 0;
        $far->z = -2;
        $scene->root->addChild($far);
        $scene->root->addChild($near);
        $rt = new RenderTexture(32, 32);
        $rt->draw($scene);
        $px = $rt->readPixels();
        $this->assertSame([255, 0, 0, 255], $this->texel($px, 32, 16, 16), 'near box in front of far box');
        $this->assertSame([0, 0, 255, 255], $this->texel($px, 32, 16, 11), 'far box above the near one');
        $this->engine->stage->addChild(new Image($rt->texture));
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 16, 16, message: 'upright when shown');
    }

    public function testDisposeFreesTheFramebuffer(): void
    {
        $rt = new RenderTexture(4, 4);
        $rt->dispose();
        $rt->dispose();
        $this->engine->gl->checkError('after dispose');
        $this->assertTrue(true);
    }
}
