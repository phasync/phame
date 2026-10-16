<?php

namespace Phame\Tests;

use Phame\Net\Transport;
use Phame\Net\View;
use Phame\Net\Viewer;
use Phame\Rendering\MeshShader;
use Phame\Scene\Geometry;
use Phame\Scene\Material;
use Phame\Scene\Mesh;
use Phame\Scene\Node3D;
use Phame\Scene\PointLight;
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

    public function testSnapshotChunksByEncodedByteSizeNotJustNodeCount(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        // 40 distinct (unshared) materials, each with a ~4 KB shader: ~160 KB total, several times over
        // View::MAX_MESSAGE_BYTES's headroom in one part - this used to fit in a single node-count-limited chunk
        // and blow Swerve's own ceiling; now it must come back as more than one part.
        for ($i = 0; $i < 40; ++$i) {
            $mesh = new Mesh(Geometry::sphere(), new Material(shader: new MeshShader('// ' . str_repeat('x', 4000) . "\nvec4 shade() { return vec4(1.0); }")));
            $scene->root->addChild($mesh);
        }

        $view->join('viewer1');

        $this->assertGreaterThan(1, count($transport->published), 'the assets alone do not fit one part');
        $geometries = $materials = $nodes = [];
        foreach ($transport->published as [$channel, $payload]) {
            $this->assertLessThanOrEqual(View::MAX_MESSAGE_BYTES, strlen($payload), 'every part stays under the protocol limit');
            $message = json_decode($payload, true);
            $this->assertSame('viewer1', $channel);
            $geometries = [...$geometries, ...($message['geometries'] ?? [])];
            $materials = [...$materials, ...($message['materials'] ?? [])];
            $nodes = [...$nodes, ...$message['nodes']];
        }
        $this->assertCount(40, $geometries, 'one geometry per mesh: Geometry::sphere() here makes a fresh one each time');
        $this->assertCount(40, $materials);
        $this->assertCount(41, $nodes, 'the scene root plus the 40 meshes');
    }

    public function testASingleRecordTooBigForAnyChunkIsALoudError(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $huge = new MeshShader('// ' . str_repeat('x', View::MAX_MESSAGE_BYTES) . "\nvec4 shade() { return vec4(1.0); }");
        $scene->root->addChild(new Mesh(Geometry::sphere(), new Material(shader: $huge)));

        try {
            $view->join('viewer1');
            $this->fail('expected an oversized material to be refused, not silently dropped or truncated');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('material', $e->getMessage());
            $this->assertStringContainsString('byte', $e->getMessage());
        }
    }

    public function testAddOfANewMeshBringsItsGeometryAndMaterialTheFirstTime(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $view->join('viewer1');
        $transport->reset();

        $mesh = new Mesh(Geometry::sphere(), new Material(color: 0xabcdef));
        $scene->root->addChild($mesh);
        $view->publish();

        $this->assertCount(1, $transport->published);
        $added = json_decode($transport->published[0][1], true);
        $this->assertSame('add', $added['type']);
        $this->assertCount(1, $added['geometries'] ?? [], 'a geometry never shown on this channel before must come with the node that needs it');
        $this->assertCount(1, $added['materials'] ?? [], 'likewise its material');
        [, , , , , , , , $geometryId, $materialId] = $added['nodes'][0];
        $this->assertSame($geometryId, $added['geometries'][0]['id']);
        $this->assertSame($materialId, $added['materials'][0]['id']);
    }

    public function testAddOfASharedGeometryAndMaterialDoesNotResendThem(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        $geometry = Geometry::sphere();
        $material = new Material(color: 0x112233);
        $scene->root->addChild(new Mesh($geometry, $material));   // in the initial snapshot, so this channel knows it
        $view->join('viewer1');
        $transport->reset();

        $scene->root->addChild(new Mesh($geometry, $material));   // added later, same objects
        $view->publish();

        $this->assertCount(1, $transport->published);
        $added = json_decode($transport->published[0][1], true);
        $this->assertArrayNotHasKey('geometries', $added, 'already known on this channel: not sent again');
        $this->assertArrayNotHasKey('materials', $added);
    }

    public function testAPublishFailureIsLoggedWithChannelAndSizeBeforeItPropagates(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phame-view-log-');
        $previous = ini_set('error_log', $logFile);
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new class implements Transport {
            public function publish(string $channel, string $payload): void
            {
                throw new \RuntimeException('transport is down');
            }
        };
        $view = new View($world, $scene, $transport, 'out');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('transport is down');
            $view->join('viewer1');
        } finally {
            $log = file_get_contents($logFile) ?: '';
            $this->assertStringContainsString('viewer1', $log, 'the channel - a join sends its snapshot to the viewer\'s own channel');
            $this->assertStringContainsString('transport is down', $log);
            ini_set('error_log', $previous);
            @unlink($logFile);
        }
    }

    public function testPointLightRecordCarriesItsLightRadius(): void
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $transport = new RecordingTransport();
        $view = new View($world, $scene, $transport, 'out');
        // fractional values: a whole-number float and an int are the same JSON number, so a whole 2.0 would decode
        // back as PHP int 2 - not what this test means to check (that the field round-trips at all, at its place)
        $scene->root->addChild(new PointLight(color: 0xffffff, intensity: 2.5, range: 50.5, lightRadius: 696000.25));

        $view->join('viewer1');

        $light = $transport->published[0] ?? null;
        $nodes = json_decode($light[1], true)['nodes'];
        $record = $nodes[array_key_last($nodes)];   // the root is first, the light is last
        [, , $kind, , , , , , $color, $intensity, $range, $lightRadius] = $record;
        $this->assertSame(2, $kind);
        $this->assertSame(0xffffff, $color);
        $this->assertSame(2.5, $intensity);
        $this->assertSame(50.5, $range);
        $this->assertSame(696000.25, $lightRadius);
    }
}
