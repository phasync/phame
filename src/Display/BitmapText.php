<?php

namespace Phame\Display;

use Phame\Math\Rectangle;
use Phame\Rendering\Painter;
use Phame\Textures\BitmapFont;
use Phame\Textures\Glyph;

/**
 * Text drawn from a BitmapFont, one textured quad per character. Characters are laid out when text, font, size,
 * hAlign, wrapWidth or lineHeight changes, not while rendering, and rendering allocates nothing.
 *
 * "\n" starts a new line. With a wrapWidth the text wraps at spaces (a word longer than the line is split).
 * Characters the font lacks are drawn as '?', or skipped if the font has no '?'.
 *
 * width and height are the inherited ones: the size of the text box in the parent's coordinates, and setting
 * them scales the object. The wrap width is wrapWidth.
 */
class BitmapText extends DisplayObject
{
    public const LEFT = 0;
    public const CENTER = 1;
    public const RIGHT = 2;

    public string $text = '' {
        set {
            if ($value !== $this->text) {
                $this->text = $value;
                $this->dirty = true;
            }
        }
    }

    public BitmapFont $font {
        set {
            $this->font = $value;
            $this->dirty = true;
        }
    }

    /** The size of the text in pixels, as the font's native size when the object is unscaled. */
    public float $size {
        set {
            $this->size = max(1.0, $value);
            $this->dirty = true;
        }
    }

    /** Tint, 0xRRGGBB. */
    public int $color;

    public int $hAlign = self::LEFT {
        set {
            $this->hAlign = max(self::LEFT, min(self::RIGHT, $value));
            $this->dirty = true;
        }
    }

    /** Lines wrap to this width, and alignment is relative to it. 0 means no wrapping. */
    public float $wrapWidth = 0.0 {
        set {
            $this->wrapWidth = max(0.0, $value);
            $this->dirty = true;
        }
    }

    /** Distance between lines in pixels. 0 uses the font's, scaled to size. */
    public float $lineHeight = 0.0 {
        set {
            $this->lineHeight = max(0.0, $value);
            $this->dirty = true;
        }
    }

    /** The width of the widest line. */
    public float $textWidth {
        get {
            $this->layout();
            return $this->widest;
        }
    }

    public float $textHeight {
        get {
            $this->layout();
            return $this->heightOfText;
        }
    }

    private bool $dirty = true;
    private float $widest = 0.0;
    private float $heightOfText = 0.0;
    private float $boxWidth = 0.0;
    private int $count = 0;
    /** @var array<int, float> left and top of each laid out character, and its size, in this object's pixels */
    private array $gx = [];
    private array $gy = [];
    private array $gw = [];
    private array $gh = [];
    /** @var array<int, ?\Phame\Textures\Texture> null for characters without ink */
    private array $glyphTexture = [];

    public function __construct(string $text = '', ?BitmapFont $font = null, float $size = 0.0, int $color = 0xFFFFFF)
    {
        $this->font = $font ?? BitmapFont::default();
        $this->size = $size > 0.0 ? $size : $this->font->size;
        $this->color = $color;
        $this->text = $text;
    }

    public function getBounds(?DisplayObject $space): Rectangle
    {
        $this->layout();
        $m = $this->getTransformationMatrix($space);
        $xs = $ys = [];
        foreach ([[0.0, 0.0], [$this->boxWidth, 0.0], [0.0, $this->heightOfText], [$this->boxWidth, $this->heightOfText]] as [$px, $py]) {
            [$xs[], $ys[]] = $m->transform($px, $py);
        }
        return new Rectangle(min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys));
    }

    public function hitTest(float $x, float $y): ?DisplayObject
    {
        if (!$this->visible || !$this->touchable) {
            return null;
        }
        $this->layout();
        return $x >= 0.0 && $y >= 0.0 && $x < $this->boxWidth && $y < $this->heightOfText ? $this : null;
    }

    public function render(Painter $painter): void
    {
        $this->layout();
        $tx = $painter->tx;
        $ty = $painter->ty;
        $a = $painter->a;
        $b = $painter->b;
        $c = $painter->c;
        $d = $painter->d;
        for ($i = 0, $n = $this->count; $i < $n; ++$i) {
            $texture = $this->glyphTexture[$i];
            if ($texture === null) {
                continue;
            }
            $x = $this->gx[$i];
            $y = $this->gy[$i];
            $painter->tx = $tx + $a * $x + $c * $y;
            $painter->ty = $ty + $b * $x + $d * $y;
            $painter->drawQuad($texture, $this->gw[$i], $this->gh[$i], $this->color);
        }
        $painter->tx = $tx;
        $painter->ty = $ty;
    }

    private function layout(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->dirty = false;
        $font = $this->font;
        $s = $this->size / $font->size;
        $lineHeight = $this->lineHeight > 0.0 ? $this->lineHeight : $font->lineHeight * $s;
        $wrap = $this->wrapWidth;
        $fallback = $font->glyph(63);

        $n = 0;
        $line = 0;
        $lineStart = 0;
        $pen = 0.0;
        $lineEnd = 0.0;
        $breakAt = -1;
        $breakPen = 0.0;
        $breakWidth = 0.0;
        $prev = 0;
        $lines = [];

        foreach (mb_str_split($this->text, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code === 10) {
                $lines[] = [$lineStart, $n, $lineEnd];
                ++$line;
                $lineStart = $n;
                $pen = $lineEnd = 0.0;
                $breakAt = -1;
                $prev = 0;
                continue;
            }
            $glyph = $code === 13 || $code === false ? null : ($font->glyph($code) ?? $fallback);
            if ($glyph === null) {
                continue;
            }
            $x = $pen + ($prev !== 0 ? $font->kerning($prev, $glyph->id) * $s : 0.0);
            $right = $x + $glyph->xAdvance * $s;
            if ($wrap > 0.0 && $glyph->id !== 32 && $right > $wrap + 1e-6 && $n > $lineStart) {
                if ($breakAt >= 0) {
                    $lines[] = [$lineStart, $breakAt, $breakWidth];
                    for ($j = $breakAt; $j < $n; ++$j) {
                        $this->gx[$j] -= $breakPen;
                        $this->gy[$j] += $lineHeight;
                    }
                    $lineStart = $breakAt;
                    $pen -= $breakPen;
                    $x -= $breakPen;
                    $lineEnd = $n > $breakAt ? $lineEnd - $breakPen : 0.0;
                } else {
                    $lines[] = [$lineStart, $n, $lineEnd];
                    $lineStart = $n;
                    $pen = $x = $lineEnd = 0.0;
                }
                ++$line;
                $breakAt = -1;
            }
            $this->gx[$n] = $x + $glyph->xOffset * $s;
            $this->gy[$n] = $line * $lineHeight + $glyph->yOffset * $s;
            $this->gw[$n] = $glyph->width * $s;
            $this->gh[$n] = $glyph->height * $s;
            $this->glyphTexture[$n] = $glyph->texture;
            ++$n;
            $pen = $x + $glyph->xAdvance * $s;
            if ($glyph->id === 32) {
                $breakAt = $n;
                $breakPen = $pen;
                $breakWidth = $lineEnd;
            } else {
                $lineEnd = $pen;
            }
            $prev = $glyph->id;
        }
        $lines[] = [$lineStart, $n, $lineEnd];

        $widest = 0.0;
        foreach ($lines as [, , $width]) {
            $widest = max($widest, $width);
        }
        $box = $wrap > 0.0 ? $wrap : $widest;
        $factor = $this->hAlign * 0.5;
        if ($factor > 0.0) {
            foreach ($lines as [$from, $to, $width]) {
                $shift = ($box - $width) * $factor;
                for ($j = $from; $j < $to; ++$j) {
                    $this->gx[$j] += $shift;
                }
            }
        }
        $this->count = $n;
        $this->widest = $widest;
        $this->boxWidth = $box;
        $this->heightOfText = $this->text === '' ? 0.0 : count($lines) * $lineHeight;
    }
}
