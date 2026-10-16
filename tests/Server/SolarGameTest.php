<?php

namespace Phame\Tests\Server;

require_once __DIR__ . '/../../server/vendor/autoload.php';

use Phame\Scene\Mesh;
use Phame\Scene\Node3D;
use PhameServer\SolarGame;
use PHPUnit\Framework\TestCase;

/**
 * The solar system's node tree shape - what Net\View would walk and show, without going through the wire protocol
 * itself (that's ViewTest's job). A drawn body (star, planet, moon, asteroid) is a Mesh; a structural node (an
 * anchor, an orbit frame) is a plain Node3D, so there is no "kind" to assert here beyond that.
 */
class SolarGameTest extends TestCase
{
    private function buildGame(): SolarGame
    {
        $class = new \ReflectionClass(SolarGame::class);
        $game = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($game);
        return $game;
    }

    /** @return list<Node3D> every node in the scene, parents before children */
    private function walk(Node3D $root): array
    {
        $out = [$root];
        foreach ($root->children as $child) {
            $out = [...$out, ...$this->walk($child)];
        }
        return $out;
    }

    public function testTreeHasEveryNodeParentBeforeChildAndOneOfEachKindByName(): void
    {
        $game = $this->buildGame();
        $nodes = $this->walk($game->scene->root);

        $seen = [];
        $byObjectId = [];
        foreach ($nodes as $node) {
            $parentId = $node->parent !== null ? spl_object_id($node->parent) : null;
            if ($parentId !== null) {
                $this->assertArrayHasKey($parentId, $seen, "a node's parent was not walked before it");
            }
            $seen[spl_object_id($node)] = true;
            $byObjectId[spl_object_id($node)] = $node;
        }

        $moon = null;
        foreach ($nodes as $node) {
            if ($node->name === 'Moon') {
                $moon = $node;
                break;
            }
        }
        $this->assertNotNull($moon, 'the Moon should be in the tree');
        $this->assertInstanceOf(Mesh::class, $moon, 'a drawn body is a Mesh');

        // Moon -> orbit frame -> Earth's (unnamed) anchor, which also carries the named Earth body as a sibling
        $orbitFrame = $moon->parent;
        $this->assertNotInstanceOf(Mesh::class, $orbitFrame, 'an orbit frame is structural, not drawn');
        $earthAnchor = $orbitFrame->parent;
        $this->assertSame('', $earthAnchor->name, "the anchor itself is unnamed; the name is on the body");
        $this->assertNotInstanceOf(Mesh::class, $earthAnchor, 'an anchor is structural, not drawn');
        $earthBodies = array_filter($earthAnchor->children, static fn (Node3D $n) => $n->name === 'Earth');
        $this->assertCount(1, $earthBodies, "Earth's anchor should have exactly one child named Earth (its body)");
        $this->assertInstanceOf(Mesh::class, reset($earthBodies));
    }

    public function testTreeIncludesOneSunEightPlanetsAndThreeHundredAsteroids(): void
    {
        $game = $this->buildGame();
        $nodes = $this->walk($game->scene->root);

        $planetNames = ['Mercury', 'Venus', 'Earth', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune'];
        $sun = $moons = $planets = $asteroids = 0;
        foreach ($nodes as $node) {
            if (!$node instanceof Mesh) {
                continue;
            }
            if ($node->name === 'Sun') {
                ++$sun;
            } elseif (in_array($node->name, $planetNames, true)) {
                ++$planets;
            } elseif ($node->name !== '') {
                ++$moons;   // every other named drawn body is a moon
            } else {
                ++$asteroids;   // asteroids are drawn but unnamed
            }
        }
        $this->assertSame(1, $sun);
        $this->assertSame(8, $planets);
        $this->assertSame(300, $asteroids);
        $this->assertGreaterThan(0, $moons);
    }
}
