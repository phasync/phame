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
            $bodies[$i] = $body;
        }
        $nodes = [];
        foreach ($spec->nodeParentIsBody as $j => $isBody) {
            $parent = $isBody ? $bodies[$spec->nodeParentIndex[$j]] : $nodes[$spec->nodeParentIndex[$j]];
            $node = new Node3D();
            $node->radius = $spec->nodeRadius[$j];
            $node->moveTo($spec->nodeOffsetX[$j], $spec->nodeOffsetY[$j], $spec->nodeOffsetZ[$j]);
            $node->spin($spec->nodeSpinX[$j][0], $spec->nodeSpinY[$j][0], $spec->nodeSpinZ[$j][0]);
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
