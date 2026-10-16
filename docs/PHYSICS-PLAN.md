# Physics plan

Phame's physics is event-driven: every body has a motion description (a function of time in its parent's space), and
the engine predicts the exact moments descriptions change. This plan grows that into a general engine. Fundamentals
come first, and each phase ends at a gate: nothing builds on a phase until its verification passes.

## Principles

- **A description and its window are the universal interface.** Every body, whichever solver moves it, publishes a
  description and the time until which it holds. Rendering, networking, queries and relative motion only see those.
  Event-driven bodies have long windows (until their next event), stepped bodies a step's, sleeping bodies infinite.
- **Validity is local.** A description lives in its parent's space; nothing an ancestor does (accelerating, turning,
  orbiting) shortens it. Derived descriptions (relative motion of a pair) carry their own window and never write it
  back.
- **Tolerance is the gap.** A pair's description only needs to be accurate enough not to miss a contact, so its error
  budget is the gap between them minus a margin: far pairs get long windows, near pairs short ones. The error of a
  description that turns at a constant rate is Σ rᵢ·|Δθᵢ(t)|, the levers times the signed area between each turning
  node's true and assumed rate.
- **Spheres are the exact upper bound.** Bounding spheres give exact times of overlap; real shapes are only checked
  inside those intervals.
- **Struct of arrays.** Solver state lives in flat arrays per component, owned by the solver (nodes hold an index), so
  a C kernel loaded through FFI can later take the loops over without copying. Kernel calls are coarse ("step this
  island n substeps", "events until t") and return events; no PHP callbacks per pair or step.
- **PHP is event-based; iteration lives behind the kernel contract.** The contract: given shapes and linear motion
  (with constant spin) valid for a known durability, report each object's earliest contact before its durability
  (partner, point, normal). Spheres, planes, triangles and translating boxes are exact (quadratics per feature);
  turning shapes are bracketed by conservative advancement inside the kernel. Pairs are rejected cheaply when the gap
  exceeds relative speed times the window; on a GPU the rejection runs in float32 with a margin (false candidates
  only) and survivors in double. Resting is a contact joint and ropes and cloth are stepped islands inside the
  kernel, so PHP still only sees events and descriptions. A PHP implementation of the same contract is the fallback
  and the reference the C/GPU kernel is tested against. With a kernel, exact brute force over pairs is the first
  broad phase; a spatial hash (3b) is added only when benchmarks need it.
- **Frame fields and body forces are one concept.** A field source has a position, a force law and a coupling (mass,
  area). A uniform, mass-coupled field reduces to an acceleration on an ancestor; anything else is applied per part,
  and joints carry the difference.

## Phases

**0. Verification harness.** Built first and run against today's engine, as a baseline.
- Closed-form cases: sphere collisions (time and velocities), equal masses swapping, projectile, point on a turning
  lever, sliding to a stop.
- A reference oracle sharing no code with the engine: dense stepping, matrices instead of quaternions, all parts
  against all parts; its own error measured by convergence at dt, dt/2, dt/4.
- Invariants on random scenes: momentum, energy never gained, no penetration, symmetry, determinism.
- Metamorphic tests: the same scene shifted, rotated, boosted by a constant velocity, built with a different tree, or
  re-described at arbitrary times gives the same world trajectories.
- Edge cases: grazing, simultaneous events, touching at t₀, large coordinates, long times.
- Fuzzing that shrinks failures to minimal regression tests; mutation runs to prove the suite catches deliberate bugs.
- Error budgets written down per quantity.

**1. Description and window as the universal interface.** Acceleration in descriptions, a validity end, local
validity, gravity as a frame field on an ancestor, client evaluation.
Gate: stressing a parent never changes a child's expiry; gravity on an ancestor matches the oracle exactly.

**2. Joints and bodies.** A joint type on the parent link: free (default), fixed, hinge, ball, slider, rope (tethers
move in). A `Body` owned by the root: mass, centre-of-mass description (linear in flight whatever the joints do),
inertia, angular momentum, a super sphere at the centre of mass covering the joints' full range, the root's position
derived from the centre of mass. The effective-mass split of impulses in the engine. Demo ragdolls rebuilt on it.
Gate: momentum exact, angular momentum within tolerance, super sphere never violated, oracle agrees; realism checks
(below) pass.

**3. Tolerance and relative descriptions.** `relative(A, B)` through the deepest common ancestor, windows from the
error rule above; replaces the lever bound in compound probing.
Gate: relative descriptions agree with composed world positions within ε over the whole window; no more probes than
today.

**3b. A broad phase for any world.** Today's grid has one cell size (sized for the demo's rocks), sends bodies over
half a cell to a list checked against everything, and fires cell crossings even through empty space. A general broad
phase: a hierarchical grid (a level per power-of-two size, so pebbles and buildings both stay cheap), empty space
skipped (a body far from everything predicts its next look from the distance to the nearest occupied cell, not per
cell), and the prediction's own window (look again at a horizon) compared against cell crossings. No assumption of a
bounded or crowded volume. The candidate design: a spatio-temporal hash. Each body registers the boxes its sphere
sweeps over its window, with the interval [t_in, t_out] it spends in each (at most 8 boxes at an instant, on the level
matching its size); a pair is a candidate only where two intervals in one box overlap, and gets the exact quadratic
limited to that overlap. The window per body is the least of a maximum, k·s/v (a bounded number of boxes; k = 1 is
today's cell crossing) and its typical time between disturbances, and never beyond the description's known validity (joint limits, field
windows, walls, an island's step); a change of motion registers the new window, and entries carry the description's
version so stale ones are dismissed by one comparison instead of being removed. Entries
sorted by box key find all candidates in one pass, the shape a C or GPU kernel wants.
Gate: open-world scenes (sparse, no walls; sizes from 0.01 to 1000; bullets among slow bodies) cost no more events
per body than the crowded box, and nothing is missed against the oracle.

**4. Contact joints, single support.** A slow impact becomes a contact joint that switches between stick, slide,
roll and separate at exact event times; the support is the parent, so moving supports carry what rests on them.
Gate: a dropped pile settles to zero events per second; stopping positions agree with the oracle; a box on a moving
platform is carried.

**5. Multi-support and stepped islands.** A solve at event times for bodies with several supports; XPBD islands
(`Physics\Particles`) for busy transitions, ropes and cloth, falling asleep into contact joints.

**6. Shapes.** Capsule, box, convex hull, static mesh, each through a distance function, checked by conservative
advancement inside the bounding spheres' overlap.

Later: field sources (a vacuum cleaner), raycasts and triggers, the native kernel.

## Realism

Chaotic motion can't be compared toss by toss, but what looks wrong almost always breaks a law:
- In flight the centre of mass follows an exact parabola and angular momentum about it is constant (flailing limbs
  turn the torso the other way: the falling cat). Energy is only ever lost. A 1 m fall takes 0.45 s.
- Classic experiments: centre of percussion (a hinged rod struck at ⅔ of its length gives no reaction at the hinge;
  tests the effective-mass split), figure skater (ω·I constant), bounce heights h·e²ⁿ, rolling down an incline at
  5/7·g·sinθ, sliding at tanθ = μ, the Dzhanibekov flip, double-pendulum energy.
- A statistical comparison with MuJoCo on the same ragdoll over many random tosses: energy decay, time to rest, spin
  after hits, joint angles, time at limits.
- A side-by-side replay viewer with overlays (centre-of-mass path, angular momentum, energy, flashes on limits and
  contacts), and detectors for jitter at rest, pops, penetration, limbs stuck at limits.

Benchmark scenes are measured at every phase and must not be the demo alone: a crowded box, an open sparse world with no
bounds, mixed sizes (0.01 to 1000) and speeds (bullets among slow bodies), a ragdoll pile, a resting stack, a rope.
