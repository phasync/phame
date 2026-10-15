<?php

namespace Phame\Events;

final class EnterFrameEvent extends Event
{
    public function __construct(public readonly float $passedTime)
    {
        parent::__construct(self::ENTER_FRAME);
    }
}
