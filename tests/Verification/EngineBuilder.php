<?php

namespace Phame\Tests\Verification;

use Phame\Scene\Node3D;
use Phame\Scene\Scene3D;
use Phame\Tests\Verification\Oracle\SceneSpec;
use Phame\World;

/**
 * Builds the engine's own Node3D tree from a {@see SceneSpec}, the same plain description the oracle runs. Only the
 * first spin segment of a node is applied here (a plain Node3D::spin() has no schedule); a joint that turns back at
 * a limit (more than one segment) is driven live by the test that set it up, in step with the clock, not by this
 * builder (see TestJoint).
 *
 * Phase 1's Node3D::setAcceleration() is only called when a spec actually asks for one (non-zero): every Phase 0
 * scene has all accelerations at 0.0, so this builder keeps working against today's engine, before that method
 * exists, and only a Phase 1 scene (see SceneGenerator) depends on it.
 */
final class EngineBuilder
{
    /** @return array{bodies: list<Node3D>, nodes: list<Node3D>} indices match the spec's body/node arrays */
    public static function build(SceneSpec $spec, World $world, Scene3D $scene): array
    {
        $bodies = [];
        foreach ($spec->bodyX as $i => $x) {
            $body = new Node3D();
            $body->compound = $spec->bodyCompound[$i];
            $body->mass = $spec->bodyMass[$i];
            $body->confined = $spec->bodyConfined[$i];
            $body->radius = $spec->bodyRadius[$i];
            $body->moveTo($x, $spec->bodyY[$i], $spec->bodyZ[$i]);
            $body->setVelocity($spec->bodyVX[$i], $spec->bodyVY[$i], $spec->bodyVZ[$i]);
            $body->spin($spec->bodySpinX[$i][0], $spec->bodySpinY[$i][0], $spec->bodySpinZ[$i][0]);
            if ($spec->bodyAX[$i] !== 0.0 || $spec->bodyAY[$i] !== 0.0 || $spec->bodyAZ[$i] !== 0.0) {
                $body->setAcceleration($spec->bodyAX[$i], $spec->bodyAY[$i], $spec->bodyAZ[$i]);
            }
            $bodies[$i] = $body;
        }
        $nodes = [];
        foreach ($spec->nodeParentIsBody as $j => $isBody) {
            $parent = $isBody ? $bodies[$spec->nodeParentIndex[$j]] : $nodes[$spec->nodeParentIndex[$j]];
            $node = new Node3D();
            $node->radius = $spec->nodeRadius[$j];
            $node->moveTo($spec->nodeOffsetX[$j], $spec->nodeOffsetY[$j], $spec->nodeOffsetZ[$j]);
            $node->spin($spec->nodeSpinX[$j][0], $spec->nodeSpinY[$j][0], $spec->nodeSpinZ[$j][0]);
            if ($spec->nodeVX[$j] !== 0.0 || $spec->nodeVY[$j] !== 0.0 || $spec->nodeVZ[$j] !== 0.0) {
                $node->setVelocity($spec->nodeVX[$j], $spec->nodeVY[$j], $spec->nodeVZ[$j]);
            }
            if ($spec->nodeAX[$j] !== 0.0 || $spec->nodeAY[$j] !== 0.0 || $spec->nodeAZ[$j] !== 0.0) {
                $node->setAcceleration($spec->nodeAX[$j], $spec->nodeAY[$j], $spec->nodeAZ[$j]);
            }
            $parent->addChild($node);   // before the body joins the scene: see class docblock
            $nodes[$j] = $node;
        }
        foreach ($bodies as $body) {
            $scene->root->addChild($body);
        }
        foreach ($spec->tetherA as $k => $a) {
            $world->physics($scene)->tether($bodies[$a], $bodies[$spec->tetherB[$k]], $spec->tetherLength[$k]);
        }
        return ['bodies' => $bodies, 'nodes' => $nodes];
    }
}
