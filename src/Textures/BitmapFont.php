<?php

namespace Phame\Textures;

use Phame\Engine;

/**
 * A font rasterized into a texture atlas, read from the BMFont text format (.fnt + one page image). All
 * measurements are in pixels at the font's native size; BitmapText scales them. Make fonts from TrueType files
 * with bin/make-font, or with any BMFont tool that can write the text format.
 */
final class BitmapFont
{
    /** @var array<int, Glyph> by Unicode code point */
    private array $glyphs = [];
    /** @var array<int, float> kerning by (first << 21) | second */
    private array $kerning = [];
    private ?Texture $page = null;

    private static ?self $default = null;
    private static ?Engine $defaultEngine = null;

    public readonly string $face;
    /** The size the font was rasterized at. */
    public readonly float $size;
    /** Distance between two lines of text. */
    public readonly float $lineHeight;
    /** Distance from the top of a line to the baseline. */
    public readonly float $base;

    private function __construct(string $fntPath)
    {
        $text = @file_get_contents($fntPath) ?: throw new \RuntimeException("Cannot read $fntPath");
        $face = '';
        $size = $lineHeight = $base = 0.0;
        $pageFile = null;
        $rows = [];
        foreach (preg_split('/\R/', $text, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $tag = strtok($line, ' ');
            $attrs = [];
            if (preg_match_all('/(\w+)=(?:"([^"]*)"|(\S*))/', $line, $m, PREG_SET_ORDER)) {
                foreach ($m as $kv) {
                    $attrs[$kv[1]] = $kv[3] ?? $kv[2];
                }
            }
            switch ($tag) {
                case 'info':
                    $face = $attrs['face'] ?? '';
                    $size = abs((float) ($attrs['size'] ?? 0));
                    break;
                case 'common':
                    $lineHeight = (float) ($attrs['lineHeight'] ?? 0);
                    $base = (float) ($attrs['base'] ?? 0);
                    if ((int) ($attrs['pages'] ?? 1) !== 1) {
                        throw new \RuntimeException('Fonts with more than one page are not supported: ' . $fntPath);
                    }
                    break;
                case 'page':
                    $pageFile ??= $attrs['file'] ?? null;
                    break;
                case 'char':
                case 'kerning':
                    $rows[] = [$tag, $attrs];
                    break;
            }
        }
        if ($pageFile === null || $lineHeight <= 0.0) {
            throw new \RuntimeException("$fntPath is not a BMFont text file");
        }
        $this->face = $face;
        $this->lineHeight = $lineHeight;
        $this->base = $base;
        $this->page = Texture::fromPng(dirname($fntPath) . '/' . $pageFile);
        $this->size = $size > 0.0 ? $size : $lineHeight;
        foreach ($rows as [$tag, $a]) {
            if ($tag === 'char') {
                $id = (int) $a['id'];
                $w = (float) $a['width'];
                $h = (float) $a['height'];
                $this->glyphs[$id] = new Glyph(
                    $id,
                    $w > 0.0 && $h > 0.0 ? $this->page->region((float) $a['x'], (float) $a['y'], $w, $h) : null,
                    $w, $h, (float) $a['xoffset'], (float) $a['yoffset'], (float) $a['xadvance'],
                );
            } else {
                $this->kerning[((int) $a['first'] << 21) | (int) $a['second']] = (float) $a['amount'];
            }
        }
    }

    public static function load(string $fntPath): self
    {
        return new self($fntPath);
    }

    /** The font that ships with Phame (DejaVu Sans), loaded on first use and again for a new Engine. */
    public static function default(): self
    {
        $engine = Engine::current();
        if (self::$default === null || self::$defaultEngine !== $engine) {
            self::$default = new self(__DIR__ . '/../../assets/fonts/dejavu-sans-32.fnt');
            self::$defaultEngine = $engine;
        }
        return self::$default;
    }

    public function glyph(int $codePoint): ?Glyph
    {
        return $this->glyphs[$codePoint] ?? null;
    }

    /** The extra distance between two characters, usually negative, at the font's native size. */
    public function kerning(int $first, int $second): float
    {
        return $this->kerning[($first << 21) | $second] ?? 0.0;
    }

    public function dispose(): void
    {
        $this->page?->dispose();
        $this->page = null;
        $this->glyphs = $this->kerning = [];
        if (self::$default === $this) {
            self::$default = self::$defaultEngine = null;
        }
    }
}
