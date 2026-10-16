<?php

namespace Phame\Tests;

use Phame\Net\Transport;
use Phame\Net\View;
use Phame\Net\Viewer;
use Phame\Scene\Geometry;
use Phame\Scene\Material;
use Phame\Scene\Mesh;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\World;
use PHPUnit\Framework\TestCase;

/** A transport that just remembers what was published, in order, for the test to inspect. */
final class RecordingTransport implements Transport
{
    /** @var list<array{string, string}> [channel, payload] */
    public array $published = [];

    public function publish(string $channel, string $payload): void
    {
        $this->published[] = [$channel, $payload];
    }

    /** Clears what has been recorded so far, so a test can look only at what happens next. */
    public function reset(): void
    {
        $this->published = [];
    }
}

final class ViewTest extends TestCase
{
    public function testSnapshotOrdersGeometriesMaterialsThenNodesParentsBeforeChildren(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $geometry = Geometry::sphere();
        $material = new Material(color: 0x112233);
        $parent = new Mesh($geometry, $material);
        $child = new Mesh($geometry, $material);   // shares the same geometry and material: sent once each
        $scene->root->addChild($parent);
        $parent->addChild($child);

        $view->join('viewer1');

        $this->assertCount(1, $transport->published, 'everything fits one chunk');
        $message = json_decode($transport->published[0][1], true);
        $this->assertSame(1, $message['v'], 'the protocol version');
        $this->assertSame('snapshot', $message['type']);
        $this->assertCount(1, $message['geometries'], 'one geometry, shared by both meshes');
        $this->assertCount(1, $message['materials'], 'one material, shared by both meshes');
        // nodes: the scene root, then parent, then child - a node never appears before its own parent
        $ids = array_column($message['nodes'], 0);
        $parentIds = array_column($message['nodes'], 1);
        $positionOf = array_flip($ids);
        foreach ($ids as $i => $id) {
            $parentId = $parentIds[$i];
            if ($parentId !== 0) {
                $this->assertLessThan($i, $positionOf[$parentId], "node $id's parent is listed before it");
            }
        }
        $this->assertCount(3, $message['nodes'], 'the scene root, the parent mesh, the child mesh');
    }

    public function testAddsAndRemovesFireOnAddChildAndDisposeOrRemoveChild(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $view->join('viewer1');
        $transport->reset();

        $node = new Node3D();
        $scene->root->addChild($node);
        $view->publish();
        $this->assertCount(1, $transport->published);
        $added = json_decode($transport->published[0][1], true);
        $this->assertSame('add', $added['type']);
        $addedId = $added['nodes'][0][0];
        $transport->reset();

        $node->dispose();
        $view->publish();
        $this->assertCount(1, $transport->published);
        $removed = json_decode($transport->published[0][1], true);
        $this->assertSame('remove', $removed['type']);
        $this->assertSame([$addedId], $removed['ids']);
    }

    public function testPrivateNodeReachesOnlyItsOwnViewersChannel(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'broadcast');
        $viewerA = $view->join('channelA');
        $view->join('channelB');
        $transport->reset();

        $hudNode = new Node3D();
        $scene->root->addChild($hudNode);
        $view->addTo($viewerA, $hudNode);
        $view->publish();

        $this->assertCount(1, $transport->published, 'one message: to A alone, never broadcast');
        [$channel, $payload] = $transport->published[0];
        $this->assertSame('channelA', $channel);
        $this->assertSame('add', json_decode($payload, true)['type']);
    }

    public function testProtocolVersionIsPresentOnSnapshotAddAndDescriptionBatch(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $view->join('viewer1');
        $this->assertSame(1, json_decode($transport->published[0][1], true)['v']);
        $transport->reset();

        $node = new Node3D();
        $scene->root->addChild($node);
        $view->publish();
        $this->assertSame(1, json_decode($transport->published[0][1], true)['v']);
        $transport->reset();

        \phasync::run(function () use ($world, $node, $view, $transport) {
            $world->run(function (World $w) use ($node, $view, $transport) {
                $node->setVelocity(1.0, 0.0, 0.0);
                $w->tick(0.1);
                $node->setVelocity(2.0, 0.0, 0.0);
                $view->publish();
            });
        });
        $this->assertNotEmpty($transport->published);
        $payload = $transport->published[0][1];
        $this->assertSame(View::PROTOCOL_VERSION, ord($payload[0]), 'the binary batch\'s first byte');
    }

    public function testNodeIdsAreMonotonicAndNeverReused(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $view->join('viewer1');
        $transport->reset();

        $a = new Node3D();
        $scene->root->addChild($a);
        $view->publish();
        $idA = json_decode($transport->published[0][1], true)['nodes'][0][0];
        $transport->reset();

        $a->dispose();
        $view->publish();   // a remove for $idA
        $transport->reset();

        $b = new Node3D();
        $scene->root->addChild($b);
        $view->publish();
        $idB = json_decode($transport->published[0][1], true)['nodes'][0][0];

        $this->assertGreaterThan($idA, $idB, 'a fresh node never gets a removed one\'s id back');
    }

    public function testDescriptionPositionsRoundTripAtOneBillionUnits(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $view->join('viewer1');
        $transport->reset();

        $node = new Node3D();
        $node->moveTo(1_000_000_000.123456, -1_000_000_000.654321, 0.25);
        $scene->root->addChild($node);
        $view->publish();   // the add already carries the description, as JSON - no precision lost there either
        $added = json_decode($transport->published[0][1], true)['nodes'][0];
        $description = array_slice($added, -17);
        $this->assertEqualsWithDelta(1_000_000_000.123456, $description[1], 1e-6);
        $this->assertEqualsWithDelta(-1_000_000_000.654321, $description[2], 1e-6);
        $transport->reset();

        \phasync::run(function () use ($world, $node, $view, $transport) {
            $world->run(function (World $w) use ($node, $view, $transport) {
                $node->setVelocity(0.0, 0.0, 0.0);   // a fresh description: forces a binary batch entry
                $view->publish();
            });
        });
        $payload = $transport->published[0][1];
        $record = substr($payload, 9);   // past the version byte and the game time
        [, $x, $y] = array_values(unpack('e4', substr($record, 4, 32)));   // t0, x, y, z as float64 (id is the first 4 bytes)
        $this->assertSame(1_000_000_000.123456, $x, 'float64: no float32 rounding at this scale');
        $this->assertSame(-1_000_000_000.654321, $y);
    }
}
