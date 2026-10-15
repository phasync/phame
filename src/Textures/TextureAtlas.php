<?php

namespace Phame\Textures;

/**
 * Named regions of one texture: a sprite sheet. Load a Sparrow/Starling XML atlas (TexturePacker, Shoebox and most
 * tools export it), or cut a uniform grid. `frames('run_')` gives the regions for a MovieClip.
 */
final class TextureAtlas
{
    /** @var array<string, Texture> */
    private array $regions = [];

    public function __construct(public readonly Texture $texture)
    {
    }

    /**
     * Reads a Sparrow XML atlas; the image is loaded from its imagePath, next to the XML file. Trimmed frames
     * (frameX/frameY) are not supported: export without trimming, or with "extrude" only.
     */
    public static function fromSparrow(string $xmlPath, float $scale = 1.0, bool $smooth = true): self
    {
        $xml = @simplexml_load_file($xmlPath) ?: throw new \RuntimeException("Cannot read the atlas $xmlPath");
        $atlas = new self(Texture::fromPng(dirname($xmlPath) . '/' . $xml['imagePath'], $scale, $smooth));
        foreach ($xml->SubTexture as $sub) {
            if (isset($sub['frameX']) && ((float) $sub['frameX'] != 0.0 || (float) $sub['frameY'] != 0.0)) {
                throw new \RuntimeException("$xmlPath: trimmed frame '{$sub['name']}' is not supported; export without trimming");
            }
            $atlas->add((string) $sub['name'], (float) $sub['x'] / $scale, (float) $sub['y'] / $scale, (float) $sub['width'] / $scale, (float) $sub['height'] / $scale);
        }
        return $atlas;
    }

    /**
     * Cuts the texture into equal cells, left to right and top to bottom, named "0", "1", ... With $rows names, the
     * cells of each row are named after it instead, which suits sheets with one animation per row:
     * `grid($sheet, 32, 32, ['idle', 'walk', 'jump'])` makes "walk_0", "walk_1", ..., so `frames('walk_')` works.
     *
     * @param list<string> $rows
     */
    public static function grid(Texture $texture, float $cellWidth, float $cellHeight, array $rows = []): self
    {
        $atlas = new self($texture);
        $columns = (int) floor($texture->width / $cellWidth + 1e-6);
        $rowCount = (int) floor($texture->height / $cellHeight + 1e-6);
        for ($r = 0, $i = 0; $r < $rowCount; ++$r) {
            for ($c = 0; $c < $columns; ++$c, ++$i) {
                $name = isset($rows[$r]) ? "{$rows[$r]}_$c" : (string) $i;
                $atlas->add($name, $c * $cellWidth, $r * $cellHeight, $cellWidth, $cellHeight);
            }
        }
        return $atlas;
    }

    /** Adds a region, in points relative to the texture's top left corner. */
    public function add(string $name, float $x, float $y, float $width, float $height): Texture
    {
        return $this->regions[$name] = $this->texture->region($x, $y, $width, $height);
    }

    public function get(string $name): Texture
    {
        return $this->regions[$name] ?? throw new \OutOfBoundsException("No region '$name' in the atlas");
    }

    /**
     * The regions whose names start with $prefix, in natural order (run_2 before run_10).
     *
     * @return list<Texture>
     */
    public function frames(string $prefix = ''): array
    {
        $names = [];
        foreach ($this->regions as $name => $_) {
            if (str_starts_with((string) $name, $prefix)) {
                $names[] = (string) $name;
            }
        }
        if ($names === []) {
            throw new \OutOfBoundsException("No regions starting with '$prefix' in the atlas");
        }
        natsort($names);
        $frames = [];
        foreach ($names as $name) {
            $frames[] = $this->regions[$name];
        }
        return $frames;
    }

    public function dispose(): void
    {
        $this->texture->dispose();
    }
}
