<?php

namespace Phame\Net;

use Phame\Scene\Geometry;
use Phame\Scene\Label;
use Phame\Scene\Material;
use Phame\Scene\Mesh;
use Phame\Scene\Node3D;
use Phame\Scene\PointLight;
use Phame\Scene\Scene3D;
use Phame\World;

/**
 * A remote window onto one Scene3D: every node in it, as `Node3D`/`Mesh`/`PointLight`/`Label` already describe
 * themselves - see docs/REMOTE-VIEW.md. A game does not call a "show" method; it builds its scene, and whatever is
 * in it is what a joining viewer gets. One `View` per `Scene3D`, like `Physics\Space`.
 *
 * Ids are per-View, monotonic `uint32`s assigned the first time a node (or a Geometry, or a Material) is
 * encountered, and never reused: not `spl_object_id()`, which PHP reuses the moment an object is freed, so a
 * removed node's id could come back moments later naming a different node while a client still holds the old one.
 */
final class View
{
    public const PROTOCOL_VERSION = 1;
    /** Swerve's own ceiling on one message; a snapshot's node list is chunked to stay under it. */
    private const CHUNK_NODES = 200;

    /** @var \WeakMap<Node3D, int> */
    private \WeakMap $nodeIds;
    private int $nextNodeId = 1;
    /** @var \WeakMap<Geometry, int> */
    private \WeakMap $geometryIds;
    private int $nextGeometryId = 1;
    /** @var \WeakMap<Material, int> */
    private \WeakMap $materialIds;
    private int $nextMaterialId = 1;
    /** @var \WeakMap<Node3D, Viewer> nodes shown to one viewer alone, not broadcast; see addTo(). */
    private \WeakMap $audience;
    /** @var array<int, Node3D> every currently-known node, by id: what the next publish() diffs against. */
    private array $known = [];
    /** @var list<Viewer> */
    private array $viewers = [];

    public function __construct(
        private readonly World $world,
        public readonly Scene3D $scene,
        private readonly Transport $transport,
        /** The channel every viewer who sees everything (the common case) is told to subscribe to. */
        private readonly string $channel,
    ) {
        $this->nodeIds = new \WeakMap();
        $this->geometryIds = new \WeakMap();
        $this->materialIds = new \WeakMap();
        $this->audience = new \WeakMap();
    }

    private function idOf(Node3D $node): int
    {
        if (!isset($this->nodeIds[$node])) {
            $this->nodeIds[$node] = $this->nextNodeId++;
            $node->published = true;   // View's own bookkeeping: takeChangedDescriptions() now reports it
        }
        return $this->nodeIds[$node];
    }

    private function geometryIdOf(Geometry $geometry): int
    {
        return $this->geometryIds[$geometry] ??= $this->nextGeometryId++;
    }

    private function materialIdOf(Material $material): int
    {
        return $this->materialIds[$material] ??= $this->nextMaterialId++;
    }

    /** The channel a node's add/remove/description reaches: its own viewer's, if shown to one alone, else everyone's. */
    private function channelFor(Node3D $node): string
    {
        return ($this->audience[$node] ?? null)?->channel ?? $this->channel;
    }

    /** Shows $node (and nothing else - its siblings are unaffected) to $viewer alone, not the shared audience. */
    public function addTo(Viewer $viewer, Node3D $node): void
    {
        $this->audience[$node] = $viewer;
    }

    /** $node goes back to being shown to everyone (the default for anything in the scene). */
    public function removeFrom(Viewer $viewer, Node3D $node): void
    {
        if (($this->audience[$node] ?? null) === $viewer) {
            unset($this->audience[$node]);
        }
    }

    /** A viewer joins: it gets its own channel and an immediate snapshot of the scene as it stands right now. */
    public function join(string $channel): Viewer
    {
        $viewer = new Viewer($channel);
        $this->viewers[] = $viewer;
        $this->sendSnapshotTo($viewer);
        return $viewer;
    }

    public function leave(Viewer $viewer): void
    {
        $i = array_search($viewer, $this->viewers, true);
        if ($i !== false) {
            array_splice($this->viewers, $i, 1);
        }
    }

    /** Every node of $root's subtree, parents before children. */
    private function walk(Node3D $root, array &$out): void
    {
        $out[] = $root;
        foreach ($root->children as $child) {
            $this->walk($child, $out);
        }
    }

    /** @return array{int, int, int, string, bool, mixed...} id, parentId, kind, name, visible, kind fields..., 17 floats */
    private function nodeRecord(Node3D $node): array
    {
        $id = $this->idOf($node);
        $parentId = $node->parent !== null ? $this->idOf($node->parent) : 0;
        $scale = [$node->scaleX, $node->scaleY, $node->scaleZ];
        $description = $node->describe();
        if ($node instanceof Mesh) {
            return [$id, $parentId, 1, $node->name, $node->visible, ...$scale, $this->geometryIdOf($node->geometry), $this->materialIdOf($node->material), ...$description];
        }
        if ($node instanceof PointLight) {
            return [$id, $parentId, 2, $node->name, $node->visible, ...$scale, $node->color, $node->intensity, $node->range, ...$description];
        }
        if ($node instanceof Label) {
            return [$id, $parentId, 3, $node->name, $node->visible, ...$scale, $node->text, $node->color, ...$description];
        }
        return [$id, $parentId, 0, $node->name, $node->visible, ...$scale, ...$description];
    }

    /**
     * `source` set (a loader - glTF, later - built this from an asset): `{id, url}`, a reference a browser fetches
     * and caches itself. Otherwise the vertex/index data inline, as `draw()` would upload to the GPU verbatim.
     */
    private function geometryRecord(Geometry $geometry): array
    {
        $id = $this->geometryIdOf($geometry);
        return $geometry->source !== null
            ? ['id' => $id, 'url' => $geometry->source]
            : ['id' => $id, 'vertices' => $geometry->vertices, 'indices' => $geometry->indices];
    }

    /** As geometryRecord(): a reference if `source` is set, else every field inline. A texture is always a
     * reference (`Texture::$source`) - inline pixel data over the wire is not implemented, and no game sends one yet. */
    private function materialRecord(Material $material): array
    {
        $id = $this->materialIdOf($material);
        if ($material->source !== null) {
            return ['id' => $id, 'url' => $material->source];
        }
        return [
            'id' => $id, 'color' => $material->color, 'alpha' => $material->alpha, 'unlit' => $material->unlit,
            'doubleSided' => $material->doubleSided, 'specular' => $material->specular, 'shininess' => $material->shininess,
            'emissive' => $material->emissive, 'shader' => $material->shader?->fragment, 'displace' => $material->shader?->displace,
            'texture' => $material->texture?->source,
        ];
    }

    /** @return array{environment: string, light: list<float|int>, ambient: int, fog: ?array{int, float, float}} */
    private function sceneRecord(): array
    {
        $sun = $this->scene->lightDirection;
        return [
            'environment' => $this->scene->environment,
            'light' => [$sun->x, $sun->y, $sun->z, $this->scene->lightColor],
            'ambient' => $this->scene->ambient,
            'fog' => $this->scene->fogColor !== null ? [$this->scene->fogColor, $this->scene->fogNear, $this->scene->fogFar] : null,
        ];
    }

    /** The whole scene, in parts small enough for Transport (one node per 76-byte-ish JSON entry, chunked). */
    private function sendSnapshotTo(Viewer $viewer): void
    {
        $nodes = [];
        $this->walk($this->scene->root, $nodes);
        $nodes = array_filter($nodes, fn (Node3D $n) => $this->channelFor($n) === $this->channel || $this->channelFor($n) === $viewer->channel);

        $geometries = [];
        $materials = [];
        $seenGeometry = [];
        $seenMaterial = [];
        $nodeRecords = [];
        foreach ($nodes as $node) {
            if ($node instanceof Mesh) {
                $gid = $this->geometryIdOf($node->geometry);
                if (!isset($seenGeometry[$gid])) {
                    $seenGeometry[$gid] = true;
                    $geometries[] = $this->geometryRecord($node->geometry);
                }
                $mid = $this->materialIdOf($node->material);
                if (!isset($seenMaterial[$mid])) {
                    $seenMaterial[$mid] = true;
                    $materials[] = $this->materialRecord($node->material);
                }
            }
            $nodeRecords[] = $this->nodeRecord($node);
        }
        $this->known = [];
        foreach ($nodes as $node) {
            $this->known[$this->idOf($node)] = $node;
        }

        $chunks = array_chunk($nodeRecords, self::CHUNK_NODES) ?: [[]];
        foreach ($chunks as $i => $chunk) {
            $message = [
                'v' => self::PROTOCOL_VERSION, 'type' => 'snapshot', 'part' => $i, 'parts' => count($chunks),
                't' => $this->world->clock->time, 'nodes' => $chunk,
            ];
            if ($i === 0) {
                $message['geometries'] = $geometries;
                $message['materials'] = $materials;
                $message += $this->sceneRecord();
            }
            $this->transport->publish($viewer->channel, json_encode($message));
        }
    }

    /** One record's bytes: id (uint32), t0/x/y/z (float64), then 13 more (velocity, orientation, spin, acceleration) as float32. */
    private function packRecord(int $id, array $description): string
    {
        [$t0, $x, $y, $z, $vx, $vy, $vz, $qx, $qy, $qz, $qw, $wx, $wy, $wz, $ax, $ay, $az] = $description;
        return pack('Ve4g13', $id, $t0, $x, $y, $z, $vx, $vy, $vz, $qx, $qy, $qz, $qw, $wx, $wy, $wz, $ax, $ay, $az);
    }

    /**
     * Diffs the scene against the last call (adds, removes), then publishes whatever changed since. A game calls
     * this once per loop iteration (as RocksGame::run() and SolarGame::run() drive their own flush today);
     * View does not run a hidden timer of its own.
     */
    public function publish(): void
    {
        $nodes = [];
        $this->walk($this->scene->root, $nodes);
        $nowKnown = [];
        foreach ($nodes as $node) {
            $nowKnown[$this->idOf($node)] = $node;
        }
        $addedIds = array_diff_key($nowKnown, $this->known);
        $removedIds = array_diff_key($this->known, $nowKnown);
        $this->known = $nowKnown;

        if ($addedIds !== []) {
            $byChannel = [];
            foreach ($addedIds as $node) {
                $byChannel[$this->channelFor($node)][] = $this->nodeRecord($node);
            }
            foreach ($byChannel as $channel => $records) {
                $this->transport->publish($channel, json_encode(['v' => self::PROTOCOL_VERSION, 'type' => 'add', 'nodes' => $records]));
            }
        }
        if ($removedIds !== []) {
            $byChannel = [];
            foreach ($removedIds as $id => $node) {
                $byChannel[$this->channelFor($node)][] = $id;
            }
            foreach ($byChannel as $channel => $ids) {
                $this->transport->publish($channel, json_encode(['v' => self::PROTOCOL_VERSION, 'type' => 'remove', 'ids' => $ids]));
            }
        }

        $changed = $this->world->takeChangedDescriptions();
        if ($changed === []) {
            return;
        }
        $byChannel = [];   // channel => list of [Node3D, packed bytes]
        foreach ($changed as [$node, $description]) {
            if (!isset($this->nodeIds[$node])) {
                continue;   // not (or no longer) in this view
            }
            $byChannel[$this->channelFor($node)][] = [$node, $this->packRecord($this->nodeIds[$node], $description)];
        }
        $time = $this->world->clock->time;
        $header = pack('Ce', self::PROTOCOL_VERSION, $time);
        foreach ($byChannel as $channel => $records) {
            $this->transport->publish($channel, $header . implode('', array_column($records, 1)));
            if ($channel !== $this->channel) {
                continue;   // already one viewer's own channel: interest filtering does not apply to a private node
            }
            foreach ($this->viewers as $viewer) {
                if ($viewer::class === Viewer::class) {
                    continue;   // the default: everyone sees everything, already covered by the broadcast above
                }
                $filtered = '';
                foreach ($records as [$node, $bytes]) {
                    if ($viewer->interestedIn($node)) {
                        $filtered .= $bytes;
                    }
                }
                if ($filtered !== '') {
                    $this->transport->publish($viewer->channel, $header . $filtered);
                }
            }
        }
    }
}
