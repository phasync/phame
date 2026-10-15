<?php

namespace Phame\Wake;

use Phame\Kinetic\Body;
use Phame\Pool\Poolable;

/** @internal One coroutine waiting in DisplayObject::wait(): parked in its own phasync slot, kept for reuse. */
final class Waiter
{
    use Poolable;

    public ?Body $subject = null;
    public ?Wake $wake = null;
    public float $deadline = 0.0;
    /** The phasync slot this waiter parks in; allocated once, kept across reuse. */
    public int $slot = -1;
    /** Bumped on every reuse and wake, so that stale entries in the world's deadline heap are recognised. */
    public int $generation = 0;
    public ?Wake $result = null;
    public ?Body $contact = null;
    /** The certificate: when the next event is predicted, which condition and which other object. */
    public float $predictedAt = INF;
    public ?Wake $predictedWake = null;
    public ?Body $predictedContact = null;
    /** When the prediction stops being exact (bodies on rotating parents): predict again then. INF if it is exact. */
    public float $expiresAt = INF;
    /** Bumped whenever the waiter is (re)scheduled: older heap entries are stale. */
    public int $ticket = 0;
    public bool $active = false;

    private function __construct()
    {
    }

    public static function get(Body $subject, ?Wake $wake, float $deadline): self
    {
        $w = self::acquire();
        if ($w->slot < 0) {
            $w->slot = \phasync::getLoop()->getSlot();
        }
        $w->subject = $subject;
        $w->wake = $wake;
        $w->deadline = $deadline;
        $w->result = null;
        $w->contact = null;
        $w->predictedAt = INF;
        $w->predictedWake = null;
        $w->predictedContact = null;
        $w->expiresAt = INF;
        ++$w->generation;
        return $w;
    }

    public function free(): void
    {
        $this->subject = null;
        $this->wake = null;
        $this->result = null;
        $this->contact = null;
        $this->predictedWake = null;
        $this->predictedContact = null;
        ++$this->generation;
        $this->release();
    }
}
