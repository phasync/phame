<?php

namespace Phame\Events;

class Event
{
    public const ADDED = 'added';
    public const REMOVED = 'removed';
    public const ADDED_TO_STAGE = 'addedToStage';
    public const REMOVED_FROM_STAGE = 'removedFromStage';
    public const ENTER_FRAME = 'enterFrame';
    public const RESIZE = 'resize';
    public const TOUCH = 'touch';
    public const KEY_DOWN = 'keyDown';
    public const KEY_UP = 'keyUp';
    public const COMPLETE = 'complete';

    public ?EventDispatcher $target = null;
    public ?EventDispatcher $currentTarget = null;
    public bool $stopped = false;

    public function __construct(public readonly string $type, public readonly bool $bubbles = false)
    {
    }

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }
}
