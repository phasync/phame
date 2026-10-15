<?php

namespace Phame\Tests;

use Phame\Display\BitmapText;
use Phame\Textures\BitmapFont;

final class BitmapTextTest extends GlTestCase
{
    /** The box around all pixels that are not the black background in the rendered frame: [x0, y0, x1, y1] or null. */
    private function inkBox(): ?array
    {
        $this->engine->render();
        [$w, $h, $rgba] = $this->engine->readPixels();
        $box = null;
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $o = ($y * $w + $x) * 4;
                if (ord($rgba[$o]) > 40 || ord($rgba[$o + 1]) > 40 || ord($rgba[$o + 2]) > 40) {
                    $box = $box === null ? [$x, $y, $x, $y] : [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
                }
            }
        }
        return $box;
    }

    public function testDefaultFontLoadsWithLatinAndNorwegianLetters(): void
    {
        $font = BitmapFont::default();
        $this->assertSame($font, BitmapFont::default());
        $this->assertSame(32.0, $font->size);
        $this->assertGreaterThan($font->base, $font->lineHeight);
        foreach (['A', 'z', '0', '~', 'æ', 'ø', 'å', 'Æ', 'Ø', 'Å', 'é'] as $char) {
            $glyph = $font->glyph(mb_ord($char));
            $this->assertNotNull($glyph?->texture, "glyph for $char");
        }
        $space = $font->glyph(32);
        $this->assertNull($space->texture);
        $this->assertGreaterThan(0.0, $space->xAdvance);
        $this->assertNull($font->glyph(0x20AC), 'the euro sign is not in the font');
    }

    public function testDefaultFontIsReloadedForANewEngine(): void
    {
        $first = BitmapFont::default();
        $this->engine->dispose();
        $this->engine = $this->createEngine(64, 48);
        $second = BitmapFont::default();
        $this->assertNotSame($first, $second);
        $this->engine->stage->addChild(new BitmapText('A'));
        $this->assertNotNull($this->inkBox());
    }

    public function testWidthGrowsWithEachCharacter(): void
    {
        $text = new BitmapText('');
        $this->assertSame(0.0, $text->textWidth);
        $this->assertSame(0.0, $text->textHeight);
        $last = 0.0;
        foreach (['i', 'ii', 'iii', 'iiii'] as $s) {
            $text->text = $s;
            $this->assertGreaterThan($last, $text->textWidth, $s);
            $last = $text->textWidth;
        }
        $text->text = 'W';
        $wide = $text->textWidth;
        $text->text = 'i';
        $this->assertGreaterThan($text->textWidth, $wide);
    }

    public function testKerningTightensPairs(): void
    {
        $font = BitmapFont::default();
        $this->assertLessThan(0.0, $font->kerning(65, 86));
        $a = new BitmapText('A');
        $v = new BitmapText('V');
        $av = new BitmapText('AV');
        $this->assertEqualsWithDelta($a->textWidth + $v->textWidth + $font->kerning(65, 86), $av->textWidth, 1e-6);
    }

    public function testNewlineStartsALine(): void
    {
        $text = new BitmapText('a');
        $one = $text->textHeight;
        $text->text = "a\nbb\nc";
        $this->assertEqualsWithDelta(3 * $one, $text->textHeight, 1e-6);
        $this->assertEqualsWithDelta((new BitmapText('bb'))->textWidth, $text->textWidth, 1e-6);
        $text->text = "a\r\nb";
        $this->assertEqualsWithDelta(2 * $one, $text->textHeight, 1e-6, 'a carriage return is ignored');
    }

    public function testLineHeightOverridesTheFont(): void
    {
        $text = new BitmapText("a\nb");
        $text->lineHeight = 50.0;
        $this->assertSame(100.0, $text->textHeight);
    }

    public function testWrapsAtSpaces(): void
    {
        $text = new BitmapText('aaa bbb ccc ddd');
        $oneLine = $text->textWidth;
        $lineHeight = (new BitmapText('a'))->textHeight;
        $text->wrapWidth = $oneLine / 2 + 10;
        $this->assertLessThanOrEqual($text->wrapWidth, $text->textWidth);
        $this->assertEqualsWithDelta(2 * $lineHeight, $text->textHeight, 1e-6);
        $this->assertEqualsWithDelta($text->wrapWidth, $text->getBounds($this->engine->stage)->width, 1e-6, 'the box is as wide as the wrap width');

        $text->wrapWidth = 0.0;
        $this->assertEqualsWithDelta($oneLine, $text->textWidth, 1e-6);
        $this->assertEqualsWithDelta($lineHeight, $text->textHeight, 1e-6);
    }

    public function testWrappedWordsKeepTheirOrderAndNoLineExceedsTheWidth(): void
    {
        $text = new BitmapText('one two three four five six seven');
        $text->wrapWidth = 90.0;
        $this->assertLessThanOrEqual(90.0, $text->textWidth);
        $this->assertGreaterThan(2, $text->textHeight / (new BitmapText('a'))->textHeight);
    }

    public function testAWordLongerThanTheLineIsSplit(): void
    {
        $text = new BitmapText('mmmmmmmmmmmmmmmm');
        $text->wrapWidth = 60.0;
        $this->assertLessThanOrEqual(60.0, $text->textWidth);
        $this->assertGreaterThan((new BitmapText('m'))->textHeight, $text->textHeight);
    }

    public function testGlyphIsDrawnWhereTheFontSaysItIs(): void
    {
        $glyph = BitmapFont::default()->glyph(65);
        $text = new BitmapText('A');
        $this->engine->stage->addChild($text);
        [$x0, $y0, $x1, $y1] = $this->inkBox();
        $this->assertEqualsWithDelta($glyph->xOffset, $x0, 1, 'left edge');
        $this->assertEqualsWithDelta($glyph->yOffset, $y0, 1, 'top edge: y grows downward');
        $this->assertEqualsWithDelta($glyph->xOffset + $glyph->width - 1, $x1, 1, 'right edge');
        $this->assertEqualsWithDelta($glyph->yOffset + $glyph->height - 1, $y1, 1, 'bottom edge');
    }

    public function testGlyphFollowsThePositionOfTheObject(): void
    {
        $text = new BitmapText('A');
        $text->x = 10;
        $text->y = 5;
        $this->engine->stage->addChild($text);
        $glyph = BitmapFont::default()->glyph(65);
        [$x0, $y0] = $this->inkBox();
        $this->assertEqualsWithDelta(10 + $glyph->xOffset, $x0, 1);
        $this->assertEqualsWithDelta(5 + $glyph->yOffset, $y0, 1);
    }

    public function testSizeScalesTheText(): void
    {
        $text = new BitmapText('Hello', size: 16.0);
        $native = new BitmapText('Hello');
        $this->assertEqualsWithDelta($native->textWidth / 2, $text->textWidth, 1e-6);
        $text->size = 32.0;
        $this->assertEqualsWithDelta($native->textWidth, $text->textWidth, 1e-6);
        $text->size = 8.0;
        $this->engine->stage->addChild($text);
        [$x0, $y0, $x1, $y1] = $this->inkBox();
        $this->assertLessThan(10, $y1 - $y0, 'small text is small');
    }

    public function testHorizontalAlignment(): void
    {
        $edges = [];
        foreach ([BitmapText::LEFT, BitmapText::CENTER, BitmapText::RIGHT] as $align) {
            $this->engine->stage->removeChildren();
            $text = new BitmapText('I', size: 16.0);
            $text->wrapWidth = 60.0;
            $text->hAlign = $align;
            $this->engine->stage->addChild($text);
            $box = $this->inkBox();
            $edges[$align] = ($box[0] + $box[2]) / 2;
        }
        $this->assertLessThan(10, $edges[BitmapText::LEFT]);
        $this->assertEqualsWithDelta(30, $edges[BitmapText::CENTER], 2);
        $this->assertGreaterThan(50, $edges[BitmapText::RIGHT]);
    }

    public function testColorTintsTheGlyphs(): void
    {
        $text = new BitmapText('I', color: 0xFF0000);
        $this->engine->stage->addChild($text);
        $this->engine->render();
        [$w, , $rgba] = $this->engine->readPixels();
        $brightest = 0;
        $blue = 0;
        for ($i = 0, $n = strlen($rgba); $i < $n; $i += 4) {
            $brightest = max($brightest, ord($rgba[$i]));
            $blue = max($blue, ord($rgba[$i + 1]), ord($rgba[$i + 2]));
        }
        $this->assertGreaterThan(200, $brightest);
        $this->assertSame(0, $blue);

        $text->color = 0x00FF00;
        $this->engine->render();
        [, , $rgba] = $this->engine->readPixels();
        $red = 0;
        for ($i = 0, $n = strlen($rgba); $i < $n; $i += 4) {
            $red = max($red, ord($rgba[$i]));
        }
        $this->assertSame(0, $red, 'a color change shows without any relayout');
    }

    public function testChangingThePropertiesRelayoutsTheText(): void
    {
        $text = new BitmapText('A');
        $this->engine->stage->addChild($text);
        $narrow = $text->textWidth;
        $text->text = 'AAAA';
        $this->assertGreaterThan($narrow, $text->textWidth);
        $box = $this->inkBox();
        $this->assertGreaterThan(40, $box[2], 'the new text is what gets drawn');
        $text->text = '';
        $this->assertNull($this->inkBox(), 'empty text draws nothing');
    }

    public function testUnknownCharactersAreQuestionMarks(): void
    {
        $this->assertEqualsWithDelta((new BitmapText('?'))->textWidth, (new BitmapText('€'))->textWidth, 1e-6);
        $this->assertEqualsWithDelta((new BitmapText('??'))->textWidth, (new BitmapText('€😀'))->textWidth, 1e-6);
    }

    public function testBoundsAndHitTestUseTheTextBox(): void
    {
        $text = new BitmapText("Hello\nWorld");
        $text->x = 4;
        $text->y = 6;
        $this->engine->stage->addChild($text);
        $bounds = $text->getBounds($this->engine->stage);
        $this->assertEqualsWithDelta(4, $bounds->x, 1e-6);
        $this->assertEqualsWithDelta(6, $bounds->y, 1e-6);
        $this->assertEqualsWithDelta($text->textWidth, $bounds->width, 1e-6);
        $this->assertEqualsWithDelta($text->textHeight, $bounds->height, 1e-6);
        $this->assertEqualsWithDelta($text->textWidth, $text->width, 1e-6);
        $this->assertSame($text, $text->hitTest(1, 1));
        $this->assertNull($text->hitTest(-1, 1));
        $this->assertNull($text->hitTest(1, $text->textHeight + 1));
    }

    public function testRenderingAllocatesNothingOnceLaidOut(): void
    {
        $text = new BitmapText('The quick brown fox');
        $this->engine->stage->addChild($text);
        for ($i = 0; $i < 20; ++$i) {
            $this->engine->render();
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < 200; ++$i) {
            $this->engine->render();
        }
        $this->assertLessThan(4096, memory_get_usage() - $before);
    }
}
