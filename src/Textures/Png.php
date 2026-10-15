<?php

namespace Phame\Textures;

/** A small PNG codec: enough to load game assets and to save screenshots. Not interlaced images. */
final class Png
{
    /**
     * Decodes a PNG file to straight-alpha RGBA, 8 bits per channel. Handles all color types at bit depths 1 to 16.
     *
     * @return array{width: int, height: int, rgba: string}
     */
    public static function decode(string $png): array
    {
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('Not a PNG file');
        }
        $pos = 8;
        $idat = '';
        $palette = $trns = '';
        $header = null;
        while ($pos + 8 <= strlen($png)) {
            ['len' => $len] = unpack('Nlen', $png, $pos);
            $type = substr($png, $pos + 4, 4);
            $data = substr($png, $pos + 8, $len);
            $pos += 12 + $len;
            if ($type === 'IHDR') {
                $header = unpack('Nw/Nh/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $data);
            } elseif ($type === 'PLTE') {
                $palette = $data;
            } elseif ($type === 'tRNS') {
                $trns = $data;
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if ($header === null) {
            throw new \InvalidArgumentException('PNG has no header');
        }
        ['w' => $w, 'h' => $h, 'depth' => $depth, 'type' => $type, 'interlace' => $interlace] = $header;
        if ($interlace !== 0) {
            throw new \InvalidArgumentException('Interlaced PNG files are not supported: save the image without interlacing');
        }
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$type] ?? throw new \InvalidArgumentException("Invalid PNG color type $type");
        $raw = @gzuncompress($idat);
        if ($raw === false) {
            throw new \InvalidArgumentException('PNG image data is corrupt');
        }
        $bitsPerPixel = $channels * $depth;
        $bpp = max(1, $bitsPerPixel >> 3);
        $stride = ($w * $bitsPerPixel + 7) >> 3;
        if (strlen($raw) < ($stride + 1) * $h) {
            throw new \InvalidArgumentException('PNG image data is truncated');
        }

        $out = [];
        $prev = array_fill(1, $stride, 0);
        for ($y = 0; $y < $h; ++$y) {
            $offset = $y * ($stride + 1);
            $filter = ord($raw[$offset]);
            if ($filter === 0 && $type === 6 && $depth === 8) {
                // unfiltered RGBA rows are the pixels as they are; $prev is only built if a later row needs it
                $out[] = substr($raw, $offset + 1, $stride);
                $prev = null;
                continue;
            }
            if ($prev === null) {
                $prev = unpack("C$stride", $raw, $offset - $stride);
            }
            $row = unpack("C$stride", $raw, $offset + 1);
            switch ($filter) {
                case 0:
                    break;
                case 1:
                    for ($i = $bpp + 1; $i <= $stride; ++$i) {
                        $row[$i] = ($row[$i] + $row[$i - $bpp]) & 255;
                    }
                    break;
                case 2:
                    for ($i = 1; $i <= $stride; ++$i) {
                        $row[$i] = ($row[$i] + $prev[$i]) & 255;
                    }
                    break;
                case 3:
                    for ($i = 1; $i <= $stride; ++$i) {
                        $left = $i > $bpp ? $row[$i - $bpp] : 0;
                        $row[$i] = ($row[$i] + (($left + $prev[$i]) >> 1)) & 255;
                    }
                    break;
                case 4:
                    for ($i = 1; $i <= $stride; ++$i) {
                        $a = $i > $bpp ? $row[$i - $bpp] : 0;
                        $b = $prev[$i];
                        $c = $i > $bpp ? $prev[$i - $bpp] : 0;
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $row[$i] = ($row[$i] + ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c))) & 255;
                    }
                    break;
                default:
                    throw new \InvalidArgumentException("Invalid PNG filter $filter");
            }
            $prev = $row;
            $out[] = self::rowToRgba($row, $w, $depth, $type, $palette, $trns);
        }
        return ['width' => $w, 'height' => $h, 'rgba' => implode('', $out)];
    }

    /** @param array<int, int> $row unfiltered scanline bytes, 1-indexed */
    private static function rowToRgba(array $row, int $w, int $depth, int $type, string $palette, string $trns): string
    {
        if ($type === 6 && $depth === 8) {
            return pack('C*', ...$row);
        }
        $px = '';
        if ($depth < 8) {
            $mask = (1 << $depth) - 1;
            $samples = [];
            foreach ($row as $byte) {
                for ($shift = 8 - $depth; $shift >= 0; $shift -= $depth) {
                    $samples[] = ($byte >> $shift) & $mask;
                }
            }
            for ($x = 0; $x < $w; ++$x) {
                $s = $samples[$x];
                if ($type === 3) {
                    $px .= substr($palette, $s * 3, 3) . (isset($trns[$s]) ? $trns[$s] : "\xff");
                } else {
                    $v = chr((int) ($s * 255 / $mask));
                    $px .= $v . $v . $v . (($trns !== '' && $s === unpack('n', $trns)[1]) ? "\0" : "\xff");
                }
            }
            return $px;
        }
        $step = $depth >> 3;   // bytes per sample: 1 or 2; the high byte is the 8-bit value
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$type];
        for ($x = 0; $x < $w; ++$x) {
            $i = 1 + $x * $channels * $step;
            switch ($type) {
                case 0:
                    $px .= chr($row[$i]) . chr($row[$i]) . chr($row[$i]) . "\xff";
                    break;
                case 2:
                    $px .= chr($row[$i]) . chr($row[$i + $step]) . chr($row[$i + 2 * $step]) . "\xff";
                    break;
                case 3:
                    $s = $row[$i];
                    $px .= substr($palette, $s * 3, 3) . (isset($trns[$s]) ? $trns[$s] : "\xff");
                    break;
                case 4:
                    $px .= chr($row[$i]) . chr($row[$i]) . chr($row[$i]) . chr($row[$i + $step]);
                    break;
                case 6:
                    $px .= chr($row[$i]) . chr($row[$i + $step]) . chr($row[$i + 2 * $step]) . chr($row[$i + 3 * $step]);
                    break;
            }
        }
        return $px;
    }

    public static function encode(int $width, int $height, string $rgba): string
    {
        if (strlen($rgba) !== $width * $height * 4) {
            throw new \InvalidArgumentException('RGBA data does not match the size');
        }
        $raw = '';
        $stride = $width * 4;
        for ($y = 0; $y < $height; ++$y) {
            $raw .= "\0" . substr($rgba, $y * $stride, $stride);
        }
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 6))
            . $chunk('IEND', '');
    }
}
