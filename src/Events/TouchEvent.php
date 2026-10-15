<?php

namespace Phame\Events;

/** A press, drag, release or hover of the pointer. Bubbles from the hit object up to the stage. */
final class TouchEvent extends Event
{
    public const BEGAN = 'began';
    public const MOVED = 'moved';
    public const ENDED = 'ended';
    public const HOVER = 'hover';

    public const BUTTON_LEFT = 1;
    public const BUTTON_MIDDLE = 2;
    public const BUTTON_RIGHT = 3;

    /**
     * @param string $phase one of the phase constants
     * @param float  $stageX pointer position in stage coordinates
     * @param int    $button 1 left, 2 middle, 3 right; 0 when no button is involved
     */
    public function __construct(
        public readonly string $phase,
        public readonly float $stageX,
        public readonly float $stageY,
        public readonly int $button = 0,
    ) {
        parent::__construct(self::TOUCH, true);
    }

    /** @return array{float, float} the pointer in the coordinate system of $space */
    public function getLocation(\Phame\Display\DisplayObject $space): array
    {
        return $space->globalToLocal($this->stageX, $this->stageY);
    }
}
