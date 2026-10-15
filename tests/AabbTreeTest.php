<?php

namespace Phame\Tests;

use Phame\Collision\{AabbNode, AabbTree};
use PHPUnit\Framework\TestCase;

final class AabbTreeTest extends TestCase
{
    public function testQueriesMatchBruteForceThroughInsertsMovesAndRemovals(): void
    {
        mt_srand(42);
        $tree = new AabbTree(5.0);
        $items = $boxes = [];
        for ($i = 0; $i < 300; ++$i) {
            $items[$i] = new \stdClass();
        }
        for ($round = 0; $round < 40; ++$round) {
            foreach ($items as $i => $item) {
                if (mt_rand(0, 9) === 0) {
                    $tree->remove($item);
                    unset($boxes[$i]);
                    continue;
                }
                $x = mt_rand(0, 1000) / 1.0;
                $y = mt_rand(0, 1000) / 1.0;
                $w = mt_rand(2, 60);
                $boxes[$i] = [$x, $y, $x + $w, $y + $w];
                $tree->set($item, ...$boxes[$i]);
            }
            for ($q = 0; $q < 50; ++$q) {
                $x = mt_rand(0, 1000);
                $y = mt_rand(0, 1000);
                $hit = $tree->first($x, $y, $x + 10, $y + 10);
                $expected = [];
                foreach ($boxes as $i => [$x0, $y0, $x1, $y1]) {
                    if ($x < $x1 && $x0 < $x + 10 && $y < $y1 && $y0 < $y + 10) {
                        $expected[] = $items[$i];
                    }
                }
                if ($expected === []) {
                    $this->assertNull($hit);
                } else {
                    $this->assertContains($hit, $expected, 'the hit really overlaps (real box, not the enlarged one)');
                }
            }
        }
        $this->assertSame(count($boxes), $tree->count);
    }

    public function testExceptSkipsAnItemAndUnseenAreRemoved(): void
    {
        $tree = new AabbTree();
        $a = new \stdClass();
        $b = new \stdClass();
        $tree->set($a, 0, 0, 10, 10)->seen = 1;
        $tree->set($b, 5, 5, 15, 15)->seen = 1;
        $this->assertSame($b, $tree->first(0, 0, 20, 20, $a));
        $tree->set($a, 0, 0, 10, 10)->seen = 2;
        $tree->removeUnseen(2);
        $this->assertSame(1, $tree->count);
        $this->assertNull($tree->first(12, 12, 14, 14), 'b is gone');
    }

    public function testSmallMovesDoNotTouchTheTreeAndNodesAreReused(): void
    {
        $tree = new AabbTree(8.0);
        $items = [];
        for ($i = 0; $i < 100; ++$i) {
            $items[] = $o = new \stdClass();
            $tree->set($o, $i * 20, 0, $i * 20 + 10, 10);
        }
        $leaf = $tree->set($items[5], 102, 2, 112, 12);   // moved 2 points: inside its enlarged box
        $this->assertSame($leaf, $tree->set($items[5], 103, 3, 113, 13));
        $pooled = AabbNode::pooledCount();
        for ($round = 0; $round < 20; ++$round) {
            foreach ($items as $i => $o) {
                $tree->set($o, $i * 20 + $round * 30, 0, $i * 20 + $round * 30 + 10, 10);   // far moves: reinserted
            }
        }
        $this->assertSame($pooled, AabbNode::pooledCount(), 'reinsertions reuse the same nodes');
    }
}
