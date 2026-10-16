<?php

namespace Phame\Tests\Verification;

use Phame\Physics\Contact;
use Phame\Physics\Wall;
use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\Tests\Verification\Oracle\SceneSpec;
use Phame\World;

/**
 * Runs a {@see SceneSpec} on today's engine, recording every contact (as the same canonical ids the oracle uses: see
 * Oracle\SceneSpec) and a position snapshot of every body and part at each sample time, for comparison against
 * Oracle\Oracle run on the same spec (see docs/PHYSICS-PLAN.md, "Phase 0").
 */
final class Harness
{
    /**
     * @return array{events: list<array{time: float, kind: string, a: string, b: string}>, samples: list<array{0: float, 1: array<string, array{0: float, 1: float, 2: float}>}>}
     */
    public static function run(SceneSpec $spec, float $until, float $sampleEvery): array
    {
        $world = new World();
        $scene = $world->stage->addChild(new Scene3D(100, 100));
        $scene->worldMinX = $spec->minX;
        $scene->worldMinY = $spec->minY;
        $scene->worldMinZ = $spec->minZ;
        $scene->worldMaxX = $spec->maxX;
        $scene->worldMaxY = $spec->maxY;
        $scene->worldMaxZ = $spec->maxZ;
        $built = EngineBuilder::build($spec, $world, $scene);
        $id = self::idMap($built['bodies'], $built['nodes']);

        $events = [];
        // the engine applies nothing itself (see Physics\Contact): these handlers are the "game", matching exactly
        // the response rules Oracle\Oracle::apply() uses, so the two trajectories can be compared at all
        $world->onCollision(function (Node3D $a, Node3D $b, Contact $c) use (&$events, $id) {
            $events[] = ['time' => $c->time, 'kind' => $c->tether ? 'tether' : 'pair', 'a' => $id[spl_object_id($a)], 'b' => $id[spl_object_id($b)]];
            if ($a->physicsCompound !== null || $b->physicsCompound !== null) {
                [$free, $part, $sign] = $a->physicsCompound !== null ? [$b, $a, -1.0] : [$a, $b, 1.0];
                $part->kineticState();
                [$pvx, $pvy, $pvz] = [\Phame\Kinetic\Kinetic::$vx, \Phame\Kinetic\Kinetic::$vy, \Phame\Kinetic\Kinetic::$vz];
                [$nx, $ny, $nz] = [$sign * $c->nx, $sign * $c->ny, $sign * $c->nz];   // from the free body to the part
                $rel = ($pvx - $free->velocityX) * $nx + ($pvy - $free->velocityY) * $ny + ($pvz - $free->velocityZ) * $nz;
                $k = 2.0 * $rel;   // elastic bounce off a kinematic (infinite-mass) surface: see Oracle\Oracle::apply()
                $free->setVelocity($free->velocityX + $k * $nx, $free->velocityY + $k * $ny, $free->velocityZ + $k * $nz);
                return;
            }
            $j = $c->impulse(1.0);
            $ka = $c->sign($a) * $j / $a->mass;
            $a->setVelocity($a->velocityX + $ka * $c->nx, $a->velocityY + $ka * $c->ny, $a->velocityZ + $ka * $c->nz);
            $kb = $c->sign($b) * $j / $b->mass;
            $b->setVelocity($b->velocityX + $kb * $c->nx, $b->velocityY + $kb * $c->ny, $b->velocityZ + $kb * $c->nz);
        });
        $world->onCollision(function (Node3D $rock, Wall $wall, Contact $c) use (&$events, $id) {
            $events[] = ['time' => $c->time, 'kind' => 'wall', 'a' => $id[spl_object_id($rock)], 'b' => 'wall' . $wall->axis . ($wall->side > 0 ? '+' : '-')];
            match ($wall->axis) {
                0 => $rock->velocityX = -$rock->velocityX,
                1 => $rock->velocityY = -$rock->velocityY,
                default => $rock->velocityZ = -$rock->velocityZ,
            };
        });

        $samples = [];
        \phasync::run(static function () use ($world, $built, $until, $sampleEvery, &$samples) {
            $world->run(static function (World $w) use ($built, $until, $sampleEvery, &$samples) {
                $samples[] = [0.0, self::snapshot($built)];
                while ($w->clock->time < $until - 1e-9) {
                    $w->tick(min($sampleEvery, $until - $w->clock->time));   // the last step lands exactly on $until
                    $samples[] = [$w->clock->time, self::snapshot($built)];
                }
            });
        });
        $world->dispose();
        return ['events' => $events, 'samples' => $samples];
    }

    /** @return array<string, array{0: float, 1: float, 2: float}> */
    private static function snapshot(array $built): array
    {
        $out = [];
        foreach ($built['bodies'] as $i => $body) {
            $out["B{$i}"] = [$body->x, $body->y, $body->z];
        }
        foreach ($built['nodes'] as $j => $node) {
            if ($node->radius > 0.0) {
                $m = $node->getWorldMatrix();
                $out["N{$j}"] = [$m->m12, $m->m13, $m->m14];
            }
        }
        return $out;
    }

    /** @return array<int, string> spl_object_id() to canonical id ("B{i}" or "N{j}") */
    private static function idMap(array $bodies, array $nodes): array
    {
        $id = [];
        foreach ($bodies as $i => $b) {
            $id[spl_object_id($b)] = "B{$i}";
        }
        foreach ($nodes as $j => $n) {
            $id[spl_object_id($n)] = "N{$j}";
        }
        return $id;
    }

    /**
     * Matches every oracle event to an engine event of the same kind and pair (order-independent) within $eps of its
     * time, greedily by nearest time. Returns the events missing from the engine, the ones the engine had extra, and
     * the worst time error among the matches.
     *
     * @param list<array{time: float, kind: string, a: string, b: string}> $engine
     * @param list<array{time: float, kind: string, a: string, b: string}> $oracle
     * @return array{missing: list<array>, extra: list<array>, worstTimeError: float}
     */
    public static function compareEvents(array $engine, array $oracle, float $eps): array
    {
        $key = static fn (array $e) => $e['kind'] . ':' . implode(',', [min($e['a'], $e['b']), max($e['a'], $e['b'])]);
        $used = array_fill(0, count($engine), false);
        $missing = [];
        $worst = 0.0;
        foreach ($oracle as $o) {
            $ok = $key($o);
            $best = null;
            $bestDelta = INF;
            foreach ($engine as $i => $e) {
                if ($used[$i] || $key($e) !== $ok) {
                    continue;
                }
                $delta = abs($e['time'] - $o['time']);
                if ($delta < $bestDelta) {
                    [$best, $bestDelta] = [$i, $delta];
                }
            }
            if ($best === null || $bestDelta > $eps) {
                $missing[] = $o;
                continue;
            }
            $used[$best] = true;
            $worst = max($worst, $bestDelta);
        }
        $extra = [];
        foreach ($engine as $i => $e) {
            if (!$used[$i]) {
                $extra[] = $e;
            }
        }
        return ['missing' => $missing, 'extra' => $extra, 'worstTimeError' => $worst];
    }

    /**
     * The worst position difference between the engine's samples and the oracle, evaluated at the same times.
     */
    public static function worstPositionError(array $samples, \Phame\Tests\Verification\Oracle\Oracle $oracle): float
    {
        $worst = 0.0;
        foreach ($samples as [$t, $positions]) {
            foreach ($positions as $id => [$x, $y, $z]) {
                [$ox, $oy, $oz] = $oracle->positionAt($id, $t);
                $worst = max($worst, sqrt(($x - $ox) ** 2 + ($y - $oy) ** 2 + ($z - $oz) ** 2));
            }
        }
        return $worst;
    }
}
