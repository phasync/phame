<?php

namespace Phame\Collision;

use Phame\Pool\Poolable;

/** @internal A node of an AabbTree: a leaf holds an item and its enlarged box, a branch the union of two children. */
final class AabbNode
{
    use Poolable;

    public ?AabbNode $parent = null;
    public ?AabbNode $left = null;
    public ?AabbNode $right = null;
    public ?object $item = null;
    public float $x0 = 0.0;
    public float $y0 = 0.0;
    public float $x1 = 0.0;
    public float $y1 = 0.0;
    /** A leaf's real (not enlarged) box. */
    public float $rx0 = 0.0;
    public float $ry0 = 0.0;
    public float $rx1 = 0.0;
    public float $ry1 = 0.0;
    /** Frame stamp, for callers that sync the tree with a set of objects. */
    public int $seen = 0;

    private function __construct()
    {
    }

    public static function get(): self
    {
        $n = self::acquire();
        $n->parent = $n->left = $n->right = null;
        $n->item = null;
        $n->seen = 0;
        return $n;
    }

    public function free(): void
    {
        $this->parent = $this->left = $this->right = null;   // no cycles left behind in the pool
        $this->item = null;
        $this->release();
    }
}
