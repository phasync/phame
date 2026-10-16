<?php

namespace Phame\Physics;

/**
 * Particles held together by constraints, stepped with XPBD (extended position-based dynamics): ropes, cloth and
 * anything else that bends, drapes, wraps and stays in contact, which an event-driven world cannot express. Strands
 * and sheets are segments with a thickness, not chains of spheres: segments collide with spheres (rigid bodies) and
 * with each other, so a rope wraps around a rock and catches on another rope.
 *
 * The data is plain arrays of floats, one per component (struct of arrays), so that the step loop touches no objects
 * and a C or GPU kernel can take the same buffers over. Each step is split into substeps; each substep predicts the
 * positions from the velocities, moves the colliders, solves the constraints and the contacts once, and derives the
 * velocities from how far the particles moved. Small substeps keep it stable and keep anything from passing through
 * anything else within one.
 */
final class Particles
{
    /** @var list<float> positions */
    public array $x = [];
    public array $y = [];
    public array $z = [];
    /** @var list<float> velocities */
    public array $vx = [];
    public array $vy = [];
    public array $vz = [];
    /** @var list<float> inverse masses: 0 holds a particle where it is */
    public array $w = [];
    /** @var list<float> positions at the start of the substep */
    private array $px = [];
    private array $py = [];
    private array $pz = [];
    public int $count = 0;

    /** @var list<int> distance constraints: the two particles, the rest length, the compliance (0: rigid) */
    public array $ca = [];
    public array $cb = [];
    public array $rest = [];
    public array $compliance = [];
    public int $constraints = 0;

    /** @var list<int> segments that collide: the two particles they run between (rope links, cloth edges) */
    public array $sa = [];
    public array $sb = [];
    public int $segments = 0;

    /** @var list<float> sphere colliders: centre, velocity (straight over a step), radius */
    public array $ox = [];
    public array $oy = [];
    public array $oz = [];
    public array $ovx = [];
    public array $ovy = [];
    public array $ovz = [];
    public array $or = [];
    public int $colliders = 0;

    /** The radius of the strands and sheets. */
    public float $thickness = 0.1;
    /** Whether segments collide with each other (strands catching, cloth folding onto itself): the costly part. */
    public bool $selfCollision = true;
    /** Static and kinetic friction against colliders and between segments. */
    public float $friction = 0.4;
    public float $gx = 0.0;
    public float $gy = 0.0;
    public float $gz = 0.0;

    /** @var array<int, list<int>> segments by grid cell, rebuilt every substep */
    private array $grid = [];

    public function addParticle(float $x, float $y, float $z, float $inverseMass = 1.0): int
    {
        $i = $this->count++;
        [$this->x[$i], $this->y[$i], $this->z[$i]] = [$x, $y, $z];
        [$this->vx[$i], $this->vy[$i], $this->vz[$i]] = [0.0, 0.0, 0.0];
        [$this->px[$i], $this->py[$i], $this->pz[$i]] = [$x, $y, $z];
        $this->w[$i] = $inverseMass;
        return $i;
    }

    /** Holds two particles at their current distance; compliance makes it give (0: as stiff as the solver allows). */
    public function addConstraint(int $a, int $b, float $compliance = 0.0): void
    {
        $c = $this->constraints++;
        [$this->ca[$c], $this->cb[$c]] = [$a, $b];
        $this->rest[$c] = sqrt(($this->x[$a] - $this->x[$b]) ** 2 + ($this->y[$a] - $this->y[$b]) ** 2 + ($this->z[$a] - $this->z[$b]) ** 2);
        $this->compliance[$c] = $compliance;
    }

    public function addSegment(int $a, int $b): void
    {
        $s = $this->segments++;
        [$this->sa[$s], $this->sb[$s]] = [$a, $b];
    }

    public function addCollider(float $x, float $y, float $z, float $radius, float $vx = 0.0, float $vy = 0.0, float $vz = 0.0): int
    {
        $o = $this->colliders++;
        [$this->ox[$o], $this->oy[$o], $this->oz[$o], $this->or[$o]] = [$x, $y, $z, $radius];
        [$this->ovx[$o], $this->ovy[$o], $this->ovz[$o]] = [$vx, $vy, $vz];
        return $o;
    }

    /** Advances $dt seconds in $substeps substeps. */
    public function step(float $dt, int $substeps): void
    {
        $h = $dt / $substeps;
        for ($s = 0; $s < $substeps; ++$s) {
            $this->predict($h);
            for ($o = 0; $o < $this->colliders; ++$o) {
                $this->ox[$o] += $this->ovx[$o] * $h;
                $this->oy[$o] += $this->ovy[$o] * $h;
                $this->oz[$o] += $this->ovz[$o] * $h;
            }
            $this->solveConstraints($h);
            $this->collideWithSpheres($h);
            if ($this->selfCollision) {
                $this->collideSegments();
            }
            for ($i = 0; $i < $this->count; ++$i) {
                $this->vx[$i] = ($this->x[$i] - $this->px[$i]) / $h;
                $this->vy[$i] = ($this->y[$i] - $this->py[$i]) / $h;
                $this->vz[$i] = ($this->z[$i] - $this->pz[$i]) / $h;
            }
        }
    }

    private function predict(float $h): void
    {
        for ($i = 0; $i < $this->count; ++$i) {
            $this->px[$i] = $this->x[$i];
            $this->py[$i] = $this->y[$i];
            $this->pz[$i] = $this->z[$i];
            if ($this->w[$i] > 0.0) {
                $this->vx[$i] += $this->gx * $h;
                $this->vy[$i] += $this->gy * $h;
                $this->vz[$i] += $this->gz * $h;
                $this->x[$i] += $this->vx[$i] * $h;
                $this->y[$i] += $this->vy[$i] * $h;
                $this->z[$i] += $this->vz[$i] * $h;
            }
        }
    }

    private function solveConstraints(float $h): void
    {
        $x = &$this->x;
        $y = &$this->y;
        $z = &$this->z;
        $w = $this->w;
        for ($c = 0; $c < $this->constraints; ++$c) {
            $a = $this->ca[$c];
            $b = $this->cb[$c];
            $wsum = $w[$a] + $w[$b];
            if ($wsum == 0.0) {
                continue;
            }
            $dx = $x[$a] - $x[$b];
            $dy = $y[$a] - $y[$b];
            $dz = $z[$a] - $z[$b];
            $d = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($d == 0.0) {
                continue;
            }
            $lambda = -($d - $this->rest[$c]) / ($wsum + $this->compliance[$c] / ($h * $h)) / $d;
            $x[$a] += $w[$a] * $lambda * $dx;
            $y[$a] += $w[$a] * $lambda * $dy;
            $z[$a] += $w[$a] * $lambda * $dz;
            $x[$b] -= $w[$b] * $lambda * $dx;
            $y[$b] -= $w[$b] * $lambda * $dy;
            $z[$b] -= $w[$b] * $lambda * $dz;
        }
    }

    /**
     * Segments against the colliders: the nearest point of a segment is pushed out of the sphere (shared by its two
     * particles by where it lies), and its motion along the surface, relative to the sphere's, is held back by
     * friction: that is what lets a rope grip and wrap.
     */
    private function collideWithSpheres(float $h): void
    {
        $x = &$this->x;
        $y = &$this->y;
        $z = &$this->z;
        $w = $this->w;
        $th = $this->thickness;
        for ($o = 0; $o < $this->colliders; ++$o) {
            [$cx, $cy, $cz, $reach] = [$this->ox[$o], $this->oy[$o], $this->oz[$o], $this->or[$o] + $th];
            [$mx, $my, $mz] = [$this->ovx[$o] * $h, $this->ovy[$o] * $h, $this->ovz[$o] * $h];   // how far it moved
            for ($s = 0; $s < $this->segments; ++$s) {
                $a = $this->sa[$s];
                $b = $this->sb[$s];
                $ex = $x[$b] - $x[$a];
                $ey = $y[$b] - $y[$a];
                $ez = $z[$b] - $z[$a];
                $len2 = $ex * $ex + $ey * $ey + $ez * $ez;
                $t = $len2 > 0.0 ? max(0.0, min(1.0, (($cx - $x[$a]) * $ex + ($cy - $y[$a]) * $ey + ($cz - $z[$a]) * $ez) / $len2)) : 0.0;
                $nx = $x[$a] + $t * $ex - $cx;
                $ny = $y[$a] + $t * $ey - $cy;
                $nz = $z[$a] + $t * $ez - $cz;
                $d2 = $nx * $nx + $ny * $ny + $nz * $nz;
                if ($d2 >= $reach * $reach || $d2 == 0.0) {
                    continue;
                }
                $d = sqrt($d2);
                [$nx, $ny, $nz] = [$nx / $d, $ny / $d, $nz / $d];
                $wa = (1.0 - $t) * $w[$a];
                $wb = $t * $w[$b];
                $k = (1.0 - $t) * $wa + $t * $wb;   // the inverse effective mass at the point
                if ($k == 0.0) {
                    continue;
                }
                $depth = $reach - $d;
                // friction: the point's slide over the surface this substep, relative to the sphere
                $sx = ($x[$a] - $this->px[$a]) * (1.0 - $t) + ($x[$b] - $this->px[$b]) * $t - $mx;
                $sy = ($y[$a] - $this->py[$a]) * (1.0 - $t) + ($y[$b] - $this->py[$b]) * $t - $my;
                $sz = ($z[$a] - $this->pz[$a]) * (1.0 - $t) + ($z[$b] - $this->pz[$b]) * $t - $mz;
                $along = $sx * $nx + $sy * $ny + $sz * $nz;
                [$sx, $sy, $sz] = [$sx - $along * $nx, $sy - $along * $ny, $sz - $along * $nz];
                $slide = sqrt($sx * $sx + $sy * $sy + $sz * $sz);
                $hold = $slide > 0.0 ? min(1.0, $this->friction * $depth / $slide) : 0.0;
                // one correction: out along the normal, and back against the slide
                $qx = $depth * $nx - $hold * $sx;
                $qy = $depth * $ny - $hold * $sy;
                $qz = $depth * $nz - $hold * $sz;
                $x[$a] += $wa / $k * $qx;   // each end by its share of the point and how freely it moves
                $y[$a] += $wa / $k * $qy;
                $z[$a] += $wa / $k * $qz;
                $x[$b] += $wb / $k * $qx;
                $y[$b] += $wb / $k * $qy;
                $z[$b] += $wb / $k * $qz;
            }
        }
    }

    /**
     * Segments against segments (strands catching on strands, cloth on itself): the nearest points of two segments
     * closer than twice the thickness are pushed apart, and friction holds back their slide past each other (that is
     * what makes strands catch instead of slipping off). A grid of cells as wide as the longest segment finds the
     * candidates; segments that share a particle are left alone.
     */
    private function collideSegments(): void
    {
        $x = &$this->x;
        $y = &$this->y;
        $z = &$this->z;
        $w = $this->w;
        $reach = 2.0 * $this->thickness;
        $cell = $reach;
        for ($s = 0; $s < $this->segments; ++$s) {
            $a = $this->sa[$s];
            $b = $this->sb[$s];
            $cell = max($cell, abs($x[$a] - $x[$b]) + abs($y[$a] - $y[$b]) + abs($z[$a] - $z[$b]) + $reach);
        }
        $this->grid = [];
        $keys = [];
        for ($s = 0; $s < $this->segments; ++$s) {
            $a = $this->sa[$s];
            $b = $this->sb[$s];
            $key = (((int) floor(($x[$a] + $x[$b]) / 2 / $cell) + 0x100000) << 42) | (((int) floor(($y[$a] + $y[$b]) / 2 / $cell) + 0x100000) << 21) | ((int) floor(($z[$a] + $z[$b]) / 2 / $cell) + 0x100000);
            $keys[$s] = $key;
            $this->grid[$key][] = $s;
        }
        for ($s = 0; $s < $this->segments; ++$s) {
            $a1 = $this->sa[$s];
            $b1 = $this->sb[$s];
            $key = $keys[$s];
            for ($i = -1; $i <= 1; ++$i) {
                for ($j = -1; $j <= 1; ++$j) {
                    $row = $key + ($i << 42) + ($j << 21);
                    for ($k = -1; $k <= 1; ++$k) {
                        foreach ($this->grid[$row + $k] ?? [] as $t) {
                            if ($t <= $s) {
                                continue;   // each pair once
                            }
                            $a2 = $this->sa[$t];
                            $b2 = $this->sb[$t];
                            if ($a1 === $a2 || $a1 === $b2 || $b1 === $a2 || $b1 === $b2) {
                                continue;
                            }
                            // their boxes, grown by the reach, must overlap: most pairs end here, before the closest points
                            if (max($x[$a1], $x[$b1]) + $reach < min($x[$a2], $x[$b2]) || max($x[$a2], $x[$b2]) + $reach < min($x[$a1], $x[$b1])
                                || max($y[$a1], $y[$b1]) + $reach < min($y[$a2], $y[$b2]) || max($y[$a2], $y[$b2]) + $reach < min($y[$a1], $y[$b1])
                                || max($z[$a1], $z[$b1]) + $reach < min($z[$a2], $z[$b2]) || max($z[$a2], $z[$b2]) + $reach < min($z[$a1], $z[$b1])) {
                                continue;
                            }
                            $this->pushApart($a1, $b1, $a2, $b2, $reach, $x, $y, $z, $w);
                        }
                    }
                }
            }
        }
    }

    /** The nearest points of segments a1-b1 and a2-b2; if closer than $reach, both are moved apart along the line between them. */
    private function pushApart(int $a1, int $b1, int $a2, int $b2, float $reach, array &$x, array &$y, array &$z, array $w): void
    {
        // closest points between two segments (clamped parameters u on the first, v on the second)
        $d1x = $x[$b1] - $x[$a1]; $d1y = $y[$b1] - $y[$a1]; $d1z = $z[$b1] - $z[$a1];
        $d2x = $x[$b2] - $x[$a2]; $d2y = $y[$b2] - $y[$a2]; $d2z = $z[$b2] - $z[$a2];
        $rx = $x[$a1] - $x[$a2]; $ry = $y[$a1] - $y[$a2]; $rz = $z[$a1] - $z[$a2];
        $aa = $d1x * $d1x + $d1y * $d1y + $d1z * $d1z;
        $ee = $d2x * $d2x + $d2y * $d2y + $d2z * $d2z;
        $ff = $d2x * $rx + $d2y * $ry + $d2z * $rz;
        $cc = $d1x * $rx + $d1y * $ry + $d1z * $rz;
        $bb = $d1x * $d2x + $d1y * $d2y + $d1z * $d2z;
        $den = $aa * $ee - $bb * $bb;
        $u = $den > 1e-12 ? max(0.0, min(1.0, ($bb * $ff - $cc * $ee) / $den)) : 0.0;
        $v = $ee > 0.0 ? ($bb * $u + $ff) / $ee : 0.0;
        if ($v < 0.0) {
            [$v, $u] = [0.0, $aa > 0.0 ? max(0.0, min(1.0, -$cc / $aa)) : 0.0];
        } elseif ($v > 1.0) {
            [$v, $u] = [1.0, $aa > 0.0 ? max(0.0, min(1.0, ($bb - $cc) / $aa)) : 0.0];
        }
        $nx = $x[$a1] + $u * $d1x - $x[$a2] - $v * $d2x;
        $ny = $y[$a1] + $u * $d1y - $y[$a2] - $v * $d2y;
        $nz = $z[$a1] + $u * $d1z - $z[$a2] - $v * $d2z;
        $dd = $nx * $nx + $ny * $ny + $nz * $nz;
        if ($dd >= $reach * $reach || $dd == 0.0) {
            return;
        }
        $d = sqrt($dd);
        [$nx, $ny, $nz] = [$nx / $d, $ny / $d, $nz / $d];
        $k = (1 - $u) ** 2 * $w[$a1] + $u ** 2 * $w[$b1] + (1 - $v) ** 2 * $w[$a2] + $v ** 2 * $w[$b2];
        if ($k == 0.0) {
            return;
        }
        $depth = $reach - $d;
        // friction: how the two nearest points slid past each other this substep, held back up to friction * depth
        $sx = ($x[$a1] - $this->px[$a1]) * (1 - $u) + ($x[$b1] - $this->px[$b1]) * $u - ($x[$a2] - $this->px[$a2]) * (1 - $v) - ($x[$b2] - $this->px[$b2]) * $v;
        $sy = ($y[$a1] - $this->py[$a1]) * (1 - $u) + ($y[$b1] - $this->py[$b1]) * $u - ($y[$a2] - $this->py[$a2]) * (1 - $v) - ($y[$b2] - $this->py[$b2]) * $v;
        $sz = ($z[$a1] - $this->pz[$a1]) * (1 - $u) + ($z[$b1] - $this->pz[$b1]) * $u - ($z[$a2] - $this->pz[$a2]) * (1 - $v) - ($z[$b2] - $this->pz[$b2]) * $v;
        $along = $sx * $nx + $sy * $ny + $sz * $nz;
        [$sx, $sy, $sz] = [$sx - $along * $nx, $sy - $along * $ny, $sz - $along * $nz];
        $slide = sqrt($sx * $sx + $sy * $sy + $sz * $sz);
        $hold = $slide > 0.0 ? min(1.0, $this->friction * $depth / $slide) : 0.0;
        // one correction of the gap between the points: apart along the normal, and back against the slide
        $qx = ($depth * $nx - $hold * $sx) / $k;
        $qy = ($depth * $ny - $hold * $sy) / $k;
        $qz = ($depth * $nz - $hold * $sz) / $k;
        $f = $w[$a1] * (1 - $u);
        $x[$a1] += $f * $qx;
        $y[$a1] += $f * $qy;
        $z[$a1] += $f * $qz;
        $f = $w[$b1] * $u;
        $x[$b1] += $f * $qx;
        $y[$b1] += $f * $qy;
        $z[$b1] += $f * $qz;
        $f = $w[$a2] * (1 - $v);
        $x[$a2] -= $f * $qx;
        $y[$a2] -= $f * $qy;
        $z[$a2] -= $f * $qz;
        $f = $w[$b2] * $v;
        $x[$b2] -= $f * $qx;
        $y[$b2] -= $f * $qy;
        $z[$b2] -= $f * $qz;
    }
}
