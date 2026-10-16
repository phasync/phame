<?php

namespace Phame\Tests\Verification\Oracle;

/**
 * A scene, as a plain struct of arrays (one array per field, never an array of [x, y, z]): the single description
 * {@see \Phame\Tests\Verification\SceneGenerator} builds both the engine's scene and the oracle's from, so the two
 * can never drift apart.
 *
 * Bodies are the direct children of the scene root (free spheres, and the roots of compound trees). A compound's
 * descendants are nodes: a turning joint (radius 0) or a part (radius > 0, the only nodes that take part in
 * collisions). A node's parent is either a body (parentIsBody) or an earlier node; nodes are always listed after
 * their parent, so forward kinematics can walk the array once, in order.
 *
 * Spin is a schedule, not a single rate, so a joint that turns back at a limit can be described exactly: parallel
 * segment arrays per body/node, each a start time (the first always 0.0) and the angular velocity from there until
 * the next start time (or forever, for the last).
 */
final class SceneSpec
{
    public float $minX = -INF;
    public float $minY = -INF;
    public float $minZ = -INF;
    public float $maxX = INF;
    public float $maxY = INF;
    public float $maxZ = INF;

    // bodies: direct children of the scene root
    /** @var list<float> */ public array $bodyX = [];
    /** @var list<float> */ public array $bodyY = [];
    /** @var list<float> */ public array $bodyZ = [];
    /** @var list<float> */ public array $bodyVX = [];
    /** @var list<float> */ public array $bodyVY = [];
    /** @var list<float> */ public array $bodyVZ = [];
    /** @var list<float> */ public array $bodyRadius = [];
    /** @var list<float> */ public array $bodyMass = [];
    /** @var list<bool> */ public array $bodyConfined = [];
    /** @var list<bool> */ public array $bodyCompound = [];
    /** @var list<list<float>> one spin schedule per body: start times (first is 0.0) */
    public array $bodySpinStart = [];
    /** @var list<list<float>> */ public array $bodySpinX = [];
    /** @var list<list<float>> */ public array $bodySpinY = [];
    /** @var list<list<float>> */ public array $bodySpinZ = [];

    // nodes: descendants of a compound body, in an order where a parent always comes before its children
    /** @var list<bool> */ public array $nodeParentIsBody = [];
    /** @var list<int> */ public array $nodeParentIndex = [];
    /** @var list<float> */ public array $nodeOffsetX = [];
    /** @var list<float> */ public array $nodeOffsetY = [];
    /** @var list<float> */ public array $nodeOffsetZ = [];
    /** @var list<float> */ public array $nodeRadius = [];
    /** @var list<list<float>> */ public array $nodeSpinStart = [];
    /** @var list<list<float>> */ public array $nodeSpinX = [];
    /** @var list<list<float>> */ public array $nodeSpinY = [];
    /** @var list<list<float>> */ public array $nodeSpinZ = [];

    // tethers: between two bodies (by index)
    /** @var list<int> */ public array $tetherA = [];
    /** @var list<int> */ public array $tetherB = [];
    /** @var list<float> */ public array $tetherLength = [];

    /** Adds a body, with a constant spin (or none: the default) from t = 0. Returns its index. */
    public function addBody(float $x, float $y, float $z, float $vx, float $vy, float $vz, float $radius, float $mass, bool $confined, bool $compound, float $spinX = 0.0, float $spinY = 0.0, float $spinZ = 0.0): int
    {
        $i = count($this->bodyX);
        $this->bodyX[] = $x;
        $this->bodyY[] = $y;
        $this->bodyZ[] = $z;
        $this->bodyVX[] = $vx;
        $this->bodyVY[] = $vy;
        $this->bodyVZ[] = $vz;
        $this->bodyRadius[] = $radius;
        $this->bodyMass[] = $mass;
        $this->bodyConfined[] = $confined;
        $this->bodyCompound[] = $compound;
        $this->bodySpinStart[] = [0.0];
        $this->bodySpinX[] = [$spinX];
        $this->bodySpinY[] = [$spinY];
        $this->bodySpinZ[] = [$spinZ];
        return $i;
    }

    /** Adds a node under a body ($parentIsBody) or another node, with a constant spin from t = 0. Returns its index. */
    public function addNode(bool $parentIsBody, int $parentIndex, float $ox, float $oy, float $oz, float $radius, float $spinX = 0.0, float $spinY = 0.0, float $spinZ = 0.0): int
    {
        $i = count($this->nodeParentIsBody);
        $this->nodeParentIsBody[] = $parentIsBody;
        $this->nodeParentIndex[] = $parentIndex;
        $this->nodeOffsetX[] = $ox;
        $this->nodeOffsetY[] = $oy;
        $this->nodeOffsetZ[] = $oz;
        $this->nodeRadius[] = $radius;
        $this->nodeSpinStart[] = [0.0];
        $this->nodeSpinX[] = [$spinX];
        $this->nodeSpinY[] = [$spinY];
        $this->nodeSpinZ[] = [$spinZ];
        return $i;
    }

    /** Replaces a node's spin schedule (for a joint that turns back at a limit; see JointSchedule). */
    public function setNodeSchedule(int $node, array $starts, array $x, array $y, array $z): void
    {
        $this->nodeSpinStart[$node] = $starts;
        $this->nodeSpinX[$node] = $x;
        $this->nodeSpinY[$node] = $y;
        $this->nodeSpinZ[$node] = $z;
    }

    public function addTether(int $a, int $b, float $length): void
    {
        $this->tetherA[] = $a;
        $this->tetherB[] = $b;
        $this->tetherLength[] = $length;
    }
}
