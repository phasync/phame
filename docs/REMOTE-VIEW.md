# Remote View

Today, `RocksGame` and `SolarGame` each hand-write a wire protocol: their own binary record layout (64 bytes vs 76),
their own snapshot JSON shape, their own per-client channels, their own ping/pong, and their own notion of "what a
body looks like" (a `{shade, mesh, transparent, tumble}` array indexed by a hand-maintained "kind" integer) that
exists only because the engine's own scene graph was never asked. This plan makes showing a `World` to a browser
Phame's job: one wire format, one server API, one client runtime (`phame.js`, extracted separately from the solar
system's three.js client). A game builds its scene once - the same `Node3D`/`Mesh`/`Geometry`/`Material`/
`PointLight` tree a native renderer already understands - and a viewer of a `Scene3D` receives exactly that tree; it
renders natively or remotely the same way. There is no second, parallel description of "what things look like."

`Phame\Net\View` (name open to bikeshedding) is a viewer's window onto one `Scene3D`, mirroring how `Physics\Space`
is a scene's own physics: `$world->view($scene)`, one per `Scene3D`, lazily created. Swerve sits behind a small
transport interface, not hard-wired in: the engine publishes channels and bytes, nothing from `Swerve\*` appears in
`View`'s public surface.

## What a viewer receives

Every node in the viewed `Scene3D`, not a parallel registry a game populates by hand. A game marks what a viewer
sees by what it puts in the tree (and the audience/interest seams below), nothing else - there is no `show()` call.
For every node:

- **id** - a per-`View` monotonic `uint32`, assigned the first time a node is encountered, retired (never reused for
  a different node) when it leaves the tree. Not `spl_object_id()`: that is reused the moment PHP frees an object,
  so a removed node's id could come back moments later naming a different node while a client still holds the old
  one.
- **parent id** - 0 for the scene's root; a node's own `Node3D::$parent`, by its view-id.
- **name** - `Node3D::$name`, already there for every node (`''` for an unnamed one).
- **description** - `Node3D::describe()`'s 17 floats (t0, position, velocity, orientation, angular velocity,
  acceleration), always all 17: one record shape for every game.
- **visible** - `Node3D::$visible`, already there.
- **kind-specific fields**, from the node's own PHP class, not a protocol parameter:
  - a `Mesh`: its `geometry` and `material`, each sent once and referenced by id (object identity, via a
    `WeakMap`, decides whether a geometry or material has been sent before - two `Mesh`es sharing one `Geometry`
    instance, as a game reusing `Geometry::sphere()` across every rock should, send that geometry's data once).
  - a `PointLight`: color, intensity, range, `lightRadius` (the light source's own physical size, 0 = a true
    point; a renderer wanting a light's angular size at a distance - an eclipse's penumbra, say - reads this,
    never `Node3D::$radius`, which is a collision sphere a light never has a physics reason to carry).
  - a `Label` (new - see below): its text and color.
  - anything else (a bare `Node3D`: a compound's root, a ragdoll's hinge joint, a tether's anchor): transform only,
    nothing drawn. A ragdoll bone is not a protocol concept - it is a `Mesh` with a `Geometry::cylinder()` the game
    adds to the tree like any other node, stretched and oriented like any other node; `View` does not know "bone"
    exists.

**Two additions this needs, to the scene graph, not the protocol:**

- `Phame\Scene\Label` - a `Node3D` that carries text (and a color) instead of a mesh: a name tag over a player's
  avatar, a HUD callout anchored to a world position. A renderer draws it its own way (typically a screen-space
  billboard); it has no geometry or material.
- `Geometry::cylinder()` - the one primitive factory missing next to `box()`/`plane()`/`sphere()`, for exactly the
  bone case above.

**One existing gap closed, in the scene graph, not the protocol:** `Geometry`'s constructor used to touch the GPU
immediately (`Engine::current()->gl`, uploading buffers on the spot), so it could not be constructed at all in a
process with no window and no GL context - which is every game server worker. The vertex and index arrays (and the
bounds derived from them) are now plain PHP values from construction; the VAO and its buffers are created lazily, on
a geometry's first `draw()`, in whichever process actually has a GL context to draw with. A headless worker building
a scene for `View` alone never touches the GPU at all. This was an engine limitation that only went unnoticed
because nothing before now needed to describe a mesh without also being able to render it.

`Material` already carries everything a shown mesh needs: colour, texture, alpha, emissive, specular/shininess,
`unlit`, `doubleSided`, and a custom GLSL shader (`MeshShader`, exactly what `Limb::material()` already returns)
in place of the standard lit model. There is nothing demo-specific to add here - `tumble` (the rocks demo's
per-instance rotation jitter) is not a material property; a game that wants it writes it into its own shader via
`vSeed`, as `Rock::material()`'s GLSL already does for its surface detail.

## Messages

Every message - JSON or binary - starts with a one-byte protocol version. A client checks it on every message (not
only the snapshot) and refuses to render past a mismatch rather than guess. `View::PROTOCOL_VERSION = 1` to start.

### Join -> snapshot

A viewer joins (`{v, type: 'join', client}`) on the game's `in` channel, as today. The game answers on that viewer's
own channel with one or more snapshot chunks, each under `View::MAX_MESSAGE_BYTES` (128 KiB, Swerve's own limit) of
*encoded* bytes - geometries and materials included, not just the node list: a shader's GLSL alone can run to a few
KB, and a scene with many distinctly-coloured (so distinctly-materialled) meshes blows past 128 KiB on assets long
before it blows past it on nodes. `View` packs geometries, then materials, then nodes, greedily into as few parts
as fit - a geometry or material is never split (there is nothing smaller to split it into), and a single record
too big for a part on its own is a thrown `\RuntimeException` naming what and how big, not a part silently over
the limit that a Transport then refuses to send. Each part carries:

1. **Geometries** - `{id, vertices, indices}`: the same `float[8 per vertex]` / `uint16` arrays `Geometry` already
   holds (and uploads to the GPU verbatim) - sent raw (as plain number arrays, since this part is JSON like the
   rest of the snapshot; a later binary snapshot variant is not precluded by this design). There is no "built-in by
   name": `Geometry::sphere()` and a hand-built geometry are indistinguishable data, so the wire format does not
   distinguish them either. **Or** `{id, url}` - `Geometry::$source`, set when a (future) glTF loader built this
   one from an asset instead of a factory or an array literal, a reference the browser fetches and caches itself
   (`"/assets/ship.glb#mesh0"`): large mesh data never goes over the WebSocket.
2. **Materials** - `{id, color, alpha, unlit, doubleSided, specular, shininess, emissive, shader, displace, texture}`
   (`shader`/`displace` are GLSL source, or absent for the standard lit model; `texture` is always a reference -
   `Texture::$source` - never inline pixel data, which is not yet implemented and not yet needed by any game).
   **Or** `{id, url}`, exactly as a geometry, when `Material::$source` is set.
3. **Nodes** - flat records, *parents always before children*, as `SolarGame` already guarantees:
   `[id, parentId, kind, name, visible, scaleX, scaleY, scaleZ, kindFields..., ...17 floats]` (scale is
   `Node3D::$scaleX/Y/Z`, static far more often than not, but part of what a node looks like, same as its mesh);
   `kind` is 0 (plain), 1 (mesh: adds `geometryId, materialId`), 2 (light: adds `color, intensity, range,
   lightRadius`), 3 (label: adds `text, color`). Chunked across as many parts as needed.

Geometries and materials may land on any part, not only the first (a client merges them into its own id maps as
each part arrives, regardless of part number - it only waits for the last part to render). A chunked snapshot
carries `part`/`parts` as today; a client does not act on it until the last part arrives.

An `add` (below) is chunked the same way, and for the same reason: a subtree added well after the join (a thrown
ragdoll's limbs, say) can introduce a geometry or material no viewer of that channel has seen yet, so `add` also
carries whichever `geometries`/`materials` are new to that channel - sent once per channel, not once per node, so
a game's own shared-by-(class, colour) `Material` (the same pattern `Ball`/`Limb` use) costs the wire nothing extra
for every further node that reuses it.

### Description batch (binary)

One record shape for every game: version byte, game time (float64), then per changed node - id (`uint32`), t0
(`float64`), position (3 x `float64`), then velocity, orientation, angular velocity and acceleration (13 x
`float32`), little-endian. 4 + 8 + 24 + 13*4 = 88 bytes. Position needs float64: at 4.5e9 km (Neptune's orbit) a
float32's representable step is already around 500 km, and even Earth's orbit (1.5e8 km) only gets about 16 km of
precision - both well inside "visibly wrong" for a solar system demo. Everything else (a velocity component, a
quaternion term, an angular rate) is a much smaller number across every game this engine has, so float32 keeps it
cheap without losing anything real.

### Adds and removes

JSON, broadcast or per-viewer depending on the audience the add/remove happened under (see below):
`{v, type: 'add', nodes: [...]}` (one node or a whole newly-added subtree, parents before children, in the snapshot's
node shape) and `{v, type: 'remove', ids: [...]}`. A node leaving the scene tree (`removeChild`, `dispose()`)
auto-removes it from every viewer who could see it - a game does not track "who has this node" by hand, as
`RocksGame::leave()` and the ragdoll-timeout coroutine do today.

### Clock sync

Both games already do the same ping/pong, answered by the socket worker directly (it holds the snapshot's `epoch`,
needs nothing from the simulating worker): `{v, type: 'ping', c}` -> `{v, type: 'pong', c, s, w}` (`c` echoed back,
`s` = server time since epoch, `w` = the answering worker's pid). This becomes the one ping/pong shape for every
game.

## Per-viewer audience and interest

Every call that adds, removes, or updates something for viewers takes (or defaults) an **audience**: everyone, or
one specific `Viewer`. The common case - a rock, a planet - is "everyone," encoded once and published on the game's
shared `out` channel, exactly as today; a private node (a player's own health bar) reaches only that one viewer's
channel. Every viewer gets its own channel from the start (as today, one per client), even though nothing uses it
for anything but relaying the shared broadcast yet - adding private nodes later must not change the channel
topology.

Separately, each `Viewer` decides **interest**: `Viewer::interestedIn(Node3D $node): bool`. This is a method to
override on a `Viewer` subclass (or an interface a game's own viewer class implements), not a closure stored per
viewer - a closure per viewer is exactly the per-instance allocation (and, if it captures `$viewer` itself, the
reference cycle) this engine avoids everywhere else (see `Space`'s struct-of-arrays, `Pool\Poolable`). The default
implementation returns true always (no filtering - every viewer sees every node, as both demos do now); this is the
seam "nearby only" (interest management for a crowded open world) plugs into later without another protocol version.
A global (everyone-audience) description batch is still encoded exactly once per tick and shared across every viewer
whose `interestedIn()` currently admits each record - the hook decides *inclusion*, not *encoding*.

## Workers

Unchanged: the simulating worker (the one holding the game's lock, as `RocksGame::ensureRunning()` already elects)
owns the `World` and its `View`s, and is the only process that calls `publish`. The four socket workers continue to
`subscribe` to a game's channels and relay bytes to their own browsers' WebSockets, answering ping/pong locally -
`swerve.php` does not change shape, it just stops branching on which game's wire format it is relaying.

## Server API (sketch - signatures will move during the build)

```
$view = $world->view($scene);                 // one per Scene3D, lazy, like $world->physics($scene)

// nothing else to call for "everyone" visibility: whatever is in $scene is what every viewer gets.
// a mesh, a light, a label - add it to the tree and it is shown; remove it and it is gone for every viewer.

$viewer = $view->join($clientChannel);         // called by the swerve glue when a client joins
class MyViewer extends Phame\Net\Viewer {
    public function interestedIn(Node3D $node): bool { ... }   // default: always true
}
$view->addTo($viewer, $privateHudNode);        // a node only $viewer ever sees
$view->removeFrom($viewer, $privateHudNode);
```

`Phame\Net\Transport` is the seam Swerve sits behind: `publish(string $channel, string $bytes)` and
`subscribe(string $channel): iterable<?string>` (null entries are heartbeats, as Swerve's own `subscribe()` already
yields). `View` takes one in its constructor; a `SwerveTransport` adapter (outside `src/`, since the engine must not
depend on Swerve) is the only place `Swerve\Swerve` is mentioned.

## Porting the two demos

`RocksGame` builds `Mesh`/`PointLight`/`Label` nodes instead of calling `setVelocity`-adjacent ad hoc fields and
hand-rolling `snapshot()`; its collision handlers are untouched. `SolarGame` does the same for its ~600 nodes.
Both games' hand-written `flush()`, `snapshot()`, `announce()`, and channel-naming code is deleted. The existing
browser client pages are not kept working through the port - they were built on each demo's own ad hoc wire format,
which this plan retires outright, not freezes; they get rebuilt on the shared runtime (`phame.js`) once that lands,
and are expected to be broken in the meantime. No adapter, no legacy path, no query-parameter version switch: an
engine that has to keep an old wire format alive for a demo it is replacing is an engine still shaped by that demo.

**An existing engine API that exists only for this reason:** `Node3D::$published`, `$publishSlot`,
`World::descriptionChanged()`, and `World::takeChangedDescriptions()` (already marked `@internal`, but called
directly today by both games' flush loops, and by tests that exercise exactly that call pattern - see
`PhysicsTest::testEveryChangeInATickIsPublishedWithItsOwnDescription`, `WorldTest`). It has no reason to be something
a game calls at all: `View` owns it entirely - setting `$published` and reading `takeChangedDescriptions()` becomes
`View`'s own private bookkeeping, driven by which nodes are in a viewed scene, not a primitive a game assembles its
own batching from. Once `View` lands, no code outside `src/World.php` and the new `src/Net/` should reference these
four names; the tests that exercise the mechanism move to exercise `View` instead, or stay as `World`-level tests of
a mechanism `View` happens to be built on - not as something game code is expected to use.

## Deferred, not designed here

Interest management past the always-true default; a wire format for level-of-detail (a planet's rings need not
update once an hour from 50 AU away); multiple `Scene3D`s shown through one `View`; reconnection resuming mid-stream
instead of a fresh snapshot; binary (not JSON) geometry/material data in the snapshot, if that turns out to matter
for a scene with many large custom meshes; inline texture pixel data (every texture today is a reference). The
glTF loader itself (parsing a `.glb`/`.gltf` into `Geometry`/`Material`/`Texture` with `$source` set, skins,
animation clips) is not built now - only the protocol's and `View`'s ability to carry `{id, url}` in place of inline
data, so loading glTF later needs no protocol version bump either. None of these need a version bump to add, which
is the point of the audience, interest, and asset-reference seams above existing from day one.
