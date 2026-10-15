# Phame: an overview

Phame is a 2D game engine for PHP 8.4+, with a small 3D scene embedded in it. It draws through OpenGL 3.3 over FFI, with SDL2 as the only native dependency. The rendering design follows Starling, the AS3 framework: a display list (Stage, Sprite, Quad, Image, BitmapText, MovieClip, TileMap…) sits on a batching painter, which sits on thin GPU wrappers.

The rendering side is conventional. The interesting part is how game logic is written. Phame has no `update()` methods and no entity lists. Every behaviour is a phasync coroutine, and that coroutine belongs to the object it is about.

## How it works

**Game logic is straight-line code.** A behaviour is a coroutine, written as an ordinary loop that waits:

```php
$enemy->go(function () use ($enemy, $player) {
    while (true) {
        Async::wait(0.8);                                        // hover
        Async::tween($enemy, 0.5, ['x' => $player->x]);          // lunge
    }
});
```

**Objects own their coroutines.** `$object->go($fn)` starts a coroutine owned by that object. It ends when the object leaves the stage, is disposed, or `cancelCoroutines()` is called. Its `finally` blocks always run, so cleanup goes where the code is:

```php
$boss->go(function () use ($boss) {
    try {
        while ($boss->hp > 0) { /* chase, burst fire, … */ }
    } finally {
        explode($boss);   // also runs when the boss is removed from the stage
    }
});
```

Ownership follows the object, not the caller. A bullet that the player's coroutine fires belongs to the bullet, so it keeps flying after the player dies. Everything a coroutine starts (helper coroutines, `race()` branches, library code) belongs to the same owner.

Under the hood, each object that calls `go()` gets a spawner. A spawner is a coroutine with a phasync Frame of its own: a nested `run()` that starts closures from a queue. To remove an object, Phame cancels that frame, which unwinds everything under it.

**Waiting costs nothing.** `Async::wait()`, `tween()`, `awaitFrame()`, `awaitEvent()`, `awaitKey()` and `awaitTouch()` park the coroutine on a phasync flag. The juggler (Phame's timer and tween scheduler) or the event system raises the flag. Nothing polls a sleeping coroutine every frame.

**All waits run on game time.** There is one clock: `$engine->clock`, with `time`, `delta`, `timeScale` and `paused`. Pausing or slowing the game pauses or slows every coroutine, every tween and every delayed call, without any code of their own.

**Racing and timeouts.** `Async::race(...$branches)` runs branches concurrently. It returns the first result and cancels the losers; their `finally` blocks run, and anything they started ends with them. `Async::timeout($s, $fn, $default)` is a race against a wait:

```php
$key = Async::timeout(2.0, fn () => Async::awaitKey());   // a KeyEvent, or null after 2 s of game time
```

**Clean exits without exceptions.** `Async::cancelled()` is a cheap check (phasync's `Frame::isCancellationRequested()`) for loops that would rather stop on their own than be unwound by an exception. An object that removes itself finishes its current step. It is only hard-cancelled at the next frame if it is still running.

**Input can be simulated.** `simulateKey()` and `pointer()` inject input exactly as the window would. Combined with a headless SDL driver and a forced frame delta, bots can play the game deterministically in tests. The GL tests render headless and assert on the pixels read back.

## Patterns

These are taken from `examples/shooter.php` and `examples/platformer.php`.

- **One behaviour, one coroutine.** The player has two: one moves, the other fires every 0.12 s. Each is a few lines and knows nothing about the other.
- **Fire and forget.** A bullet is created, given a coroutine that moves it and checks hits, and removes itself at the edge. Nothing else keeps track of bullets.
- **Behaviours as closures.** Enemy types are a table of closures (`diver`, `weaver`, `hunter`) handed to `$enemy->go(...)`. A new enemy type is a new closure.
- **Waves as a sequence.** A single coroutine is the level script: spawn N enemies with a pause between them, wait until the sky is clear, then start the next wave. It reads like a screenplay.
- **Effects own their lifetime.** Floating score text tweens upward, fades, and removes itself. Screen shake is a coroutine that decays and then resets the camera.
- **Restart by removal.** `$world->removeChildren(dispose: true)` ends every coroutine of the previous round. No reset code is needed.
- **Cancel groups.** A plain `new EventDispatcher()` can own coroutines, such as a wave of enemies, so they can be cancelled together.
- **Waiting for something specific.** `awaitEvent($clip, Event::COMPLETE)` waits for an animation to end. `awaitEvent($voice, Event::COMPLETE)` waits for a sound. `awaitEvent($player, Event::REMOVED_FROM_STAGE)` then `awaitKey(Key::SPACE)` gives game over, then restart.

## Is it like Lua?

The coroutines are similar. What Phame builds on them is not.

**Same as Lua.** phasync coroutines are PHP Fibers, which are stackful, like Lua coroutines. A deeply nested function can wait without every level of the call stack taking part. That is an advantage over generator-based systems such as Unity's `IEnumerator` coroutines or Python generators, where every level has to `yield`.

**What Lua (and LÖVE) leave to you.** Lua gives you `coroutine.resume` and `coroutine.yield`, and that is all. LÖVE and Defold games write their own scheduler, or use a small library. Neither has:
- an owner whose removal ends the coroutine, with guaranteed cleanup;
- cancellation that spreads to everything a coroutine started;
- race or timeout as built-ins;
- one game clock that every wait obeys.

Lua 5.4's `coroutine.close` with to-be-closed variables comes closest to the cleanup part.

**Compared with other engines:**
- **Unity coroutines:** belong to a MonoBehaviour and stop when it is destroyed, which is like Phame's ownership. But `StopCoroutine` does not run `finally` blocks, and nesting means yielding at every level. UniTask with CancellationTokens is closer to Phame, but cancellation there is manual plumbing.
- **Godot 4 `await`:** awaiting signals is pleasant, but it has no cancellation. A coroutine awaiting on a freed object is a known source of errors.
- **KorGE (Kotlin) and similar:** Kotlin's structured concurrency gives the same guarantees: a scope cancels its children, `finally` runs, and `withTimeout` and `select` exist. KorGE is the closest relative. Phame does not invent a new concept.

**What Phame adds.** It ties structured concurrency to the display list:
- removing an object from the stage is the cancellation;
- ownership follows the object, not the code that called `go()`;
- every wait obeys the game clock, so pause and slow motion are free;
- tweens, events and timers are all awaitable at no per-frame cost.

The result is game code with no update loops, no entity bookkeeping and no cleanup code. It is probably the cleanest structured-concurrency game API in a scripting language.

It also shows that PHP, with phasync, can do this at all: frame-paced, allocation-free hot paths, GPU batching, and thousands of concurrent coroutines.

## Limits

These are worth knowing before judging it.

- **Speed.** About 2.7 µs per animated sprite on the development machine, roughly 5,000 sprites at 72 fps. The cost is PHP-side (matrices, vertex packing), not GPU. The JIT helps with 3D math.
- **Single-threaded, Linux-tested only.** Headless rendering depends on SDL's offscreen driver.
- **Hard cancellation costs more than a request.** Measured on phasync main: cancelling its own frame takes about 10 µs, `requestCancellation()` about 4 µs. A full owner lifecycle is about 83 µs with a request and about 276 µs with a hard cancel, because the exception unwinds a nested `run()`. Phame therefore prefers requests and hard-cancels only stragglers.
- **Still young.** It has no clipping rectangles, shadow maps, model loader, gamepad input or compressed audio yet (see the README's roadmap).
