<?php

namespace Phame\Display;

use Phame\Rendering\Painter;

/** The root of the display list. Its size is the size of the game's own coordinate system, not of the window. */
final class Stage extends DisplayObjectContainer
{
    /** The world this stage belongs to. */
    public ?\Phame\World $world = null;
    /** Background color, 0xRRGGBB. */
    public int $color = 0x000000;

    public function __construct(public readonly int $stageWidth, public readonly int $stageHeight)
    {
    }

    public function hitTest(float $x, float $y): ?DisplayObject
    {
        if ($x < 0 || $y < 0 || $x >= $this->stageWidth || $y >= $this->stageHeight) {
            return null;
        }
        return parent::hitTest($x, $y) ?? $this;
    }

    public function render(Painter $painter): void
    {
        parent::render($painter);
    }
}
