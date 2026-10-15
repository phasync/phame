<?php

namespace Phame\Display;

use Phame\Events\Event;
use Phame\Math\Rectangle;
use Phame\Rendering\Painter;

abstract class DisplayObjectContainer extends DisplayObject implements \Phame\Kinetic\Group
{
    /** @var list<DisplayObject> */
    private array $children = [];

    /** @internal How many waiting coroutines watch this group (see Wake): its members report their motion changes. */
    public int $dependents = 0;

    public function kineticMembers(): array
    {
        return $this->children;
    }

    public int $numChildren {
        get => count($this->children);
    }

    /** @return list<DisplayObject> a copy: safe to modify the container while iterating */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function addChild(DisplayObject $child): DisplayObject
    {
        return $this->addChildAt($child, count($this->children));
    }

    public function addChildAt(DisplayObject $child, int $index): DisplayObject
    {
        if ($index < 0 || $index > count($this->children)) {
            throw new \OutOfRangeException("Invalid child index $index");
        }
        for ($o = $this; $o !== null; $o = $o->parent) {
            if ($o === $child) {
                throw new \LogicException('An object cannot be added as a child of itself or of its descendants');
            }
        }
        if ($child->parent === $this) {
            $at = array_search($child, $this->children, true);
            array_splice($this->children, $at, 1);
            $index = min($index, count($this->children));
            array_splice($this->children, $index, 0, [$child]);
            return $child;
        }
        $child->removeFromParent();
        array_splice($this->children, $index, 0, [$child]);
        $child->parent = $this;
        if ($this->clock !== null) {
            self::attachClockTo($child, $this->clock);
        }
        if ($child->watchedBelow > 0 || $this->dependents > 0) {
            $child->joinedGroup($this);
        }
        if ($this->dependents > 0) {
            $child->motionChanged();   // a new member: waiters on this group check it
        }
        $child->dispatchEvent(new Event(Event::ADDED, true));
        if ($this->stage !== null) {
            self::broadcast($child, new Event(Event::ADDED_TO_STAGE));
        }
        return $child;
    }

    public function removeChild(DisplayObject $child, bool $dispose = false): void
    {
        $index = array_search($child, $this->children, true);
        if ($index !== false) {
            $this->removeChildAt($index, $dispose);
        }
    }

    public function removeChildAt(int $index, bool $dispose = false): DisplayObject
    {
        $child = $this->children[$index] ?? throw new \OutOfRangeException("Invalid child index $index");
        for ($o = $child; $o !== null; $o = $o->parent) {   // REMOVED bubbles: only create it if someone listens
            if ($o->hasEventListener(Event::REMOVED)) {
                $child->dispatchEvent(new Event(Event::REMOVED, true));
                break;
            }
        }
        if ($this->stage !== null) {
            $event = null;
            self::leaveStage($child, $event);
        }
        if ($child->watchedBelow > 0) {
            $child->leftGroup($this);
        }
        array_splice($this->children, $index, 1);
        $child->parent = null;
        if ($this->dependents > 0) {
            ($this->stage?->world ?? \Phame\World::current())->memberRemoved($this, $child);
        }
        if ($dispose) {
            $child->dispose();
        }
        return $child;
    }

    public function removeChildren(bool $dispose = false): void
    {
        for ($i = count($this->children) - 1; $i >= 0; --$i) {
            $this->removeChildAt($i, $dispose);
        }
    }

    public function getChildAt(int $index): DisplayObject
    {
        return $this->children[$index] ?? throw new \OutOfRangeException("Invalid child index $index");
    }

    public function getChildByName(string $name): ?DisplayObject
    {
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }
        return null;
    }

    public function contains(DisplayObject $object): bool
    {
        for ($o = $object; $o !== null; $o = $o->parent) {
            if ($o === $this) {
                return true;
            }
        }
        return false;
    }

    public function getBounds(?DisplayObject $space): Rectangle
    {
        if (!$this->children) {
            [$x, $y] = $this->getTransformationMatrix($space)->transform(0.0, 0.0);
            return new Rectangle($x, $y);
        }
        $bounds = null;
        foreach ($this->children as $child) {
            $b = $child->getBounds($space);
            $bounds = $bounds === null ? $b : $bounds->union($b);
        }
        return $bounds;
    }

    public function hitTest(float $x, float $y): ?DisplayObject
    {
        if (!$this->visible || !$this->touchable) {
            return null;
        }
        for ($i = count($this->children) - 1; $i >= 0; --$i) {
            $child = $this->children[$i];
            if (!$child->visible || !$child->touchable) {
                continue;
            }
            $inverse = $child->getLocalMatrix();
            $inverse->invert();
            [$lx, $ly] = $inverse->transform($x, $y);
            if (($hit = $child->hitTest($lx, $ly)) !== null) {
                return $hit;
            }
        }
        return null;
    }

    public function render(Painter $painter): void
    {
        foreach ($this->children as $child) {
            if ($child->visible && $child->alpha > 0.0) {
                $painter->drawChild($child);
            }
        }
    }

    /** Dispatches the event on $object and on all its descendants. */
    /** @internal Gives $object and everything below it the world's clock, keeping them where they are. */
    public static function attachClockTo(DisplayObject $object, ?\Phame\Clock $clock): void
    {
        $object->attachClock($clock);
        if ($object instanceof DisplayObjectContainer) {
            foreach ($object->children as $child) {
                self::attachClockTo($child, $clock);
            }
        } elseif ($object instanceof \Phame\Scene\Scene3D) {
            \Phame\Scene\Node3D::attachClockTo($object->root, $clock);
        }
    }

    /** Ends the coroutines of everything below $object and tells listeners it left the stage (the event is made on demand). */
    private static function leaveStage(DisplayObject $object, ?Event &$event): void
    {
        $object->cancelCoroutines();
        if ($object->hasEventListener(Event::REMOVED_FROM_STAGE)) {
            $event ??= new Event(Event::REMOVED_FROM_STAGE);
            $event->target = null;
            $object->dispatchEvent($event);
        }
        if ($object instanceof DisplayObjectContainer) {
            foreach ($object->children as $child) {
                self::leaveStage($child, $event);
            }
        } elseif ($object instanceof \Phame\Scene\Scene3D) {
            \Phame\Scene\Node3D::leaveParent($object->root, $event);
        }
    }

    public static function broadcast(DisplayObject $object, Event $event): void
    {
        if ($object->hasEventListener($event->type)) {
            $event->target = null;
            $object->dispatchEvent($event);
        }
        if ($object instanceof DisplayObjectContainer) {
            foreach ($object->children as $child) {
                self::broadcast($child, $event);
            }
        } elseif ($object instanceof \Phame\Scene\Scene3D) {
            \Phame\Scene\Node3D::broadcast($object->root, $event);
        }
    }

    public function dispose(): void
    {
        foreach ($this->children as $child) {
            $child->dispose();
        }
        $this->children = [];
        parent::dispose();
    }
}
