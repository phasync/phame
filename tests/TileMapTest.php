<?php

namespace Phame\Tests;

use Phame\Display\{Quad, TileMap};

final class TileMapTest extends GlTestCase
{
    private function level(): TileMap
    {
        // 10 x 10 point tiles; the body is a 10 x 10 quad
        $map = new TileMap(<<<MAP
            ######
            #    #
            #  o #
            #    #
            ######
            MAP, 10);
        $map->x = 100;   // offsets must be honoured
        $map->y = 50;
        return $map;
    }

    private function body(float $x, float $y): Quad
    {
        $q = new Quad(10, 10);
        $q->x = $x;
        $q->y = $y;
        return $q;
    }

    public function testFallsOntoTheFloorAndStops(): void
    {
        $map = $this->level();
        $b = $this->body(115, 65);
        $this->assertFalse($map->isOnGround($b));
        $this->assertSame(TileMap::DOWN, $map->move($b, 0, 100));
        $this->assertEqualsWithDelta(80.0, $b->y, 1e-9, 'resting on top of the floor row (y 90)');
        $this->assertTrue($map->isOnGround($b));
        $this->assertSame(0, $map->move($b, 0, -5), 'free to jump');
        $this->assertFalse($map->isOnGround($b));
    }

    public function testWallsStopHorizontalMovementButSlideAlongY(): void
    {
        $map = $this->level();
        $b = $this->body(115, 65);
        $this->assertSame(TileMap::RIGHT | TileMap::DOWN, $map->move($b, 500, 500), 'no tunnelling at high speed');
        $this->assertEqualsWithDelta(140.0, $b->x, 1e-9);
        $this->assertEqualsWithDelta(80.0, $b->y, 1e-9);
        $this->assertSame(TileMap::LEFT | TileMap::UP, $map->move($b, -500, -500));
        $this->assertEqualsWithDelta(110.0, $b->x, 1e-9);
        $this->assertEqualsWithDelta(60.0, $b->y, 1e-9);
    }

    public function testBodyLargerThanATileAndMisalignedRows(): void
    {
        $map = new TileMap("        \n   #    \n        ", 10);
        $b = new Quad(15, 25);   // spans rows 0..2 at y=3
        $b->x = 0;
        $b->y = 3;
        $this->assertSame(TileMap::RIGHT, $map->move($b, 30, 0));
        $this->assertEqualsWithDelta(15.0, $b->x, 1e-9, 'stopped by the single block in the middle row');
        $b->y = 30;   // below the block: passes
        $this->assertSame(0, $map->move($b, 30, 0));
    }

    public function testTouchingFindsPickupsAndSetTileRemovesThem(): void
    {
        $map = $this->level();
        $b = $this->body(125, 66);
        $this->assertSame([3, 2], $map->touching($b, 'o'));
        $this->assertTrue($map->take($b, 'o'));
        $this->assertFalse($map->take($b, 'o'), 'taken');
        $this->assertSame(' ', $map->getTile(3, 2));
        $map->setTile(3, 2, 'o');
        $this->assertSame('o', $map->tileAt(135, 75));
        $this->assertSame('#', $map->tileAt(101, 51));
        $this->assertSame(' ', $map->tileAt(0, 0), 'outside the map is empty');
    }

    public function testDrawsStyledTilesOnlyAndCullsOffscreen(): void
    {
        $map = new TileMap("#.#\n...", 8);
        $map->style('#', 0xFF0000);
        $map->x = 16;
        $map->y = 8;
        $this->engine->stage->addChild($map);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 18, 10);
        $this->assertPixel([0, 0, 0, 255], 26, 10, message: 'unstyled "." is not drawn');
        $this->assertPixel([255, 0, 0, 255], 34, 10);

        $wide = new TileMap(str_repeat('#', 1000), 8);
        $wide->style('#', 0x00FF00);
        $wide->y = 30;
        $wide->x = -4000;
        $this->engine->stage->addChild($wide);
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 2, 32);
        $this->assertLessThan(20, $this->engine->painter->quadsDrawn, 'only the visible tiles are drawn');
    }
}
