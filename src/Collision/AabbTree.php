<?php

namespace Phame\Collision;

/**
 * A dynamic bounding volume hierarchy of 2D boxes (as in Box2D's dynamic tree). Each item is stored with a box
 * enlarged by $margin, so an item that moves a little needs no update at all; only one that leaves its enlarged box
 * is reinserted. Queries visit only the branches whose boxes overlap. Nodes are pooled and queries use a reused
 * stack, so a steady game allocates nothing here.
 */
final class AabbTree
{
    private ?AabbNode $root = null;
    /** @var \WeakMap<object, AabbNode> */
    private \WeakMap $leaves;
    /** @var list<AabbNode> reused traversal stack */
    private array $stack = [];
    /** @var list<?AabbNode> reused list for removals */
    private array $stale = [];

    /** The frame its owner last brought it up to date in (see DisplayObject::firstOverlap()). */
    public int $synced = -1;

    public function __construct(public float $margin = 8.0)
    {
        $this->leaves = new \WeakMap();
    }

    public int $count {
        get => count($this->leaves);
    }

    /** Adds the item, or updates its box. Returns its leaf (for the seen stamp). */
    public function set(object $item, float $x0, float $y0, float $x1, float $y1): AabbNode
    {
        $leaf = $this->leaves[$item] ?? null;
        if ($leaf !== null) {
            $leaf->rx0 = $x0;
            $leaf->ry0 = $y0;
            $leaf->rx1 = $x1;
            $leaf->ry1 = $y1;
            if ($x0 >= $leaf->x0 && $y0 >= $leaf->y0 && $x1 <= $leaf->x1 && $y1 <= $leaf->y1) {
                return $leaf;   // still inside its enlarged box
            }
            $this->detach($leaf);
        } else {
            $leaf = AabbNode::get();
            $leaf->item = $item;
            $leaf->rx0 = $x0;
            $leaf->ry0 = $y0;
            $leaf->rx1 = $x1;
            $leaf->ry1 = $y1;
            $this->leaves[$item] = $leaf;
        }
        $m = $this->margin;
        $leaf->x0 = $x0 - $m;
        $leaf->y0 = $y0 - $m;
        $leaf->x1 = $x1 + $m;
        $leaf->y1 = $y1 + $m;
        $this->attach($leaf);
        return $leaf;
    }

    public function remove(object $item): void
    {
        $leaf = $this->leaves[$item] ?? null;
        if ($leaf !== null) {
            unset($this->leaves[$item]);
            $this->detach($leaf);
            $leaf->free();
        }
    }

    /** Removes every item whose leaf was not stamped with $seen. */
    public function removeUnseen(int $seen): void
    {
        $n = 0;
        foreach ($this->leaves as $leaf) {
            if ($leaf->seen !== $seen) {
                $this->stale[$n++] = $leaf;
            }
        }
        for ($i = 0; $i < $n; ++$i) {
            $this->remove($this->stale[$i]->item);
            $this->stale[$i] = null;   // the array keeps its size: no reallocation next time
        }
    }

    /** The first item (other than $except) whose real box overlaps the given box, or null. */
    public function first(float $x0, float $y0, float $x1, float $y1, ?object $except = null): ?object
    {
        if ($this->root === null) {
            return null;
        }
        $stack = &$this->stack;
        $stack[0] = $this->root;
        $top = 1;
        while ($top > 0) {
            $node = $stack[--$top];
            if ($x0 >= $node->x1 || $node->x0 >= $x1 || $y0 >= $node->y1 || $node->y0 >= $y1) {
                continue;
            }
            if ($node->item !== null) {
                if ($node->item !== $except && $x0 < $node->rx1 && $node->rx0 < $x1 && $y0 < $node->ry1 && $node->ry0 < $y1) {
                    return $node->item;
                }
                continue;
            }
            $stack[$top++] = $node->left;
            $stack[$top++] = $node->right;
        }
        return null;
    }

    public function clear(): void
    {
        foreach ($this->leaves as $item => $_) {
            $this->remove($item);
        }
    }

    private function attach(AabbNode $leaf): void
    {
        if ($this->root === null) {
            $this->root = $leaf;
            $leaf->parent = null;
            return;
        }
        // descend to the sibling whose union with the leaf grows the least (perimeter heuristic)
        $node = $this->root;
        while ($node->item === null) {
            $l = $node->left;
            $r = $node->right;
            $costL = self::perimeter(min($l->x0, $leaf->x0), min($l->y0, $leaf->y0), max($l->x1, $leaf->x1), max($l->y1, $leaf->y1)) - self::perimeter($l->x0, $l->y0, $l->x1, $l->y1);
            $costR = self::perimeter(min($r->x0, $leaf->x0), min($r->y0, $leaf->y0), max($r->x1, $leaf->x1), max($r->y1, $leaf->y1)) - self::perimeter($r->x0, $r->y0, $r->x1, $r->y1);
            $node = $costL <= $costR ? $l : $r;
        }
        $branch = AabbNode::get();
        $old = $node->parent;
        $branch->parent = $old;
        $branch->left = $node;
        $branch->right = $leaf;
        $node->parent = $branch;
        $leaf->parent = $branch;
        if ($old === null) {
            $this->root = $branch;
        } elseif ($old->left === $node) {
            $old->left = $branch;
        } else {
            $old->right = $branch;
        }
        self::refit($branch);
    }

    private function detach(AabbNode $leaf): void
    {
        $branch = $leaf->parent;
        if ($branch === null) {
            $this->root = null;
            return;
        }
        $sibling = $branch->left === $leaf ? $branch->right : $branch->left;
        $grand = $branch->parent;
        $sibling->parent = $grand;
        if ($grand === null) {
            $this->root = $sibling;
        } else {
            if ($grand->left === $branch) {
                $grand->left = $sibling;
            } else {
                $grand->right = $sibling;
            }
            self::refit($grand);
        }
        $leaf->parent = null;
        $branch->free();
    }

    private static function refit(?AabbNode $node): void
    {
        for (; $node !== null; $node = $node->parent) {
            $l = $node->left;
            $r = $node->right;
            $node->x0 = min($l->x0, $r->x0);
            $node->y0 = min($l->y0, $r->y0);
            $node->x1 = max($l->x1, $r->x1);
            $node->y1 = max($l->y1, $r->y1);
        }
    }

    private static function perimeter(float $x0, float $y0, float $x1, float $y1): float
    {
        return ($x1 - $x0) + ($y1 - $y0);
    }
}
