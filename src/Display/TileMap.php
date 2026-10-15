<?php

namespace Phame\Display;

use Phame\Math\Rectangle;
use Phame\Rendering\Painter;
use Phame\Textures\Texture;

/**
 * A grid of tiles made from a text map, one character per tile, drawn in one batch. It also moves bodies with
 * tile collision, which is most of what a platformer or a top-down maze needs:
 *
 * ```php
 * $level = new TileMap(<<<MAP
 *     ##########
 *     #   o    #
 *     #  ###   #
 *     ##########
 *     MAP, 32, solid: '#');
 * $level->style('#', 0x606070)->style('o', 0xFFD040);
 * $hit = $level->move($player, $vx * $dt, $vy * $dt);   // stops at walls; returns the sides it hit
 * if ($hit & TileMap::DOWN) { $vy = 0; }                  // landed
 * ```
 *
 * Bodies and the map must share a parent, and the map must not be rotated or scaled. Spaces are empty; outside the
 * map is empty too, so add a border to keep bodies in.
 */
final class TileMap extends DisplayObject
{
    public const LEFT = 1;
    public const RIGHT = 2;
    public const UP = 4;
    public const DOWN = 8;

    private const EPSILON = 1e-6;

    /** @var list<string> */
    private array $rows;
    public readonly int $columns;
    public readonly int $rowCount;
    /** @var array<string, int> */
    private array $colors = [];
    /** @var array<string, ?Texture> */
    private array $textures = [];

    /** @param string $solid the characters that block movement */
    public function __construct(string $map, public readonly float $tileSize, public string $solid = '#')
    {
        $rows = preg_split('/\R/', rtrim($map, "\r\n"));
        $this->columns = max(array_map('strlen', $rows));
        $this->rowCount = count($rows);
        $this->rows = array_map(fn (string $r) => str_pad($r, $this->columns), $rows);
    }

    /** How tiles of this character look. Characters without a style are not drawn. */
    public function style(string $char, int $color = 0xFFFFFF, ?Texture $texture = null): self
    {
        $this->colors[$char] = $color;
        $this->textures[$char] = $texture;
        return $this;
    }

    /** The character at a column and row, or ' ' outside the map. */
    public function getTile(int $column, int $row): string
    {
        return $row >= 0 && $row < $this->rowCount && $column >= 0 && $column < $this->columns ? $this->rows[$row][$column] : ' ';
    }

    public function setTile(int $column, int $row, string $char): void
    {
        if ($row < 0 || $row >= $this->rowCount || $column < 0 || $column >= $this->columns) {
            throw new \OutOfRangeException("No tile at $column, $row");
        }
        $this->rows[$row][$column] = $char;
    }

    /** The character under a point in the map's parent's coordinates (where bodies live). */
    public function tileAt(float $x, float $y): string
    {
        return $this->getTile((int) floor(($x - $this->x) / $this->tileSize), (int) floor(($y - $this->y) / $this->tileSize));
    }

    /**
     * The first column and row of a tile with this character whose box overlaps the body, as [column, row], or
     * null.
     *
     * @return ?array{int, int}
     */
    public function touching(DisplayObject $body, string $char): ?array
    {
        $s = $this->tileSize;
        $bx = $body->x - $this->x;
        $by = $body->y - $this->y;
        $c1 = (int) floor(($bx + $body->width - self::EPSILON) / $s);
        $r1 = (int) floor(($by + $body->height - self::EPSILON) / $s);
        for ($r = (int) floor($by / $s); $r <= $r1; ++$r) {
            for ($c = (int) floor($bx / $s); $c <= $c1; ++$c) {
                if ($this->getTile($c, $r) === $char) {
                    return [$c, $r];
                }
            }
        }
        return null;
    }

    /** Removes the first tile with this character that the body touches, and says whether there was one. For pickups: `if ($map->take($player, 'o')) { ++$coins; }` */
    public function take(DisplayObject $body, string $char, string $replacement = ' '): bool
    {
        $tile = $this->touching($body, $char);
        if ($tile === null) {
            return false;
        }
        $this->rows[$tile[1]][$tile[0]] = $replacement;
        return true;
    }

    /**
     * Moves the body by (dx, dy), first along x and then along y, stopping it against solid tiles. Returns the sides
     * it was stopped on: LEFT, RIGHT, UP and DOWN combined. The body's box is its x, y, width and height. Fast
     * bodies do not tunnel: every tile on the way is checked.
     */
    public function move(DisplayObject $body, float $dx, float $dy): int
    {
        $s = $this->tileSize;
        $w = $body->width;
        $h = $body->height;
        $bx = $body->x - $this->x;
        $by = $body->y - $this->y;
        $hit = 0;
        if ($dx != 0.0) {
            $r0 = (int) floor($by / $s);
            $r1 = (int) floor(($by + $h - self::EPSILON) / $s);
            if ($dx > 0.0) {
                $from = (int) floor(($bx + $w - self::EPSILON) / $s) + 1;
                $to = (int) floor(($bx + $w + $dx - self::EPSILON) / $s);
                for ($c = $from; $c <= $to; ++$c) {
                    if ($this->solidInColumn($c, $r0, $r1)) {
                        $dx = $c * $s - $w - $bx;
                        $hit |= self::RIGHT;
                        break;
                    }
                }
            } else {
                $from = (int) floor($bx / $s) - 1;
                $to = (int) floor(($bx + $dx) / $s);
                for ($c = $from; $c >= $to; --$c) {
                    if ($this->solidInColumn($c, $r0, $r1)) {
                        $dx = ($c + 1) * $s - $bx;
                        $hit |= self::LEFT;
                        break;
                    }
                }
            }
            $bx += $dx;
            $body->x = $bx + $this->x;
        }
        if ($dy != 0.0) {
            $c0 = (int) floor($bx / $s);
            $c1 = (int) floor(($bx + $w - self::EPSILON) / $s);
            if ($dy > 0.0) {
                $from = (int) floor(($by + $h - self::EPSILON) / $s) + 1;
                $to = (int) floor(($by + $h + $dy - self::EPSILON) / $s);
                for ($r = $from; $r <= $to; ++$r) {
                    if ($this->solidInRow($r, $c0, $c1)) {
                        $dy = $r * $s - $h - $by;
                        $hit |= self::DOWN;
                        break;
                    }
                }
            } else {
                $from = (int) floor($by / $s) - 1;
                $to = (int) floor(($by + $dy) / $s);
                for ($r = $from; $r >= $to; --$r) {
                    if ($this->solidInRow($r, $c0, $c1)) {
                        $dy = ($r + 1) * $s - $by;
                        $hit |= self::UP;
                        break;
                    }
                }
            }
            $body->y = $by + $dy + $this->y;
        }
        return $hit;
    }

    /** Whether solid ground is right below the body (for jumping, coyote time and the like). */
    public function isOnGround(DisplayObject $body): bool
    {
        $s = $this->tileSize;
        $bx = $body->x - $this->x;
        $below = $body->y - $this->y + $body->height;
        $row = (int) floor(($below + self::EPSILON) / $s);
        return abs($row * $s - $below) < 1e-3 && $this->solidInRow($row, (int) floor($bx / $s), (int) floor(($bx + $body->width - self::EPSILON) / $s));
    }

    private function solidInColumn(int $c, int $r0, int $r1): bool
    {
        for ($r = $r0; $r <= $r1; ++$r) {
            if (str_contains($this->solid, $this->getTile($c, $r))) {
                return true;
            }
        }
        return false;
    }

    private function solidInRow(int $r, int $c0, int $c1): bool
    {
        for ($c = $c0; $c <= $c1; ++$c) {
            if (str_contains($this->solid, $this->getTile($c, $r))) {
                return true;
            }
        }
        return false;
    }

    public function getBounds(?DisplayObject $space): Rectangle
    {
        $m = $this->getTransformationMatrix($space);
        $w = $this->columns * $this->tileSize;
        $h = $this->rowCount * $this->tileSize;
        $ax = $m->a * $w;
        $ay = $m->b * $w;
        $cx = $m->c * $h;
        $cy = $m->d * $h;
        $minX = $m->tx + min(0.0, $ax) + min(0.0, $cx);
        $minY = $m->ty + min(0.0, $ay) + min(0.0, $cy);
        return new Rectangle($minX, $minY, $m->tx + max(0.0, $ax) + max(0.0, $cx) - $minX, $m->ty + max(0.0, $ay) + max(0.0, $cy) - $minY);
    }

    public function hitTest(float $x, float $y): ?DisplayObject
    {
        if (!$this->visible || !$this->touchable) {
            return null;
        }
        return $x >= 0.0 && $y >= 0.0 && $x < $this->columns * $this->tileSize && $y < $this->rowCount * $this->tileSize ? $this : null;
    }

    public function render(Painter $painter): void
    {
        $s = $this->tileSize;
        $pa = $painter->a;
        $pb = $painter->b;
        $pc = $painter->c;
        $pd = $painter->d;
        $ptx = $painter->tx;
        $pty = $painter->ty;
        $c0 = $r0 = 0;
        $c1 = $this->columns - 1;
        $r1 = $this->rowCount - 1;
        if ($pb == 0.0 && $pc == 0.0 && $pa > 0.0 && $pd > 0.0) {
            // only the tiles inside the render target (scrolling levels are mostly off screen)
            $c0 = max($c0, (int) floor(-$ptx / ($pa * $s)));
            $c1 = min($c1, (int) floor(($painter->width - $ptx) / ($pa * $s)));
            $r0 = max($r0, (int) floor(-$pty / ($pd * $s)));
            $r1 = min($r1, (int) floor(($painter->height - $pty) / ($pd * $s)));
        }
        for ($r = $r0; $r <= $r1; ++$r) {
            $row = $this->rows[$r];
            for ($c = $c0; $c <= $c1; ++$c) {
                $char = $row[$c];
                if (!isset($this->colors[$char])) {
                    continue;
                }
                $painter->tx = $pa * $c * $s + $pc * $r * $s + $ptx;
                $painter->ty = $pb * $c * $s + $pd * $r * $s + $pty;
                $painter->drawQuad($this->textures[$char], $s, $s, $this->colors[$char]);
            }
        }
        $painter->tx = $ptx;
        $painter->ty = $pty;
    }
}
