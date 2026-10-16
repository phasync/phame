# Verification

Phase 0 of docs/PHYSICS-PLAN.md: a reference oracle that shares no code with the engine, checked against today's
engine before anything in the plan is built on top of it. The oracle lives in tests/Verification/Oracle, the random
scene generator and comparison harness in tests/Verification, and the tests themselves in
tests/Verification/VerificationTest.php.

## What the oracle covers

A dense fixed-step simulation of spheres, axis-aligned box walls, tethers, and trees of nodes that turn at constant
rates about axes in their parent's space - the same vocabulary `Physics\Space` understands, built with its own math
(3x3 rotation matrices, not quaternions; its own impulse formulas, not `Physics\Contact`). Positions and orientations
are always exact closed-form functions of time (straight lines between velocity changes, exact rotation matrices from
a spin schedule); the step size only controls how finely new contacts are searched for. All parts are checked against
all parts - no grid, unlike the engine's cells.

Finding a crossing within a step is not a plain two-point sample: `Oracle::findCrossing()` does conservative interval
root-isolation, given a safe bound on how fast each pair's gap can change (exact for two free bodies; an over-estimate
for a compound part, from its own translation plus every ancestor's angular speed times a lever long enough to cover
any of them). A sub-interval is only trusted whole when the smaller of its two end gaps, minus the bound times its
width, is still positive; otherwise it is halved and each half checked the same way, down to machine precision. This
is what a plain "sample dt apart" oracle cannot do: at 500+ units/second a bullet crosses a 0.01-radius body in under
2.6 units worth of a 0.0131s step, invisible to its two endpoints alone, and a graze (where the true dip is far
narrower than the step and the true closing rate near it is far below the bound, nearly all of it tangential) is
exactly the case a naive "step by gap / speed" march gets stuck on, taking ever-smaller steps that approach the dip
but never reach it - interval isolation halves its way past that instead.

A compound's parts are treated as kinematic: a contact can change a free body's velocity, never a part's own tree
motion. This is deliberate, not a missing feature - see "Compound contacts" below for why.

`SceneGenerator` produces a `SceneSpec` (a struct of arrays: a scene description with no `Phame` classes in it) for
four kinds of scene, each from a seed alone:

- `spheresInBox` - random spheres in a confined box.
- `tetheredChain` - a chain of tethered spheres.
- `compoundWithTurningArms` - a spinning compound with turning arms and free spheres passing through, one aimed to
  be sure to touch.
- `openWorld` - no walls, sparse placement over a large volume (2000+ units), radii log-uniform from 0.01 to 1000,
  most bodies drifting at a walking pace and a couple of "bullets" at 200+ units/second, one bullet aimed to be sure
  to meet a target. This exercises large coordinates and large speed ratios, which the rocks-in-a-box demo never
  does.
- `bulletThroughTinyBody` (dead-centre and grazing), `bulletThroughThinCompoundPart`, `twoBulletsCrossing` - built to
  expose tunnelling if `findCrossing()` ever regresses to a plain two-point sample: run at a deliberately coarse
  dt = 0.05 (vs. the usual 0.0131) in `VerificationTest`, where a naive check would miss every one of them.

`EngineBuilder` builds the engine's own scene from the identical `SceneSpec`. `Harness` runs the engine (applying the
same elastic/wall/tether response rules the oracle uses - the engine applies nothing itself, so this is the "game"
half of the comparison) and records every contact and a position snapshot at each sample time; `Harness::compareEvents`
and `Harness::worstPositionError` compare that against the oracle's own run.

## Error budgets

**Contact time and position, free-free pairs, walls, tethers:** 1e-4. These are exact closed-form events on both
sides (the engine predicts them analytically, the oracle finds them by bisection to 1e-13), so the only real source
of error is floating-point accumulation over a run; 1e-4 is generous against the ~1e-11 actually observed.

**Contact time, a compound's part:** 0.05 seconds. `Space::PROBE_TOLERANCE` (0.02 units) means the engine reports a
part touch somewhere in a gap of up to twice that, not at the exact touch; over the closing speeds in these scenes
(a few units/second) that is a few hundredths of a second, hence the budget.

**Position, after a compound's part contact:** not checked. A fast-spinning part can move a long way in
`PROBE_TOLERANCE`'s worth of time (speed = lever x angular rate), so the bounce the engine computes (from the part's
position and velocity at its slightly-early touch) and the oracle's (from the exact touch) start from measurably
different states. That difference does not shrink afterwards - it is carried forward by an otherwise-correct elastic
bounce - so Phase 0 only checks position agreement up to just before the first part contact (tight, 1e-4) and leaves
the post-contact trajectory unconstrained. This is accepted engine behaviour, not a disagreement: see "Compound
contacts" below.

**Velocity:** not checked directly; a wrong velocity shows up as a growing position error, which the position budgets
above already catch.

**The oracle's own error:** since motion is exact between events, halving the step does not change the answer except
when a step was coarse enough to miss a brief graze entirely (a discrete miss, not a smooth integration error).
`testTheOracleConvergesAsTheStepHalves` checks this properly: it runs at dt, dt/2 and dt/4, and requires that halving
again (dt/2 to dt/4) moves the answer much less than the previous halving did (dt to dt/2) - true whether or not a
graze was missed at the coarsest step, and the actual comparisons above use dt = 0.0131s, finer than every step this
convergence check passes at.

**Simultaneous and boundary events:** two independent contacts landing at (numerically) the same instant - a
coincidence in a generated scene's round numbers, or a genuine tie - are both kept, not merged or dropped (see
`Oracle::run()`'s per-window "resolved" bookkeeping). A contact within 1e-6 of the run's end time is excluded from
comparison: whether it counts as "before" or "just after" the end is then a tie decided by which side's floating-point
chain rounds which way, not a real disagreement.

## Compound contacts

Phase 0 found no disagreement between the engine and the oracle once the above budgets - and the oracle's own two
bugs below - were accounted for. The large apparent "disagreement" on compound/part contacts (position error of
several units after a fast hit) is `Space`'s documented PROBE_TOLERANCE approximation doing exactly what it is for,
amplified by a spinning part's speed; it is not filed as a `baseline-failure`.

## Oracle bugs found and fixed while building this

Not engine disagreements - bugs in the oracle itself, caught by comparing against the engine and fixed before relying
on it:

- **Simultaneous unrelated events.** The crossing search used the live cursor time to decide whether a pair counted
  as "freshly separated". Two unrelated bodies reaching their respective walls at the exact same instant (common with
  a generator that only ever produces round numbers) meant whichever was resolved first moved the cursor to that
  instant, making the second event's own gap read ~0 there too, so it was skipped. Fixed by deciding freshness from a
  sample fixed at the start of the whole step, not the cursor (see `Oracle::earliestCrossing()`).
- **Run-boundary ties**, see "Simultaneous and boundary events" above.
- **Tunnelling.** The original oracle only sampled a step's two ends: a fast bullet could cross a thin body with the
  gap positive at both, missing it. Fixed with `findCrossing()`'s conservative interval isolation (see "What the
  oracle covers"), found by a reviewer, not by the comparison itself - the engine's exact quadratic solve never
  tunnels, so a naive oracle and the engine would have quietly agreed on the same miss. `bulletThroughTinyBody` et al.
  (above) exist specifically to keep this caught if it ever regresses.

## Mutation testing

`tests/Verification/mutate.php` applies one deliberate bug at a time to a temporary copy of `src/Physics/Space.php`
(flipping the sign in a quadratic root, skipping the wall check, dropping tether events, a `PROBE_TOLERANCE` boundary
off-by-one, skipping the cell-crossing rescan, using the wrong t0 in a description), points a throwaway bootstrap
file at the mutant instead of the real file (`src/` is only ever read, never written, and the temp directory is
removed on exit, success, failure or Ctrl-C), and runs `tests/Verification` against each. Run it with
`php8.4 tests/Verification/mutate.php` (and `php8.5 ...`).

Of 6 mutants, 5 are killed (one of the tests fails). One survives: changing the `PROBE_TOLERANCE` comparison in
`touching()` from `<=` to `<` - an off-by-one at a boundary so narrow (0.02 units) that landing on it exactly is
essentially never hit by a random or hand-built scene. Reported here, not yet turned into a test.

## Phase 1

Acceleration in descriptions, a validity end, gravity as a frame field (docs/PHYSICS-PLAN.md, "Phase 1"). The gate
tests live in `tests/Verification/PhaseOneTest.php`, under `#[Group('phase1')]` (excluded from the default run in
phpunit.xml, like `soak`): run them explicitly, once told the engine side is ready, with `--group phase1`.

**Oracle/SceneSpec support**, already in place and exercised by the Phase 0 tests too (acceleration defaults to 0.0
everywhere there, so none of them depend on anything Phase 1 added): a body's or node's own constant acceleration
(`SceneSpec::addBody()`/`addNode()`'s trailing `ax, ay, az`), a node's own constant linear velocity (previously always
0, now a part can fall or be thrown within its compound, not just turn), and positions/velocities follow p0 + v0·age +
½a·age² throughout (`Oracle::bodyState()`/`nodeState()`). `findCrossing()`'s speed bound widens by accelBound() x
width (a safe sum of every accelerating ancestor's magnitude, up the chain) on top of the Phase 0 bound, since
velocity is no longer constant within a step - exactly the `|Δv| + |Δa|·width` the plan calls for. No new engine
API was needed to validate this (it is pure Oracle math), but the hand-built near-graze scene (see below) checks it
against a case designed to stress it.

**Gate tests**: a projectile's apex and landing time against the closed form; a ball bouncing under gravity with
heights scaling by e² per bounce (run to 90% of the closed-form time it takes the bounces to pile up to rest, not a
fixed duration - see "Zeno tails" below); two bodies under the same acceleration colliding exactly when they would
without it (gravity cancels in their relative motion); `validUntil` staying put while its parent is accelerated and
spun hard, with `expire` firing exactly once; gravity on a falling compound reaching its part exactly like the
oracle; two free bodies under different (one falling, one not; opposite) accelerations, including a near-graze close
to a true double root (see below); gravity on the scene root (`Node3D::$gravityX/Y/Z`) and the same acceleration
given to every body directly giving identical trajectories (checked against one oracle run, built from the per-body
form); gravity added to `spheresInBox`/`openWorld` (both gained an optional `$gravity` parameter).

**A near-exact double root is ill-conditioned, so the graze test is a near-graze.** Two bodies with constant but
different x-accelerations have a relative x position that is a parabola in time; its vertex is where relative
x-velocity is exactly 0. Choosing the starting separation puts that vertex at relative x = 0 exactly - an exact
tangency if the transverse offset also exactly equals the sum of the radii. That configuration is a genuine double
root, but it is also where a sign change (what both this oracle and a plain root search look for) may never be
observed in floating point: the gap can read a hair positive on both sides of the touch forever, by rounding alone.
`findCrossing()` on an exact tangency like that explores every halving down to BISECT_EPS on both sides near the
touch (neither side can be pruned, since the true closing rate is 0 right at the point) - `isolateBudget` bounds that
to a fixed number of calls, which is correctly "no crossing" for a mathematically exact tangency, but would be a
one-window-late answer for a genuine, exactly-zero-width crossing (a configuration with no physical reason to exist).
The gate test offsets the transverse distance a hair under the radius sum instead, keeping the double root's
numerical character (very low effective closing rate, almost all of it tangential) while making it an unambiguous,
brief, real contact - the same technique as the Phase 0 "graze" tunnelling scenes.

**Zeno tails are physics, not a hang.** A ball bouncing inelastically (e < 1) under gravity on a flat floor bounces
infinitely often before coming to rest, in finite time (resting contact is Phase 4, not modelled here) - the bounce
intervals shrink geometrically, summing to a finite total. A test that just ticks a fixed duration will try to
process that whole infinite tail in one call. The bouncing-ball gate test instead computes the accumulation point in
closed form (first impact at t1, bounce interval shrinking by e each time from T1 = 2·e·v1/g, so the tail after t1
sums to T1/(1 − e)) and runs to 90% of the way there, checking both the heights and that there were as many bounces
as the closed form predicts by then.

**Hang guards.** Every gate test that can re-trigger events (any bounce response) caps the number it will accept and
fails with a clear message instead of hanging if an engine regression re-triggers the same contact forever -
`Harness::run()`'s own two handlers (used by the falling-container, near-graze, root-gravity and generator-gravity
tests) and the three hand-built scenes in `PhaseOneTest` all do this. This exists because of a real regression caught
while writing these tests: `Space::smallestRoot()`'s root acceptance tolerance briefly let a just-resolved wall
contact re-trigger itself every instant, forever, on the simplest possible bouncing-ball scene - fixed in the engine,
not here, but the guard stays as a safety net for the next such regression.

## Running it

```
TMPDIR=/tmp/claude-1000/phame-verify php8.4 vendor/bin/phpunit tests/Verification
TMPDIR=/tmp/claude-1000/phame-verify php8.5 vendor/bin/phpunit tests/Verification
TMPDIR=/tmp/claude-1000/phame-verify php8.4 tests/Verification/mutate.php
```

The default run (about 2 seconds) covers a handful of seeded scenes of every kind above, the tunnelling scenes at a
coarse step, the convergence check and the turning-joint-at-a-limit check. `--group soak` runs many more seeds of the
box and open-world scenes, including a larger open-world scale (n = 20, 5000 units, 5 seconds), at the cost of about
a minute and a half. Mutation testing runs the default suite once per mutant (about 15 seconds for all 6).
