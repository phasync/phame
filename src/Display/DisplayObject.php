<?php

namespace Phame\Display;

use Phame\Events\EventDispatcher;
use Phame\Math\Matrix;
use Phame\Math\Rectangle;
use Phame\Rendering\BlendMode;
use Phame\Rendering\Painter;

/**
 * Base of everything that is drawn. Coordinates follow the Flash/Starling convention: x grows to the right,
 * y grows down, rotation is in radians, clockwise. Rotation and scale happen around the pivot point.
 *
 * Changing x, y, pivot, scale, skew or rotation marks the cached transformation matrix dirty.
 */
abstract class DisplayObject extends EventDispatcher implements \Phame\Kinetic\Body
{
    use \Phame\Kinetic\Waits;

    public ?DisplayObjectContainer $parent = null;
    public string $name = '';
    public bool $visible = true {
        set { $this->visible = $value; if ($this->waiting !== null || ($this->parent?->dependents ?? 0) > 0) { $this->motionChanged(); } }
    }
    public bool $touchable = true;
    public int $blendMode = BlendMode::AUTO;
    /** If set, the object (with its children) is drawn into a texture and that is drawn through the filter. */
    public ?\Phame\Rendering\Filter $filter = null;

    public float $alpha = 1.0 {
        set => $this->alpha = max(0.0, min(1.0, $value));
    }

    /*
     * Motion description: position and rotation at game time $mt, and their rates per second. x, y and rotation are
     * read from it at the current game time, so moving objects need no updates between changes. Every change rebases
     * the description at the current time and tells the world once (it reconciles after the coroutines have run).
     */
    private float $mx = 0.0;
    private float $my = 0.0;
    private float $mr = 0.0;
    private float $mt = 0.0;
    private float $mvx = 0.0;
    private float $mvy = 0.0;
    private float $mvr = 0.0;
    /** @internal The clock of the world the object is in (set when it joins a stage), for reading the description. */
    public ?\Phame\Clock $clock = null;
    private float $matrixTime = -1.0;

    public float $x {
        get => $this->mvx == 0.0 ? $this->mx : $this->mx + $this->mvx * (($this->clock?->time ?? 0.0) - $this->mt);
        set { $this->rebase(); $this->mx = $value; $this->motionEdited(); }
    }
    public float $y {
        get => $this->mvy == 0.0 ? $this->my : $this->my + $this->mvy * (($this->clock?->time ?? 0.0) - $this->mt);
        set { $this->rebase(); $this->my = $value; $this->motionEdited(); }
    }
    /** Radians, clockwise. */
    public float $rotation {
        get => $this->mvr == 0.0 ? $this->mr : $this->mr + $this->mvr * (($this->clock?->time ?? 0.0) - $this->mt);
        set { $this->rebase(); $this->mr = $value; $this->motionEdited(); }
    }
    /** Points per second of game time, in the parent's coordinates: the object moves without anyone updating it. */
    public float $velocityX {
        get => $this->mvx;
        set { $this->rebase(); $this->mvx = $value; $this->motionEdited(); }
    }
    public float $velocityY {
        get => $this->mvy;
        set { $this->rebase(); $this->mvy = $value; $this->motionEdited(); }
    }
    /** Radians per second of game time. */
    public float $angularVelocity {
        get => $this->mvr;
        set { $this->rebase(); $this->mvr = $value; $this->motionEdited(); }
    }

    /** Teleports: puts the object at ($x, $y) and stops it there. */
    public function moveTo(float $x, float $y): void
    {
        $this->rebase();
        $this->mx = $x;
        $this->my = $y;
        $this->mvx = $this->mvy = 0.0;
        $this->motionEdited();
    }

    /** Linear motion from now on, in points per second (the parent's coordinates). */
    public function setVelocity(float $vx, float $vy): void
    {
        $this->rebase();
        $this->mvx = $vx;
        $this->mvy = $vy;
        $this->motionEdited();
    }

    /** Rotation speed from now on, in radians per second. */
    public function spin(float $radiansPerSecond): void
    {
        $this->rebase();
        $this->mvr = $radiansPerSecond;
        $this->motionEdited();
    }

    /** Whether the description changes the object over time (velocity or spin). */
    public bool $isMoving {
        get => $this->mvx != 0.0 || $this->mvy != 0.0 || $this->mvr != 0.0;
    }

    /** Moves the description's start to the current game time, keeping the object where it is. */
    private function rebase(): void
    {
        $t = $this->clock?->time ?? 0.0;
        if ($t != $this->mt) {
            $dt = $t - $this->mt;
            $this->mx += $this->mvx * $dt;
            $this->my += $this->mvy * $dt;
            $this->mr += $this->mvr * $dt;
            $this->mt = $t;
        }
    }

    private function motionEdited(): void
    {
        $this->matrixDirty = true;
        $this->motionChanged();
    }

    /** @internal Joins (or leaves) a world's clock without moving the object. */
    public function attachClock(?\Phame\Clock $clock): void
    {
        if ($clock === $this->clock) {
            return;
        }
        $this->rebase();
        $this->clock = $clock;
        $this->mt = $clock?->time ?? 0.0;
    }

    public float $pivotX = 0.0 {
        set { $this->pivotX = $value; $this->matrixDirty = true; }
    }
    public float $pivotY = 0.0 {
        set { $this->pivotY = $value; $this->matrixDirty = true; }
    }
    public float $scaleX = 1.0 {
        set { $this->scaleX = $value; $this->matrixDirty = true; }
    }
    public float $scaleY = 1.0 {
        set { $this->scaleY = $value; $this->matrixDirty = true; }
    }
    public float $skewX = 0.0 {
        set { $this->skewX = $value; $this->matrixDirty = true; }
    }
    public float $skewY = 0.0 {
        set { $this->skewY = $value; $this->matrixDirty = true; }
    }

    /** Width of the bounds in the parent's coordinates. Setting it changes scaleX. */
    public float $width {
        get => $this->getBounds($this->parent)->width;
        set {
            $this->scaleX = 1.0;
            $current = $this->getBounds($this->parent)->width;
            if ($current != 0.0) {
                $this->scaleX = $value / $current;
            }
        }
    }

    /** Height of the bounds in the parent's coordinates. Setting it changes scaleY. */
    public float $height {
        get => $this->getBounds($this->parent)->height;
        set {
            $this->scaleY = 1.0;
            $current = $this->getBounds($this->parent)->height;
            if ($current != 0.0) {
                $this->scaleY = $value / $current;
            }
        }
    }

    public ?Stage $stage {
        get {
            $o = $this;
            while ($o->parent !== null) {
                $o = $o->parent;
            }
            return $o instanceof Stage ? $o : null;
        }
    }

    /** @internal cached local matrix: read through updateMatrix() */
    public float $ma = 1.0;
    public float $mb = 0.0;
    public float $mc = 0.0;
    public float $md = 1.0;
    public float $mtx = 0.0;
    public float $mty = 0.0;
    private bool $matrixDirty = true;

    /** @internal Refreshes the cached local matrix fields if a transform property changed. */
    final public function updateMatrix(): void
    {
        if (!$this->matrixDirty) {
            if ($this->mvx == 0.0 && $this->mvy == 0.0 && $this->mvr == 0.0) {
                return;
            }
            $now = $this->clock?->time ?? 0.0;   // moving: the matrix is a function of time
            if ($now === $this->matrixTime) {
                return;
            }
        }
        $this->matrixDirty = false;
        $this->matrixTime = $this->clock?->time ?? 0.0;
        if ($this->skewX == 0.0 && $this->skewY == 0.0) {
            if ($this->rotation == 0.0) {
                $this->ma = $this->scaleX;
                $this->mb = 0.0;
                $this->mc = 0.0;
                $this->md = $this->scaleY;
            } else {
                $cos = cos($this->rotation);
                $sin = sin($this->rotation);
                $this->ma = $this->scaleX * $cos;
                $this->mb = $this->scaleX * $sin;
                $this->mc = -$this->scaleY * $sin;
                $this->md = $this->scaleY * $cos;
            }
        } else {
            $this->ma = $this->scaleX * cos($this->rotation + $this->skewY);
            $this->mb = $this->scaleX * sin($this->rotation + $this->skewY);
            $this->mc = -$this->scaleY * sin($this->rotation - $this->skewX);
            $this->md = $this->scaleY * cos($this->rotation - $this->skewX);
        }
        $this->mtx = $this->x - $this->pivotX * $this->ma - $this->pivotY * $this->mc;
        $this->mty = $this->y - $this->pivotX * $this->mb - $this->pivotY * $this->md;
    }

    /** The transform from this object's coordinates to its parent's. */
    public function getLocalMatrix(): Matrix
    {
        $this->updateMatrix();
        return new Matrix($this->ma, $this->mb, $this->mc, $this->md, $this->mtx, $this->mty);
    }

    /** The transform from this object's coordinates to those of $target (the stage's root if null). */
    public function getTransformationMatrix(?DisplayObject $target = null): Matrix
    {
        $m = new Matrix();
        for ($o = $this; $o !== null && $o !== $target; $o = $o->parent) {
            $m->concat($o->getLocalMatrix());
        }
        if ($target !== null && $o !== $target) {
            $t = $target->getTransformationMatrix(null);
            $t->invert();
            $m->concat($t);
        }
        return $m;
    }

    /** @return array{float, float} */
    public function localToGlobal(float $x, float $y): array
    {
        return $this->getTransformationMatrix(null)->transform($x, $y);
    }

    /** @return array{float, float} */
    public function globalToLocal(float $x, float $y): array
    {
        $m = $this->getTransformationMatrix(null);
        $m->invert();
        return $m->transform($x, $y);
    }

    protected function world(): \Phame\World
    {
        return $this->stage?->world ?? \Phame\World::current();
    }

    public function removeFromParent(): void
    {
        $this->parent?->removeChild($this);
    }

    /** The bounds in the coordinate system of $space (the parent's, if null is not given explicitly). */
    abstract public function getBounds(?DisplayObject $space): Rectangle;

    /** Whether the two objects' bounding boxes overlap on screen. Good for bullets and pickups; rotated objects get a generous box. */
    public function overlaps(DisplayObject $other): bool
    {
        self::screenBox($this);
        $x0 = self::$boxX0;
        $y0 = self::$boxY0;
        $x1 = self::$boxX1;
        $y1 = self::$boxY1;
        self::screenBox($other);
        return $x0 < self::$boxX1 && self::$boxX0 < $x1 && $y0 < self::$boxY1 && self::$boxY0 < $y1;
    }

    /**
     * A visible child of $group that this object overlaps, or null. Inside Async::until() conditions the engine
     * keeps a bounding volume tree of the group, updated once per frame, so a bullet tests only nearby targets;
     * elsewhere the children are scanned in drawing order.
     */
    public function firstOverlap(DisplayObjectContainer $group, float $margin = 0.0): ?DisplayObject
    {
        if (self::$stableFrame >= 0) {
            $tree = $this->world()->collisionTree($group);
            if ($tree->synced !== self::$stableFrame) {
                $tree->synced = self::$stableFrame;
                foreach ($group->getChildren() as $child) {
                    if ($child->visible) {
                        self::screenBox($child);
                        $tree->set($child, self::$boxX0, self::$boxY0, self::$boxX1, self::$boxY1)->seen = self::$stableFrame;
                    }
                }
                $tree->removeUnseen(self::$stableFrame);
            }
            self::screenBox($this);
            return $tree->first(self::$boxX0 - $margin, self::$boxY0 - $margin, self::$boxX1 + $margin, self::$boxY1 + $margin, $this);
        }
        self::screenBox($this);
        $x0 = self::$boxX0 - $margin;
        $y0 = self::$boxY0 - $margin;
        $x1 = self::$boxX1 + $margin;
        $y1 = self::$boxY1 + $margin;
        foreach ($group->getChildren() as $child) {
            if ($child !== $this && $child->visible) {
                self::screenBox($child);
                if ($x0 < self::$boxX1 && self::$boxX0 < $x1 && $y0 < self::$boxY1 && self::$boxY0 < $y1) {
                    return $child;
                }
            }
        }
        return null;
    }

    /** @internal Set by the engine while it checks Async::until() conditions: positions are stable then. */
    public static int $stableFrame = -1;
    private int $boxFrame = -2;
    private float $boxCacheX0 = 0.0;
    private float $boxCacheY0 = 0.0;
    private float $boxCacheX1 = 0.0;
    private float $boxCacheY1 = 0.0;

    /** @internal Whether the object's box is entirely outside its stage. */
    public function outsideStage(): bool
    {
        $stage = $this->stage;
        if ($stage === null) {
            return true;
        }
        self::screenBox($this);
        return self::$boxX1 <= 0.0 || self::$boxY1 <= 0.0 || self::$boxX0 >= $stage->stageWidth || self::$boxY0 >= $stage->stageHeight;
    }

    public function kineticState(): void
    {
        self::computeScreenBox($this);
        $x0 = self::$boxX0;
        $y0 = self::$boxY0;
        $x1 = self::$boxX1;
        $y1 = self::$boxY1;
        $cx = ($x0 + $x1) / 2;
        $cy = ($y0 + $y1) / 2;
        if ($this->mvr != 0.0) {   // spinning: a circle around its own pivot holds it at any angle
            self::worldTransformOf($this);
            $cx = self::$wtx;
            $cy = self::$wty;
            $r = sqrt(max(($x0 - $cx) ** 2, ($x1 - $cx) ** 2) + max(($y0 - $cy) ** 2, ($y1 - $cy) ** 2));
            [$x0, $y0, $x1, $y1] = [$cx - $r, $cy - $r, $cx + $r, $cy + $r];
        }
        // the world velocity of the reference point: every ancestor's velocity, plus every rotating ancestor's turn
        // (omega x r); arcs are followed along their tangent for a short window, with the box grown to hold them
        $vx = $vy = 0.0;
        $margin = 0.0;
        $horizon = INF;
        $window = \Phame\Kinetic\Kinetic::$arcHorizon;
        for ($n = $this; $n !== null; $n = $n->parent) {
            self::worldTransformOf($n->parent);
            $pa = self::$wa;
            $pb = self::$wb;
            $pc = self::$wc;
            $pd = self::$wd;
            if ($n->mvx != 0.0 || $n->mvy != 0.0) {
                $vx += $pa * $n->mvx + $pc * $n->mvy;
                $vy += $pb * $n->mvx + $pd * $n->mvy;
            }
            if ($n !== $this && $n->mvr != 0.0) {
                self::worldTransformOf($n);
                $omega = $pa * $pd - $pb * $pc < 0.0 ? -$n->mvr : $n->mvr;   // a mirrored parent turns the other way
                $rx = $cx - self::$wtx;
                $ry = $cy - self::$wty;
                $vx -= $omega * $ry;
                $vy += $omega * $rx;
                $margin += \Phame\Kinetic\Kinetic::arcDeviation(sqrt($rx * $rx + $ry * $ry), $omega, $window);
                $horizon = $window;
            }
        }
        \Phame\Kinetic\Kinetic::$x0 = $x0 - $margin;
        \Phame\Kinetic\Kinetic::$y0 = $y0 - $margin;
        \Phame\Kinetic\Kinetic::$x1 = $x1 + $margin;
        \Phame\Kinetic\Kinetic::$y1 = $y1 + $margin;
        \Phame\Kinetic\Kinetic::$z0 = -0.5;   // 2D: one plane, so z never separates two bodies
        \Phame\Kinetic\Kinetic::$z1 = 0.5;
        \Phame\Kinetic\Kinetic::$vx = $vx;
        \Phame\Kinetic\Kinetic::$vy = $vy;
        \Phame\Kinetic\Kinetic::$vz = 0.0;
        \Phame\Kinetic\Kinetic::$horizon = $horizon;
    }

    private static float $wa = 1.0;
    private static float $wb = 0.0;
    private static float $wc = 0.0;
    private static float $wd = 1.0;
    private static float $wtx = 0.0;
    private static float $wty = 0.0;

    /** The transform from $o's coordinates to the screen, into the $w statics (identity for null). */
    private static function worldTransformOf(?DisplayObject $o): void
    {
        $a = $d = 1.0;
        $b = $c = $tx = $ty = 0.0;
        for ($n = $o; $n !== null; $n = $n->parent) {
            $n->updateMatrix();
            $na = $n->ma * $a + $n->mc * $b;
            $nb = $n->mb * $a + $n->md * $b;
            $nc = $n->ma * $c + $n->mc * $d;
            $nd = $n->mb * $c + $n->md * $d;
            $ntx = $n->ma * $tx + $n->mc * $ty + $n->mtx;
            $ty = $n->mb * $tx + $n->md * $ty + $n->mty;
            $tx = $ntx;
            $a = $na;
            $b = $nb;
            $c = $nc;
            $d = $nd;
        }
        self::$wa = $a;
        self::$wb = $b;
        self::$wc = $c;
        self::$wd = $d;
        self::$wtx = $tx;
        self::$wty = $ty;
    }

    public function kineticBounds(): void
    {
        $stage = $this->stage;
        \Phame\Kinetic\Kinetic::$minX = $stage === null ? INF : 0.0;   // off stage: outside already
        \Phame\Kinetic\Kinetic::$minY = $stage === null ? INF : 0.0;
        \Phame\Kinetic\Kinetic::$maxX = $stage?->stageWidth ?? -INF;
        \Phame\Kinetic\Kinetic::$maxY = $stage?->stageHeight ?? -INF;
        \Phame\Kinetic\Kinetic::$minZ = -INF;
        \Phame\Kinetic\Kinetic::$maxZ = INF;
    }

    public function kineticGroup(): ?\Phame\Kinetic\Group
    {
        return $this->parent;
    }

    private static float $boxX0 = 0.0;
    private static float $boxY0 = 0.0;
    private static float $boxX1 = 0.0;
    private static float $boxY1 = 0.0;

    /** The object's axis-aligned box on screen into the $box statics; for quads without allocating anything. */
    private static function screenBox(DisplayObject $o): void
    {
        if ($o->boxFrame === self::$stableFrame) {   // computed earlier in this check phase
            self::$boxX0 = $o->boxCacheX0;
            self::$boxY0 = $o->boxCacheY0;
            self::$boxX1 = $o->boxCacheX1;
            self::$boxY1 = $o->boxCacheY1;
            return;
        }
        self::computeScreenBox($o);
        if (self::$stableFrame >= 0) {
            $o->boxFrame = self::$stableFrame;
            $o->boxCacheX0 = self::$boxX0;
            $o->boxCacheY0 = self::$boxY0;
            $o->boxCacheX1 = self::$boxX1;
            $o->boxCacheY1 = self::$boxY1;
        }
    }

    private static function computeScreenBox(DisplayObject $o): void
    {
        if (!$o instanceof Quad) {
            $r = $o->getBounds(null);
            self::$boxX0 = $r->x;
            self::$boxY0 = $r->y;
            self::$boxX1 = $r->x + $r->width;
            self::$boxY1 = $r->y + $r->height;
            return;
        }
        $o->updateMatrix();
        $a = $o->ma;
        $b = $o->mb;
        $c = $o->mc;
        $d = $o->md;
        $tx = $o->mtx;
        $ty = $o->mty;
        for ($p = $o->parent; $p !== null; $p = $p->parent) {   // world = parent * ... * local
            $p->updateMatrix();
            $na = $p->ma * $a + $p->mc * $b;
            $nb = $p->mb * $a + $p->md * $b;
            $nc = $p->ma * $c + $p->mc * $d;
            $nd = $p->mb * $c + $p->md * $d;
            $ntx = $p->ma * $tx + $p->mc * $ty + $p->mtx;
            $ty = $p->mb * $tx + $p->md * $ty + $p->mty;
            $tx = $ntx;
            $a = $na;
            $b = $nb;
            $c = $nc;
            $d = $nd;
        }
        $ax = $a * $o->meshWidth;
        $ay = $b * $o->meshWidth;
        $cx = $c * $o->meshHeight;
        $cy = $d * $o->meshHeight;
        self::$boxX0 = $tx + min(0.0, $ax) + min(0.0, $cx);
        self::$boxX1 = $tx + max(0.0, $ax) + max(0.0, $cx);
        self::$boxY0 = $ty + min(0.0, $ay) + min(0.0, $cy);
        self::$boxY1 = $ty + max(0.0, $ay) + max(0.0, $cy);
    }

    /** The touchable object at the point (in this object's coordinates), or null. */
    abstract public function hitTest(float $x, float $y): ?DisplayObject;

    abstract public function render(Painter $painter): void;

    public function dispose(): void
    {
        $this->cancelCoroutines();
        $this->filter?->dispose();
        $this->removeEventListener(\Phame\Events\Event::ENTER_FRAME);
    }
}
