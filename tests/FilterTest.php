<?php

namespace Phame\Tests;

use Phame\Display\{Quad, Sprite};
use Phame\Filters\{BlurFilter, BloomFilter, ColorMatrixFilter, GlowFilter};
use Phame\GL\Shader;
use Phame\Rendering\Filter;

final class FilterTest extends GlTestCase
{
    private function quad(float $w, float $h, int $color, float $x = 0, float $y = 0): Quad
    {
        $q = new Quad($w, $h);
        $q->color = $color;
        $q->x = $x;
        $q->y = $y;
        return $q;
    }

    public function testIdentityFilterDrawsTheObjectUnchanged(): void
    {
        $q = $this->quad(10, 10, 0xFF0000, 20, 12);
        $q->filter = new Filter('vec4 filterPixel(vec2 uv) { return texture(uTexture, uv); }');
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 21, 13, message: 'top left corner');
        $this->assertPixel([255, 0, 0, 255], 29, 21, message: 'bottom right corner');
        $this->assertPixel([0, 0, 0, 255], 19, 13, message: 'left of it');
        $this->assertPixel([0, 0, 0, 255], 21, 11, message: 'above it');
        $this->assertPixel([0, 0, 0, 255], 31, 21, message: 'right of it');
    }

    public function testFilterFollowsTheParentTransformAndAlpha(): void
    {
        $group = new Sprite();
        $group->x = 30;
        $group->y = 10;
        $group->scaleX = $group->scaleY = 2;
        $group->alpha = 0.5;
        $group->filter = new Filter('vec4 filterPixel(vec2 uv) { return texture(uTexture, uv); }');
        $group->addChild($this->quad(5, 5, 0xFFFFFF));
        $this->engine->stage->addChild($group);
        $this->engine->render();
        $this->assertPixel([128, 128, 128, 255], 35, 15, 3, 'half transparent white over black, scaled up');
        $this->assertPixel([0, 0, 0, 255], 41, 15, message: 'beyond the scaled quad');
    }

    /** Pass 3 critic suspected rotated filtered objects were clipped to their unrotated bounds. */
    public function testRotatedAndScaledFilteredObjectMatchesUnfiltered(): void
    {
        $make = function (?Filter $filter): string {
            $this->engine->stage->removeChildren();
            $group = new Sprite();
            $group->addChild($this->quad(20, 8, 0xFF8000, -10, -4));
            $group->x = 32;
            $group->y = 24;
            $group->rotation = M_PI / 5;
            $group->scaleX = 1.5;
            $group->filter = $filter;
            $this->engine->stage->addChild($group);
            $this->engine->render();
            return $this->engine->readPixels()[2];
        };
        $plain = $make(null);
        $filtered = $make(new Filter('vec4 filterPixel(vec2 uv) { return texture(uTexture, uv); }'));
        $differing = 0;
        for ($i = 0, $n = strlen($plain); $i < $n; ++$i) {
            $differing += abs(ord($plain[$i]) - ord($filtered[$i])) > 40 ? 1 : 0;
        }
        $this->assertGreaterThan(200, strlen($plain) / 4 - substr_count(chunk_split($plain, 4, '|'), "\0\0\0\xFF|"), 'the quad covers ~240 pixels');
        $this->assertLessThan(40, $differing, 'only edge texels differ (resampling), nothing is clipped');
    }

    public function testShaderIsApplied(): void
    {
        $q = $this->quad(8, 8, 0xFF0000, 4, 4);
        $q->filter = new Filter('vec4 filterPixel(vec2 uv) { vec4 c = texture(uTexture, uv); return vec4(c.bgr, c.a); }');
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([0, 0, 255, 255], 8, 8);
    }

    public function testCustomUniformsAndPassesAreSet(): void
    {
        $seen = [];
        $filter = new Filter(<<<'GLSL'
            uniform float uAmount;
            vec4 filterPixel(vec2 uv) { return texture(uTexture, uv) * uAmount; }
            GLSL, 2, function (Shader $s, int $pass) use (&$seen) {
                $seen[] = $pass;
                $s->set('uAmount', 0.5);
            });
        $q = $this->quad(8, 8, 0xFFFFFF, 4, 4);
        $q->filter = $filter;
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertSame([0, 1], $seen);
        $this->assertPixel([64, 64, 64, 255], 8, 8, 3, 'two passes each halve it, premultiplied over black');
    }

    public function testMarginLetsTheFilterDrawOutsideTheBounds(): void
    {
        $q = $this->quad(4, 4, 0xFFFFFF, 20, 20);
        $filter = new Filter('vec4 filterPixel(vec2 uv) { return vec4(1.0); }');
        $q->filter = $filter;
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 23, 23, message: 'the filter paints the object rectangle');
        $this->assertPixel([0, 0, 0, 255], 18, 18, message: 'but not around it');
        $filter->margin = 3;
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 18, 18, message: 'with a margin it does');
        $this->assertPixel([255, 255, 255, 255], 26, 26);
        $this->assertPixel([0, 0, 0, 255], 16, 16);
    }

    public function testUOriginalAndTexelSizeAreAvailable(): void
    {
        $q = $this->quad(8, 8, 0x00FF00, 4, 4);
        $q->filter = new Filter(<<<'GLSL'
            vec4 filterPixel(vec2 uv) {
                float ok = uTexelSize.x > 0.0 && uTexelSize.x < 0.1 ? 1.0 : 0.0;
                return texture(uOriginal, uv) * ok;
            }
            GLSL);
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 8, 8);
    }

    public function testBlurSpreadsColorBeyondTheObject(): void
    {
        $q = $this->quad(10, 10, 0xFFFFFF, 27, 19);
        $q->filter = new BlurFilter(6.0);
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $inside = $this->pixel(32, 24);
        $outside = $this->pixel(40, 24);
        $far = $this->pixel(3, 3);
        $this->assertGreaterThan(150, $inside[0], 'the middle stays bright');
        $this->assertLessThan(255, $inside[0] + 1);
        $this->assertGreaterThan(5, $outside[0], 'light has spread to the right of the 10 point wide quad');
        $this->assertLessThan(150, $outside[0]);
        $this->assertSame([0, 0, 0, 255], $far);
    }

    public function testBloomHaloComesOnlyFromBrightParts(): void
    {
        $bright = new Sprite();
        $bright->addChild($this->quad(10, 10, 0xFFFFFF, 27, 19));
        $bright->filter = new BloomFilter(1.0, 6.0, 0.5);
        $this->engine->stage->addChild($bright);
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 32, 24, message: 'the object itself is unchanged');
        $this->assertGreaterThan(5, $this->pixel(40, 24)[0], 'a halo around the bright quad');

        $this->engine->stage->removeChild($bright);
        $dim = new Sprite();
        $dim->addChild($this->quad(10, 10, 0x303030, 27, 19));
        $dim->filter = new BloomFilter(1.0, 6.0, 0.5);
        $this->engine->stage->addChild($dim);
        $this->engine->render();
        $this->assertSame(0, $this->pixel(40, 24)[0], 'nothing below the threshold blooms');
    }

    public function testGlowAddsAColoredHaloBehindTheObject(): void
    {
        $q = $this->quad(8, 8, 0xFF0000, 28, 20);
        $q->filter = new GlowFilter(0x00FF00, 2.0, 6.0);
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 32, 24, message: 'the object itself is on top');
        $halo = $this->pixel(38, 24);
        $this->assertGreaterThan(20, $halo[1], 'green halo next to it');
        $this->assertLessThan(10, $halo[0]);
    }

    public function testDropShadowIsOffset(): void
    {
        $q = $this->quad(8, 8, 0xFF0000, 16, 16);
        $q->filter = new GlowFilter(0xFFFFFF, 3.0, 2.0, 12, 0);
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertGreaterThan(150, $this->pixel(32, 20)[1], 'shadow moved 12 points to the right');
        $this->assertLessThan(10, $this->pixel(12, 20)[1], 'nothing on the left');
    }

    public function testColorMatrixGrayscaleAndInvert(): void
    {
        $q = $this->quad(8, 8, 0xFF0000, 4, 4);
        $q->filter = ColorMatrixFilter::grayscale();
        $this->engine->stage->addChild($q);
        $this->engine->render();
        [$r, $g, $b] = $this->pixel(8, 8);
        $this->assertEqualsWithDelta(54, $r, 2);
        $this->assertSame($r, $g);
        $this->assertSame($r, $b);

        $q->filter = ColorMatrixFilter::invert();
        $this->engine->render();
        $this->assertPixel([0, 255, 255, 255], 8, 8);
    }

    public function testColorMatrixKeepsTransparencyIntact(): void
    {
        $q = $this->quad(8, 8, 0xFF0000, 4, 4);
        $q->alpha = 0.5;
        $q->filter = ColorMatrixFilter::tint(0x0000FF, 1.0);
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $this->assertPixel([0, 0, 128, 255], 8, 8, 3, 'blue at half alpha over black');
    }

    public function testNestedFiltersCompose(): void
    {
        $outer = new Sprite();
        $outer->x = 10;
        $outer->y = 10;
        $inner = $this->quad(8, 8, 0xFF0000, 4, 4);
        $inner->filter = ColorMatrixFilter::invert();
        $outer->addChild($inner);
        $outer->filter = ColorMatrixFilter::grayscale();
        $this->engine->stage->addChild($outer);
        $this->engine->render();
        [$r, $g, $b] = $this->pixel(18, 18);
        $this->assertSame($r, $g);
        $this->assertGreaterThan(150, $r, 'inverted red is cyan, whose luma is high');
    }

    public function testFilterOnObjectSizedDifferentlyEachFrameDoesNotBreak(): void
    {
        $q = $this->quad(10, 10, 0xFFFFFF, 5, 5);
        $q->filter = new BlurFilter(2.0);
        $this->engine->stage->addChild($q);
        foreach ([10, 40, 70, 10] as $size) {
            $q->width = $size;
            $this->engine->render();
        }
        $this->assertGreaterThan(200, $this->pixel(8, 8)[0]);
        $this->engine->gl->checkError('after filter resizes');
    }

    public function testInvisibleOrEmptyObjectsAreSkipped(): void
    {
        $empty = new Sprite();
        $empty->filter = new BlurFilter();
        $this->engine->stage->addChild($empty);
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 5, 5);
    }

    public function testBadShaderReportsTheCompilerLog(): void
    {
        $q = $this->quad(4, 4, 0xFFFFFF);
        $q->filter = new Filter('vec4 filterPixel(vec2 uv) { return nonsense; }');
        $this->engine->stage->addChild($q);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fragment shader failed to compile');
        $this->engine->render();
    }

    public function testDisposeObjectDisposesFilter(): void
    {
        $q = $this->quad(4, 4, 0xFFFFFF);
        $q->filter = new BlurFilter();
        $this->engine->stage->addChild($q);
        $this->engine->render();
        $q->dispose();
        $this->engine->gl->checkError('after dispose');
        $this->assertTrue(true);
    }
}
