<?php

namespace Phame\Tests;

use PHPUnit\Framework\TestCase;
use Phame\Display\{Quad, Sprite, Stage};

final class DisplayListTest extends TestCase
{
    public function testChildrenAreOrderedAndReparented(): void
    {
        $a = new Sprite();
        $b = new Sprite();
        $c = new Quad(1, 1);
        $a->addChild($c);
        $this->assertSame($a, $c->parent);
        $b->addChild($c);
        $this->assertSame($b, $c->parent);
        $this->assertSame(0, $a->numChildren);
        $this->assertSame(1, $b->numChildren);
    }

    public function testAddChildAtReordersAnExistingChild(): void
    {
        $s = new Sprite();
        [$x, $y, $z] = [new Quad(1, 1), new Quad(1, 1), new Quad(1, 1)];
        $s->addChild($x);
        $s->addChild($y);
        $s->addChild($z);
        $s->addChildAt($z, 0);
        $this->assertSame([$z, $x, $y], $s->getChildren());
        $s->addChildAt($z, 3 - 1);
        $this->assertSame([$x, $y, $z], $s->getChildren());
    }

    public function testCannotCreateCycles(): void
    {
        $a = new Sprite();
        $b = new Sprite();
        $a->addChild($b);
        $this->expectException(\LogicException::class);
        $b->addChild($a);
    }

    public function testCannotAddSelf(): void
    {
        $a = new Sprite();
        $this->expectException(\LogicException::class);
        $a->addChild($a);
    }

    public function testInvalidIndexThrows(): void
    {
        $this->expectException(\OutOfRangeException::class);
        (new Sprite())->addChildAt(new Quad(1, 1), 1);
    }

    public function testStageIsFoundThroughTheParentChain(): void
    {
        $stage = new Stage(100, 100);
        $a = new Sprite();
        $q = new Quad(1, 1);
        $a->addChild($q);
        $this->assertNull($q->stage);
        $stage->addChild($a);
        $this->assertSame($stage, $q->stage);
    }

    public function testTransformationOrderIsScaleRotateThenTranslateAroundThePivot(): void
    {
        $q = new Quad(10, 10);
        $q->pivotX = 5;
        $q->pivotY = 5;
        $q->x = 100;
        $q->y = 100;
        $q->scaleX = 2;
        $q->rotation = M_PI / 2;
        // the center stays at (100,100); the top left corner (0,0) is 5 right, 5 down from the pivot, scaled by 2 in x then rotated 90 degrees clockwise
        [$x, $y] = $q->localToGlobal(0, 0);
        $this->assertEqualsWithDelta(100 - (-5 * 0 + -5 * 1) * 1.0 * 0 + 5, $x, 1e-9);
        $this->assertEqualsWithDelta(100 - 10, $y, 1e-9);
        [$cx, $cy] = $q->localToGlobal(5, 5);
        $this->assertEqualsWithDelta(100.0, $cx, 1e-9);
        $this->assertEqualsWithDelta(100.0, $cy, 1e-9);
    }

    public function testGlobalToLocalInvertsLocalToGlobalThroughNestedParents(): void
    {
        $stage = new Stage(200, 200);
        $outer = new Sprite();
        $outer->x = 30;
        $outer->y = 10;
        $outer->scaleX = 2;
        $outer->rotation = 0.3;
        $inner = new Sprite();
        $inner->x = 7;
        $inner->scaleY = 0.5;
        $inner->skewX = 0.2;
        $q = new Quad(10, 10);
        $q->x = 3;
        $stage->addChild($outer);
        $outer->addChild($inner);
        $inner->addChild($q);
        [$gx, $gy] = $q->localToGlobal(4, 6);
        [$lx, $ly] = $q->globalToLocal($gx, $gy);
        $this->assertEqualsWithDelta(4.0, $lx, 1e-9);
        $this->assertEqualsWithDelta(6.0, $ly, 1e-9);
    }

    public function testBoundsInOtherSpaces(): void
    {
        $stage = new Stage(200, 200);
        $a = new Sprite();
        $a->x = 50;
        $a->y = 20;
        $q = new Quad(10, 20);
        $q->x = 5;
        $a->addChild($q);
        $stage->addChild($a);
        $inParent = $q->getBounds($a);
        $this->assertEquals([5.0, 0.0, 10.0, 20.0], [$inParent->x, $inParent->y, $inParent->width, $inParent->height]);
        $inStage = $q->getBounds($stage);
        $this->assertEquals([55.0, 20.0, 10.0, 20.0], [$inStage->x, $inStage->y, $inStage->width, $inStage->height]);
        // a sibling's space, which is neither parent nor child
        $b = new Sprite();
        $b->x = 100;
        $stage->addChild($b);
        $inB = $q->getBounds($b);
        $this->assertEquals(-45.0, $inB->x);
    }

    public function testContainerBoundsAreTheUnionOfTheChildren(): void
    {
        $s = new Sprite();
        $a = new Quad(10, 10);
        $b = new Quad(10, 10);
        $b->x = 30;
        $b->y = 40;
        $s->addChild($a);
        $s->addChild($b);
        $r = $s->getBounds($s);
        $this->assertEquals([0.0, 0.0, 40.0, 50.0], [$r->x, $r->y, $r->width, $r->height]);
    }

    public function testSettingWidthScalesTheObject(): void
    {
        $q = new Quad(10, 20);
        $q->width = 30;
        $this->assertSame(3.0, $q->scaleX);
        $this->assertSame(1.0, $q->scaleY);
        $q->height = 10;
        $this->assertSame(0.5, $q->scaleY);
    }

    public function testHitTestPicksTheTopmostTouchableThroughTransforms(): void
    {
        $stage = new Stage(100, 100);
        $group = new Sprite();
        $group->x = 10;
        $group->y = 10;
        $group->scaleX = $group->scaleY = 2;
        $below = new Quad(20, 20);
        $above = new Quad(5, 5);
        $above->x = 3;
        $above->y = 3;
        $group->addChild($below);
        $group->addChild($above);
        $stage->addChild($group);
        // above covers stage 16..26, below covers 10..50
        $this->assertSame($above, $stage->hitTest(20, 20));
        $this->assertSame($below, $stage->hitTest(40, 40));
        $this->assertSame($stage, $stage->hitTest(80, 80), 'nothing hit: the stage itself');
        $this->assertNull($stage->hitTest(-1, 5));

        $above->touchable = false;
        $this->assertSame($below, $stage->hitTest(20, 20));
        $above->touchable = true;
        $above->visible = false;
        $this->assertSame($below, $stage->hitTest(20, 20));
        $group->touchable = false;
        $this->assertSame($stage, $stage->hitTest(20, 20));
    }

    public function testAlphaIsClamped(): void
    {
        $q = new Quad(1, 1);
        $q->alpha = 3.0;
        $this->assertSame(1.0, $q->alpha);
        $q->alpha = -1.0;
        $this->assertSame(0.0, $q->alpha);
    }

    public function testRemoveChildAndDispose(): void
    {
        $s = new Sprite();
        $q = new Quad(1, 1);
        $s->addChild($q);
        $s->removeChild($q);
        $this->assertNull($q->parent);
        $this->assertSame(0, $s->numChildren);
        $s->addChild($q);
        $s->dispose();
        $this->assertSame(0, $s->numChildren);
    }

    public function testOverlapsComparesScreenBoxesAcrossParents(): void
    {
        $a = new Quad(10, 10);
        $group = new Sprite();
        $group->x = 100;
        $b = new Quad(10, 10);
        $b->x = -95;   // on screen at x 5..15
        $group->addChild($b);
        $stage = new Sprite();
        $stage->addChild($a);
        $stage->addChild($group);
        $this->assertTrue($a->overlaps($b));
        $b->x = -85;
        $this->assertFalse($a->overlaps($b));
        $b->x = -95;
        $b->y = 10;   // touching edges do not overlap
        $this->assertFalse($a->overlaps($b));
    }

    public function testRotatedQuadBoundsContainAllCorners(): void
    {
        $q = new Quad(10, 4);
        $q->rotation = M_PI / 2;
        $r = $q->getBounds(null);
        $this->assertEqualsWithDelta(-4.0, $r->x, 1e-9);
        $this->assertEqualsWithDelta(0.0, $r->y, 1e-9);
        $this->assertEqualsWithDelta(4.0, $r->width, 1e-9);
        $this->assertEqualsWithDelta(10.0, $r->height, 1e-9);
    }
}
