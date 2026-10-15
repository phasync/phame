# Phame

A 2D game engine for PHP 8.4+, built on [phasync](https://github.com/phasync/phasync) and FFI, composited on the GPU with OpenGL 3.3 core. The design follows [Starling](https://github.com/Gamua/Starling-Framework) for AS3: a display list on top of a batching painter on top of thin GPU primitives.

```php
$engine = new Phame\Engine(800, 600, 'Hello');
$engine->run(function ($engine) {
    $box = new Phame\Display\Quad(50, 50);
    $engine->stage->addChild($box);
    while (true) {
        Phame\Async::tween($box, 1.0, ['x' => 700.0], Phame\Animation\Transitions::EASE_OUT_BOUNCE);
        Phame\Async::tween($box, 1.0, ['x' => 0.0]);
    }
});
```

Coroutines are ordinary phasync coroutines. `Async::awaitFrame()` returns the frame's game-time delta in seconds; the global clock is `Engine::current()->clock`. `Async::awaitFrame()`, `wait()`, `tween()` and `awaitEvent()` suspend them without any per-frame polling cost, and everything still running when the game quits is cancelled (its `finally` blocks run).

## Game logic as straight-line code

There is no `update()`. A sequence is a coroutine, and a coroutine belongs to the object it is about:

```php
$boss->go(function () use ($boss, $player, $stage) {
    try {
        while ($boss->hp > 0) {
            Async::tween($boss, 1.5, ['x' => $player->x]);          // chase
            for ($i = 0; $i < 3; ++$i) {                            // burst fire
                fireAt($boss, $player);
                Async::wait(0.2);
            }
            $key = Async::timeout(2.0, fn () => Async::awaitKey());   // a KeyEvent, or null after 2 s of game time
        }
    } finally {
        blowUp($boss);                                             // runs when the boss is removed from the stage, too
    }
});
```

- `go()` on a display object, a `Node3D` or any `EventDispatcher` (use a plain `new EventDispatcher()` as a cancel group for a wave of enemies) starts a coroutine that ends when the object leaves the stage (a `Node3D`: leaves its parent), is disposed, or `cancelCoroutines()` is called; `finally` blocks run. Reparenting removes first, so it ends them too.
- Ownership follows the object, not the code: everything an object's coroutine starts (`Engine::go()`, `phasync::go()`, race branches, library coroutines) belongs to that object, but `$bullet->go()` called by the player belongs to the bullet, so the bullet keeps flying when the player dies. Each object that uses `go()` gets a spawner coroutine with a phasync frame of its own; its coroutines start within the same frame, right after the caller next waits. A coroutine that removes its own object finishes what it is doing; if it is still running at the start of the next frame, it is cancelled then (no exception is created for the usual case of removing yourself and returning). `Async::cancelled()` is a cheap check for loops that prefer to stop cleanly instead of being unwound by an exception.
- `Async::wait`, `tween`, `awaitFrame` (returns the frame's delta), `awaitEvent($dispatcher, $type, $accept)`, `awaitKey(Key::SPACE)`, `awaitTouch($object, $phase)` and `race(...$branches)` / `timeout($seconds, $fn, $default)` suspend at no cost while waiting, in game time: pause and `timeScale` apply to every coroutine. A waiter gets the first matching event even if several arrive in one frame.
- `Engine::isKeyDown(Key::LEFT)` is the held-key state for continuous movement; `Engine::simulateKey()` presses keys for bots, replays and tests:
  ```php
  $engine->go(function () use ($engine) {   // hold left for a second
      $engine->simulateKey(Key::LEFT, true);
      Async::wait(1.0);
      $engine->simulateKey(Key::LEFT, false);
  });
  ```
- `$object->removeFromParent()` takes an object off the display list (and ends its coroutines).
- Wake only when needed: `velocityX`/`velocityY` make the engine move an object, and `Async::until($condition)` lets the engine check a condition every frame without waking the coroutine; it returns the condition's value. `$bullet->velocityY = -600; $hit = Async::until(fn () => $bullet->y < -10 ? true : $bullet->firstOverlap($enemies));` Conditions must only test (collision boxes are cached while they run), and several coroutines can see the same hit in one frame.
- `$a->overlaps($b)` and `$a->firstOverlap($group)` compare screen boxes across parents, without allocating. `Engine::pointer($x, $y, $phase)` injects mouse/touch input the same way `simulateKey()` injects keys.
- `TileMap` builds a level from a text map (one character per tile), draws only the visible tiles in one batch, and moves bodies with tile collision: `$hit = $map->move($player, $dx, $dy)` returns the sides it was stopped on, without tunnelling. `isOnGround()`, `take($body, 'o')` for pickups, `tileAt()`, `setTile()`.

`examples/shooter.php` is a complete arena shooter (player, three enemy behaviours, waves, collisions, score, particles, screen shake, floating score text, pause, restart) with no entity lists and no update methods. `examples/platformer.php` does the same for a platformer: tile collision, coyote time, a moving platform, patrolling enemies, a following camera.

## Layers

Each layer only knows the ones above it in this list.

| Layer | Namespace | Role |
|---|---|---|
| Platform | `Phame\Platform` | SDL2 window, GL context, event queue. The only code that is platform specific. |
| GL | `Phame\GL` | `GL`: the GL 3.3 core function table, loaded through a proc-address closure (it has no other dependency). `Shader`, `Buffer`, `VertexLayout`, `VertexArray`, `Texture2D`: one class per GL object. |
| Rendering | `Phame\Rendering` | `QuadBatch` (8192 quads per draw call) and `Painter` (state stack, blend modes, batching). |
| Math, Pool | `Phame\Math`, `Phame\Pool` | `Vector3`, `Matrix4`, `IntRect`: pooled, mutable, typed value objects (see Allocation). |
| Scene | `Phame\Scene` | The 3D scene graph: `Node3D`, `Camera`, `Mesh`, `Geometry`, `Material`, and `Scene3D`, which embeds a 3D scene in the 2D display list. |
| Display | `Phame\Display` | `DisplayObject`, `DisplayObjectContainer`, `Sprite`, `Quad`, `Image`, `BitmapText`, `ParticleSystem`, `TileMap`, `MovieClip`, `Stage`. |
| Filters | `Phame\Filters` | `Filter` (in `Rendering`) and `BlurFilter`, `GlowFilter`, `BloomFilter`, `ColorMatrixFilter`. |
| Events, Animation | `Phame\Events`, `Phame\Animation` | Bubbling events, touch, `Juggler`, `Tween`, `DelayedCall`, `Transitions`. |
| Engine, Async | `Phame\Engine`, `Phame\Async`, `Phame\Clock` | The coroutine frame loop, the awaitable primitives, and the global clock (`$engine->clock`: `time`, `delta`, `timeScale`, `paused`, `realDelta`, `frame`). |

## 3D

`Scene3D` is a display object. Put it in the stage like a `Quad`; it draws a lit, depth-tested scene into its rectangle, and 2D objects can sit above and below it. `examples/cube.php` shows it with coroutines driving the animation.

- Right-handed, y up, the camera looks down -z. Rotations are radians, applied to a point in the order z, x, y.
- Opaque meshes are drawn first, transparent ones (`Material::$alpha < 1`) back to front without writing depth. Back faces are culled unless `Material::$doubleSided`.
- One directional light plus ambient (`Scene3D::$lightDirection`, `$lightColor`, `$ambient`); `Material::$unlit` skips lighting. Vector properties such as `lightDirection` are readonly references to pooled objects: change them in place, `$scene->lightDirection->set(1, -1, -1)`.
- `Geometry` takes interleaved position, normal and uv floats and 16 bit indices (at most 65536 vertices); `box`, `plane` and `sphere` are provided.
- `Node3D::forward($v)`, `right($v)` and `up($v)` give a node's (or the camera's) axes in world space, for camera-relative movement.
- Picking: `Scene3D::pick($x, $y)` returns the nearest visible `Mesh` under a point in the scene's local 2D coordinates (tested against bounding boxes, so a rotated mesh is a little generous); `ray()` fills an origin and direction for you. `Mesh::worldBounds()` and `Mesh::intersects()` give the world-space box and box-vs-box overlap; `Node3D::findByName()` searches descendants.
- A `Scene3D` is drawn into the screen-aligned box of its rectangle: do not rotate it, and its alpha is ignored.

## Shaders and effects

`examples/showcase.php` uses everything below at once.

- **Mesh shaders.** `new Material(shader: new MeshShader($glsl))`. Your GLSL defines `vec4 shade()` (straight RGBA) and optionally `vec3 displace(vec3 pos, vec3 normal, vec2 uv)` for waves and wobble; `uTime`, `sceneLight(n)`, `sceneSpecular(n)` and `flatNormal()` are provided. Extra uniforms go in the `uniforms` closure. The standard material is itself a `MeshShader` (`MeshShader::STANDARD`). `Geometry::plane($w, $d, $segments)` subdivides for displacement.
- **Lighting.** Up to 4 `PointLight`s (colour, intensity, range) next to the sun, Blinn-Phong `Material::$specular`/`$shininess`, `$emissive`, and linear fog (`Scene3D::$fogColor`, `$fogNear`, `$fogFar`).
- **Render textures.** `new RenderTexture($w, $h, $scale)` then `$rt->draw($displayObject)`; `$rt->texture` is an ordinary texture for an `Image` or a material, and may itself contain a `Scene3D` (it has a depth buffer). `readPixels()` reads it back. Nesting works.
- **Filters.** `$object->filter = new BlurFilter(6)`: the object and its children are drawn into a texture and that goes through the filter's passes. Write your own with `new Filter($glsl, $passes)`, where the GLSL defines `vec4 filterPixel(vec2 uv)` and may read `uTexture`, `uOriginal`, `uTexelSize`, `uTime`, `uPass`; return premultiplied colour. Filters work on a `Scene3D` too. A filtered object costs two or three texture-sized draws, so use them on a few objects, not on every sprite.
- **Particles.** `ParticleSystem` is a pooled, array-free emitter drawn as one batch: `start()`, `burst($n)`, `emissionRate`, `lifetime`, `speed`, `spread`, gravity, start/end size, colour and alpha.
- **Animation.** `TextureAtlas::fromSparrow($xml)` (TexturePacker and friends) or `TextureAtlas::grid($sheet, $w, $h, ['idle', 'walk'])` (one animation per row: `frames('walk_')`), then `new MovieClip($atlas->frames('run_'), 12)`. It plays in game time; assign `frames` to switch animation, set `loop = false` and `Async::awaitEvent($clip, Event::COMPLETE)` to wait for the end.
- **Audio.** `Sound::load('hit.wav')->play($volume)` returns a `Voice` (`stop()`, `volume`, `loop`) that dispatches `COMPLETE`, so `Async::awaitEvent($voice, Event::COMPLETE)` waits for it. Any WAV format is converted on load; `Sound::tone(900, 0.06, 'square', 300)` makes a beep without asset files. Mixing is done by SDL every frame (no extra native library); headless engines mix silently on game time.
- **Text.** `BitmapText` draws BMFont atlases (æøå included in the shipped DejaVu Sans). Make others with `bin/make-font font.ttf 32 name`.

Premultiplied alpha is the rule: every blend mode assumes the shader outputs premultiplied colour, and render textures are stored that way.

## Allocation

The hot paths allocate no arrays and no objects. `Vector3`, `Matrix4` and `IntRect` have private constructors and live in pools: take one with `Vector3::get(...)`, change it in place (operations return `$this`), and `release()` temporaries. A recycled instance holds stale values, so the factories reinitialise everything; releasing twice throws. Matrices returned by `Node3D::getLocalMatrix()` and `getWorldMatrix()` belong to the node: copy them if you need to keep them. The 2D `Matrix` and `Rectangle` still allocate and are not used per frame by the renderer.

## Platforms

SDL2 is the only native dependency, and GL entry points are resolved through `SDL_GL_GetProcAddress`, so no GL library is linked. The SDL library is found per OS (`libSDL2-2.0.so.0`, `SDL2.dll`, Homebrew or system `libSDL2-2.0.0.dylib`); set `PHAME_SDL2` to a path to override. macOS gets the forward-compatible flag it requires for core profiles.

Only Linux has been tested. Headless rendering (tests, screenshots) uses SDL's `offscreen` video driver, which may not exist on other platforms, where the GL tests skip themselves.

Porting to another windowing library means replacing `Platform\SDL` and `Platform\Window`, and passing that library's proc-address function to `new GL(...)`.

## Performance

See [MANUAL.md](MANUAL.md#performance) for measurements on a 2025 and a 2014 CPU; `php examples/benchmark.php` measures your own machine.


`php examples/bench.php` measures animated, textured sprites per frame on the machine running it. On the development machine (PHP 8.5, NVIDIA GPU) the cost is about 2.7 µs per sprite, which is roughly 5,000 sprites at 72 fps and 10,000 at 38 fps. The time is spent in PHP (matrix updates and vertex packing), not in the GPU.

On the development machine (2013-era CPU), pooled `Matrix4::product` takes 0.84 µs, `invertFrom` 1.5 µs and `compose` 0.66 µs; with `opcache.enable_cli=1 opcache.jit=tracing` they take 0.28, 1.4 and 0.40 µs. Enabling the JIT is the cheapest speed-up for 3D scenes with many nodes.

## Tests

`vendor/bin/phpunit`. The GL tests render headless and assert on pixels read back from the GPU.

## Roadmap

Clipping rectangles, trimmed atlas frames, shadow maps (needs depth textures), a model loader, skybox, OGG/MP3 decoding, gamepad and touch input.

## Known limitations

- Straight-alpha textures with linear filtering can show a dark fringe at edges. Textures are premultiplied by the shader, not on load.
- `width`/`height` setters change only `scaleX`/`scaleY`, as in Starling, which has surprising results for rotated objects.
- The PNG decoder does not support interlaced images.
