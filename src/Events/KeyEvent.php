<?php

namespace Phame\Events;

/** Key events are dispatched on the stage. $keyCode is the SDL keycode: the ASCII value for printable keys. */
final class KeyEvent extends Event
{
    public function __construct(string $type, public readonly int $keyCode, public readonly bool $repeat = false)
    {
        parent::__construct($type);
    }
}
