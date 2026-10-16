# Phame

**An experiment: a game engine in PHP, built to stress-test [phasync](https://github.com/phasync/phasync) and
[Swerve](https://github.com/phasync/swerve).**

Phame is a general-purpose game engine written in PHP 8.4+: a scene graph, an event-driven physics engine, a native
OpenGL renderer through FFI, and a server that shows a live world to any number of browsers. We are not claiming
that PHP is a good language for game engines. We are building one because a game server is about the most demanding
long-running workload we could think of for an async PHP runtime: thousands of concurrent coroutines, sockets to every
player, hard timing, constant allocation pressure, and no tolerance for a stalled event loop. Whatever breaks in
phasync or Swerve under that load gets reported upstream and fixed, and we will write up honestly whether PHP turns
out to be suitable.

Live demos (a development view; expect them to change and occasionally break):
[frode.ennerd.com/phame](https://frode.ennerd.com/phame/)

- **Rocks in a box:** 700 rocks and glass marbles, simulated on the server in PHP, rendered in every visitor's
  browser. Double-click to throw a ragdoll into them.
- **Solar System War (observer mode):** the solar system as it is right now, computed from published ephemerides:
  planets, moons and the asteroid belt in their real positions, with lunar eclipses where and when they actually
  happen.

## The approach

### Motion is described, not stepped

Most engines simulate by stepping: every frame, integrate velocities into positions, then look for objects that
overlap. Phame instead gives every object a **motion description**: where it is at time t0, how fast it moves and
spins, and its acceleration. Its position at any later time is a formula, not accumulated state. The engine computes
the exact moment that description stops being true (a collision, a wall, a joint reaching its limit) and only then
does any work.

Consequences:

- **Collisions are exact.** The moment two moving spheres touch is the root of a quadratic (a quartic under different
  accelerations). A bullet cannot pass through a target between frames, because there are no frames.
- **Idle costs nothing.** A world where nothing collides does no work; cost follows events, not time. 700 rocks
  colliding in a box cost about 1.6% of one CPU core.
- **No drift.** Positions are evaluated from their descriptions, never summed up step by step, and the result does not
  depend on frame rate.
- **Networking is cheap and smooth.** The server sends a description only when it changes (a few dozen bytes); every
  client evaluates the same formula at its own frame rate. The rocks demo runs on roughly 12 KB/s per viewer, without
  interpolation buffers or extrapolation guesses.

### Motion lives in a tree

Descriptions are relative to a parent node, so complex motion is built from simple pieces: a limb turns on its joint,
the joint rides the torso, the torso flies through the air. The Moon rides an orbit frame that turns around Earth's
anchor, which moves around the Sun. The relative motion of any two nodes is found through their common ancestor, and
the tree also tells the engine how complex that relative motion is (straight, an arc, or nested turns), and so how
long a simple description of it stays accurate.

### Descriptions carry their own validity

A description knows how long it holds (`validUntil`). The engine refits it when that time comes. A planet can be
described as a straight line for an hour, a fast moon by a turning frame, and both are refit from real orbital
formulas, each on its own schedule, chosen from an error budget. The same mechanism is how the physics plan handles
gravity sources, joints under load and anything else that is "almost linear for a while".

### Game logic is straight-line code

There is no `update()`. Every behaviour is a phasync coroutine owned by the object it is about: it waits for game
time, events or other coroutines, and ends (running its `finally` blocks) when its object is removed. See
[OVERVIEW.md](OVERVIEW.md) for how this compares to Unity, Godot, LÖVE and KorGE.

### The browser is a viewer

The server owns the world. `Phame\Net\View` streams the scene graph to browsers: meshes, materials and lights once,
then description changes as they happen, with per-viewer channels for private state (a HUD) and interest management.
A shared browser runtime renders it; games write no networking or rendering code of their own.

## Compared with other engines

|  | Stepped engines (Unity, Godot, Unreal; PhysX, Jolt, Box2D) | Phame |
|---|---|---|
| Simulation | integrate every body every tick (30-120 Hz) | compute the next event of each description, sleep until then |
| Collisions | overlap tests per tick, plus continuous detection for fast objects | exact contact times, no tunnelling by construction |
| Cost | bodies × ticks, idle or not | proportional to events |
| Networking | snapshots at a tick rate, interpolation and client prediction | descriptions on change, evaluated exactly by every client |
| Determinism | depends on step order and floating point accumulation | positions are pure functions of time |
| Strong at | dense contact: stacks, piles, vehicles, cloth | sparse and fast motion: projectiles, orbits, open worlds, many idle objects |
| Weak at | precision over long times and large distances | sustained contact, which is planned as stepped islands inside the event-driven world |

The idea is old in physics (event-driven molecular dynamics dates from 1957; kinetic data structures from the late
1990s), but rare in games, because resting contact and complex shapes break the pure model. Phame's plan
([docs/PHYSICS-PLAN.md](docs/PHYSICS-PLAN.md)) handles those with contact joints, stepped islands for ropes and cloth,
and a native kernel behind a narrow contract: given shapes moving linearly for a known time, report the first contact.

## Why PHP, then?

Again: this is a stress test, not a recommendation. That said, the reasons it is not absurd:

- **The event-driven model keeps the hot paths small.** An engine that wakes only on events does far less per-frame
  arithmetic than a stepped one, which narrows PHP's gap to compiled languages where it matters.
- **A game server is mostly coordination.** Sockets to every player, timers, pub/sub between worker processes,
  per-player state: this is the workload phasync and Swerve are built for, and where PHP's ecosystem is strongest.
- **Heavy numerics can move out.** FFI already drives OpenGL; the physics kernel contract is designed so that a C or
  GPU implementation can take over the inner loops without changing the PHP side.
- **PHP 8.4 is fast enough to find out.** The tracing JIT, typed properties and FFI make it worth measuring instead of
  assuming.

What we have learned so far about running PHP this way is recorded in [MANUAL.md](MANUAL.md): allocation is cheap but
garbage-collector roots and reference cycles are not, so hot paths use pooled objects and struct-of-arrays data, and
owners take apart what they dispose of so that nothing waits for the cycle collector.

## What it has found so far

Phame has already exposed real problems in its dependencies, each reported with a reproduction and fixed upstream: a
subscriber-list leak in phasync that ended in a worker segfault under load, cleanup lost when a coroutine was
cancelled inside `finally`, garbage-collector pauses in long-running servers, and missing raw publishing and timed
waits in the APIs a game server needs.

## Status

Working:

- Event-driven physics for spheres, walls, tethers and compound bodies with turning joints; constant acceleration,
  gravity fields and validity windows.
- A verification suite: closed-form cases, conservation and invariance laws, a reference simulator that shares no code
  with the engine, open-world scenes with bullets, and mutation runs that plant bugs to prove the tests catch them
  ([docs/VERIFICATION.md](docs/VERIFICATION.md)).
- The remote view protocol ([docs/REMOTE-VIEW.md](docs/REMOTE-VIEW.md)), multiple worlds in one process, benchmark
  scenes (`php bench/run.php`).
- A 2D and 3D client engine with a Starling-style display list, shaders, filters, particles, audio and text
  ([docs/ENGINE.md](docs/ENGINE.md)).

Not yet: joints and bodies as engine concepts, resting contact, shapes other than spheres, the shared browser runtime,
model loading (glTF). The order and the gate each step must pass are in [docs/PHYSICS-PLAN.md](docs/PHYSICS-PLAN.md).

## Trying it

```sh
composer install
vendor/bin/phpunit            # the test suite, headless
php bench/run.php             # benchmark scenes
php examples/shooter.php      # a 2D game with coroutines (needs SDL2)
```

The demo server is in `server/` (`server/run.sh`), running on [Swerve](https://github.com/phasync/swerve).

Requires PHP 8.4 or later with FFI. Developed and tested on Linux.

## License

MIT, see [LICENSE](LICENSE).
