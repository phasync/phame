<?php

namespace Phame\Tests;

use PHPUnit\Framework\TestCase;
use Phame\Textures\Png;

final class PngTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $w = 7;
        $h = 5;
        $rgba = '';
        for ($i = 0; $i < $w * $h; ++$i) {
            $rgba .= chr($i * 7 & 255) . chr($i * 13 & 255) . chr($i * 31 & 255) . chr(255 - $i);
        }
        $d = Png::decode(Png::encode($w, $h, $rgba));
        $this->assertSame([$w, $h], [$d['width'], $d['height']]);
        $this->assertSame($rgba, $d['rgba']);
    }

    public function testEncodeRejectsWrongDataLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Png::encode(2, 2, 'short');
    }

    public function testDecodeRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Png::decode('not a png');
    }

    public function testDecodesGrayscaleAndPaletteAndSubFilter(): void
    {
        // 2x1 grayscale 8-bit, filter 0: 0x10, 0xf0
        $gray = self::build(2, 1, 8, 0, "\x00\x10\xf0");
        $this->assertSame("\x10\x10\x10\xff\xf0\xf0\xf0\xff", Png::decode($gray)['rgba']);

        // 2x1 palette 8-bit, red + semi-transparent blue
        $plte = "\xff\x00\x00\x00\x00\xff";
        $trns = "\xff\x80";
        $pal = self::build(2, 1, 8, 3, "\x00\x00\x01", ['PLTE' => $plte, 'tRNS' => $trns]);
        $this->assertSame("\xff\x00\x00\xff\x00\x00\xff\x80", Png::decode($pal)['rgba']);

        // 3x1 gray with the Sub filter (1): 10, +5, +5 => 10, 15, 20
        $sub = self::build(3, 1, 8, 0, "\x01\x0a\x05\x05");
        $this->assertSame("\x0a\x0a\x0a\xff\x0f\x0f\x0f\xff\x14\x14\x14\xff", Png::decode($sub)['rgba']);

        // 4x1 gray 1-bit: 1010 => 255,0,255,0
        $bit = self::build(4, 1, 1, 0, "\x00\xa0");
        $this->assertSame("\xff\xff\xff\xff\x00\x00\x00\xff\xff\xff\xff\xff\x00\x00\x00\xff", Png::decode($bit)['rgba']);
    }

    public function testFilteredRowsAfterUnfilteredRgbaRowsSeeTheRowAbove(): void
    {
        // 1x3 RGBA: row 0 unfiltered, row 1 Up, row 2 unfiltered, row 3 Paeth (against an unfiltered row above)
        $raw = "\x00\x0a\x14\x1e\x28" . "\x02\x01\x01\x01\x01" . "\x00\x64\x64\x64\x64" . "\x04\x01\x01\x01\x01";
        $this->assertSame(
            "\x0a\x14\x1e\x28" . "\x0b\x15\x1f\x29" . "\x64\x64\x64\x64" . "\x65\x65\x65\x65",
            Png::decode(self::build(1, 4, 8, 6, $raw))['rgba'],
        );
    }

    /** @param array<string,string> $extra chunks placed before IDAT */
    private static function build(int $w, int $h, int $depth, int $colorType, string $raw, array $extra = []): string
    {
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $out = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, $depth, $colorType, 0, 0, 0));
        foreach ($extra as $type => $data) {
            $out .= $chunk($type, $data);
        }
        return $out . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
    }
}
