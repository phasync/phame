<?php

namespace Phame\Audio;

use Phame\Events\Event;
use Phame\Events\EventDispatcher;

/**
 * One playing instance of a Sound. It dispatches COMPLETE when it ends by itself:
 * `Async::awaitEvent($sound->play(), Event::COMPLETE);`
 */
final class Voice extends EventDispatcher
{
    /** Read position in bytes. */
    public int $position = 0;
    public bool $playing = true;

    public function __construct(public readonly Sound $sound, public float $volume = 1.0, public bool $loop = false)
    {
    }

    public function stop(): void
    {
        $this->playing = false;
    }

    /** @internal called by the mixer */
    public function finish(): void
    {
        $this->playing = false;
        $this->dispatchEvent(new Event(Event::COMPLETE));
    }
}
