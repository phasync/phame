<?php

namespace Phame\Tests\Server;

require_once __DIR__ . '/../../server/vendor/autoload.php';

use PhameServer\SolarGame;
use PhameServer\Solar\SolarSystem;
use PHPUnit\Framework\TestCase;

class SolarGameTest extends TestCase
{
    private function buildGame(): SolarGame
    {
        $class = new \ReflectionClass(SolarGame::class);
        $game = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($game);
        return $game;
    }

    private function snapshot(SolarGame $game): array
    {
        return (new \ReflectionMethod(SolarGame::class, 'snapshot'))->invoke($game);
    }

    public function testSnapshotRoundTripsEveryNodeParentsBeforeChildren(): void
    {
        $game = $this->buildGame();
        $parts = $this->snapshot($game);
        $this->assertGreaterThan(1, count($parts), 'the tree should need more than one part');

        $seenIds = [];
        $total = 0;
        $moon = null;
        $byId = [];
        foreach ($parts as $i => $part) {
            $decoded = json_decode($part, true);
            $this->assertSame('snapshot', $decoded['type']);
            $this->assertSame($i, $decoded['part']);
            $this->assertSame(count($parts), $decoded['parts']);

            foreach ($decoded['nodes'] as $node) {
                [$id, $parentId, $name, $radius, $kind] = $node;
                if ($parentId !== 0) {
                    $this->assertArrayHasKey($parentId, $seenIds, "node $id ($name)'s parent $parentId was not seen before it");
                }
                $this->assertArrayNotHasKey($id, $seenIds, "node $id appeared twice");
                $seenIds[$id] = true;
                $byId[$id] = ['parentId' => $parentId, 'name' => $name, 'radius' => $radius, 'kind' => $kind];
                $this->assertCount(22, $node, 'id, parentId, name, radius, kind, then 17 description floats');
                if ($name === 'Moon') {
                    $moon = $id;
                }
                ++$total;
            }
        }

        $this->assertCount($total, $seenIds);
        $this->assertNotNull($moon, 'the Moon should be in the snapshot');
        $this->assertSame(SolarSystem::KIND_MOON, $byId[$moon]['kind']);

        // Moon -> orbit frame -> Earth's (unnamed) anchor, which also carries the named Earth body as a sibling
        $orbitFrameId = $byId[$moon]['parentId'];
        $this->assertSame(SolarSystem::KIND_ORBIT_FRAME, $byId[$orbitFrameId]['kind']);
        $earthAnchorId = $byId[$orbitFrameId]['parentId'];
        $this->assertSame('', $byId[$earthAnchorId]['name'], 'the anchor itself is unnamed; the name is on the body');
        $this->assertSame(SolarSystem::KIND_ANCHOR, $byId[$earthAnchorId]['kind']);
        $earthBody = array_filter($byId, static fn ($n) => $n['parentId'] === $earthAnchorId && $n['name'] === 'Earth');
        $this->assertCount(1, $earthBody, "Earth's anchor should have exactly one child named Earth (its body)");
        $this->assertSame(SolarSystem::KIND_PLANET, reset($earthBody)['kind']);
    }

    public function testSnapshotIncludesStarPlanetsAndAsteroids(): void
    {
        $game = $this->buildGame();
        $kinds = [];
        foreach ($this->snapshot($game) as $part) {
            foreach (json_decode($part, true)['nodes'] as [$id, $parentId, $name, $radius, $kind]) {
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            }
        }
        $this->assertSame(1, $kinds[SolarSystem::KIND_STAR] ?? 0);
        $this->assertSame(8, $kinds[SolarSystem::KIND_PLANET] ?? 0);
        $this->assertSame(300, $kinds[SolarSystem::KIND_ASTEROID] ?? 0);
        $this->assertGreaterThan(0, $kinds[SolarSystem::KIND_MOON] ?? 0);
    }
}
