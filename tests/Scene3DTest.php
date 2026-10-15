<?php

namespace Phame\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Phame\Display\Quad;
use Phame\Math\Matrix4;
use Phame\Math\Vector3;
use Phame\Events\{Event, EnterFrameEvent, TouchEvent};
use Phame\Scene\{Geometry, Material, Mesh, Node3D, Scene3D};
use Phame\Textures\Texture;

final class Scene3DTest extends GlTestCase
{
    private function scene(int $w = 64, int $h = 48, int $background = 0x000000): Scene3D
    {
        $scene = new Scene3D($w, $h);
        $scene->background = $background;
        $this->engine->stage->addChild($scene);
        return $scene;
    }

    private function box(Scene3D $scene, int $color, float $size = 2.0, bool $unlit = true): Mesh
    {
        $mesh = new Mesh(Geometry::box($size, $size, $size), new Material($color, unlit: $unlit));
        $scene->root->addChild($mesh);
        return $mesh;
    }

    public static function geometries(): array
    {
        return [
            'box' => ['boxData', [1.0, 2.0, 3.0]],
            'plane' => ['planeData', [2.0, 2.0, 1]],
            'subdivided plane' => ['planeData', [2.0, 3.0, 5]],
            'sphere' => ['sphereData', [1.0, 12, 8]],
        ];
    }

    /** Backface culling relies on every triangle being counter-clockwise seen from where its vertex normals point. */
    #[DataProvider('geometries')]
    public function testTrianglesFaceTheirNormals(string $method, array $args): void
    {
        [$v, $i] = (new \ReflectionMethod(Geometry::class, $method))->invoke(null, ...$args);
        $vertex = fn (int $n) => array_slice($v, $n * 8, 8);
        $checked = 0;
        for ($t = 0; $t < count($i); $t += 3) {
            [$a, $b, $c] = [$vertex($i[$t]), $vertex($i[$t + 1]), $vertex($i[$t + 2])];
            $e1 = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
            $e2 = [$c[0] - $a[0], $c[1] - $a[1], $c[2] - $a[2]];
            $n = [$e1[1] * $e2[2] - $e1[2] * $e2[1], $e1[2] * $e2[0] - $e1[0] * $e2[2], $e1[0] * $e2[1] - $e1[1] * $e2[0]];
            $area = sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2);
            if ($area < 1e-9) {
                continue;   // the sphere's poles produce degenerate triangles
            }
            $dot = ($n[0] * ($a[3] + $b[3] + $c[3]) + $n[1] * ($a[4] + $b[4] + $c[4]) + $n[2] * ($a[5] + $b[5] + $c[5])) / $area;
            $this->assertGreaterThan(0.0, $dot, "triangle $t of $method is wound clockwise");
            ++$checked;
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testBoxDrawsInTheMiddleOfTheScene(): void
    {
        $scene = $this->scene();
        $this->box($scene, 0xFF0000);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 32, 24, message: 'center');
        $this->assertPixel([0, 0, 0, 255], 2, 2, message: 'corner shows the background');
    }

    public function testNearerObjectsHideFartherOnesWhateverTheDrawOrder(): void
    {
        $scene = $this->scene();
        $near = $this->box($scene, 0x00FF00);
        $far = $this->box($scene, 0xFF0000, 4.0);
        $far->z = -3.0;
        // far was added last, so it is drawn last: the depth test must still keep the near box in front
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 32, 24);
        $far->removeFromParent();
        $scene->root->addChild($far);
        $near->z = 0.0;
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 32, 24);
    }

    public function testNodeTransformsAreInheritedFromParents(): void
    {
        $scene = $this->scene();
        $pivot = new Node3D();
        $pivot->x = 1.0;
        $pivot->scaleX = $pivot->scaleY = $pivot->scaleZ = 3.0;
        $scene->root->addChild($pivot);
        $mesh = new Mesh(Geometry::box(0.2, 0.2, 0.2), new Material(0xFF0000, unlit: true));
        $mesh->x = 0.5;   // 1.5 units to the right of the origin in the world, 0.6 wide
        $pivot->addChild($mesh);
        $this->assertEqualsWithDelta(2.5, $mesh->getWorldMatrix()->transformPoint(Vector3::get())->x, 1e-9);
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 32, 24, message: 'nothing at the origin');
        $this->assertPixel([255, 0, 0, 255], 32 + (int) round(2.5 / (4.9 * tan(deg2rad(30))) * 24), 24, message: 'to the right');
    }

    public function testBackFacesAreCulledUnlessDoubleSided(): void
    {
        $scene = $this->scene();
        $scene->camera->y = 3.0;
        $scene->camera->z = 0.0;
        $scene->camera->lookAt(0, 0, 0);   // looking straight down onto the plane's top
        $plane = new Mesh(Geometry::plane(4, 4), new Material(0xFFFFFF, unlit: true));
        $scene->root->addChild($plane);
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 32, 24, message: 'seen from above: visible');

        $plane->rotationX = M_PI;   // upside down: now we see its back
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 32, 24, message: 'back face culled');
        $plane->material->doubleSided = true;
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 32, 24, message: 'double sided');
    }

    public function testLightingFollowsTheSurfaceNormal(): void
    {
        $scene = $this->scene();
        $scene->ambient = 0x000000;
        $this->box($scene, 0xFFFFFF, 2.0, unlit: false);
        $scene->lightDirection->set(0, 0, -1);   // travels away from the camera: lights the face towards it
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 32, 24, message: 'facing the light');
        $scene->lightDirection->set(0, 0, 1);
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 32, 24, message: 'facing away: only ambient');
        $scene->ambient = 0x404040;
        $this->engine->render();
        $this->assertPixel([64, 64, 64, 255], 32, 24, message: 'ambient');
        $scene->lightDirection->set(-1, 0, -1);   // 45 degrees to the face
        $scene->ambient = 0;
        $this->engine->render();
        $this->assertPixel([180, 180, 180, 255], 32, 24, 2, 'cos(45)');
    }

    public function testTransparentMeshesBlendOverWhatIsBehind(): void
    {
        $scene = $this->scene(background: 0x000000);
        $this->box($scene, 0xFF0000)->z = -2.0;
        $glass = $this->box($scene, 0x0000FF, 0.5);
        $glass->material->alpha = 0.5;
        $glass->z = 1.0;
        $this->engine->render();
        $this->assertPixel([127, 0, 128, 255], 32, 24, 2, 'half blue over red');
        $this->assertPixel([255, 0, 0, 255], 32 + 4, 24, message: 'beside the glass: red only');
    }

    public function testTransparentMeshesAreSortedBackToFront(): void
    {
        $scene = $this->scene();
        $front = $this->box($scene, 0xFF0000, 1.0);
        $front->material->alpha = 0.5;
        $front->z = 1.0;
        $back = $this->box($scene, 0x00FF00, 1.0);
        $back->material->alpha = 0.5;
        $back->z = -1.0;
        // inserted front first, so unsorted drawing would put the back box over it
        $this->engine->render();
        [$r, $g] = [$this->pixel(32, 24)[0], $this->pixel(32, 24)[1]];
        $this->assertGreaterThan($g, $r, 'the front (red) box dominates: red is blended last');
    }

    public function testTexturesAndRegionsAreSampled(): void
    {
        $scene = $this->scene();
        $mesh = $this->box($scene, 0xFFFFFF);
        $green = Texture::fromColor(2, 2, 0xFF00FF00);
        $mesh->material->texture = $green;
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 32, 24);
        // a 2x1 texture: left red, right blue; the region picks the right texel
        $tex = Texture::fromRgba(2, 1, "\xff\x00\x00\xff\x00\x00\xff\xff", smooth: false);
        $mesh->material->texture = $tex->region(1, 0, 1, 1);
        $this->engine->render();
        $this->assertPixel([0, 0, 255, 255], 32, 24);
    }

    public function testDrawingStaysInsideTheSceneRectangle(): void
    {
        $this->engine->stage->color = 0x202020;
        $scene = new Scene3D(32, 24);
        $scene->x = 16;
        $scene->y = 12;
        $scene->background = 0x000080;
        $this->engine->stage->addChild($scene);
        $wall = new Mesh(Geometry::plane(50, 50), new Material(0xFF0000, unlit: true, doubleSided: true));
        $wall->rotationX = M_PI / 2;   // stands up facing the camera; far larger than the rectangle
        $scene->root->addChild($wall);
        $this->engine->render();
        $this->assertPixel([255, 0, 0, 255], 32, 24, message: 'inside');
        $this->assertPixel([255, 0, 0, 255], 17, 13, message: 'inside, top left');
        $this->assertPixel([0x20, 0x20, 0x20, 255], 14, 24, message: 'left of the rectangle');
        $this->assertPixel([0x20, 0x20, 0x20, 255], 32, 40, message: 'below the rectangle');
    }

    public function testSceneWithoutBackgroundShowsThe2DLayerBehindIt(): void
    {
        $this->engine->stage->color = 0x336699;
        $scene = new Scene3D(64, 48);
        $this->engine->stage->addChild($scene);
        $this->engine->render();
        $this->assertPixel([0x33, 0x66, 0x99, 255], 5, 5);
    }

    public function testTwoDimensionalObjectsAboveAndBelowAreCompositedCorrectly(): void
    {
        $below = new Quad(64, 48);
        $below->color = 0x0000FF;
        $this->engine->stage->addChild($below);
        $scene = new Scene3D(64, 48);   // no background
        $this->engine->stage->addChild($scene);
        $this->box($scene, 0xFF0000, 1.0);
        $above = new Quad(10, 10);
        $above->color = 0x00FF00;
        $above->x = 5;
        $above->y = 5;
        $this->engine->stage->addChild($above);
        $this->engine->render();
        $this->assertPixel([0, 0, 255, 255], 40, 5, message: '2D below the scene shows around the 3D objects');
        $this->assertPixel([255, 0, 0, 255], 32, 24, message: '3D over 2D');
        $this->assertPixel([0, 255, 0, 255], 8, 8, message: '2D above the scene');
        // and the painter keeps working in later frames
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 8, 8);
    }

    public function testSceneInsideALetterboxedStageStaysInsideTheStage(): void
    {
        $this->engine->dispose();
        $this->engine = $this->createEngine(64, 48, 128, 48);
        $scene = new Scene3D(64, 48);
        $scene->background = 0xFFFFFF;
        $this->engine->stage->addChild($scene);
        $this->engine->render();
        $this->assertPixel([255, 255, 255, 255], 64, 24, message: 'stage');
        $this->assertPixel([0, 0, 0, 255], 10, 24, message: 'left bar stays black');
        $this->assertPixel([0, 0, 0, 255], 120, 24, message: 'right bar stays black');
    }

    public function testEnterFrameAndTouchReachTheScene(): void
    {
        $scene = $this->scene();
        $mesh = $this->box($scene, 0xFF0000);
        $total = 0.0;
        $mesh->addEventListener(Event::ENTER_FRAME, function (EnterFrameEvent $e) use (&$total) { $total += $e->passedTime; });
        $bubbled = null;
        $scene->root->addEventListener('custom', function (Event $e) use (&$bubbled) { $bubbled = $e->target; });
        $mesh->dispatchEvent(new Event('custom', true));
        $this->assertSame($mesh, $bubbled, 'events bubble through the 3D parents');

        $this->engine->renderFrame(0.25);
        $this->engine->renderFrame(0.25);
        $this->assertEqualsWithDelta(0.5, $total, 1e-9);

        $got = null;
        $scene->addEventListener(Event::TOUCH, function (TouchEvent $e) use (&$got) { $got = $e->target; });
        $this->engine->pointer(10, 10, TouchEvent::BEGAN, 1);
        $this->assertSame($scene, $got);
    }

    public function testNodeHierarchyRules(): void
    {
        $a = new Node3D();
        $b = new Node3D();
        $a->addChild($b);
        $this->expectException(\LogicException::class);
        $b->addChild($a);
    }

    public function testLookAtAimsTheNegativeZAxisAtTheTarget(): void
    {
        $n = new Node3D();
        $n->setPosition(1, 2, 3);
        $n->lookAt(4, 6, 3);   // direction (3,4,0)
        $d = $n->getWorldMatrix()->transformDirection(Vector3::get(0, 0, -1));
        $this->assertEqualsWithDelta(0.6, $d->x, 1e-9);
        $this->assertEqualsWithDelta(0.8, $d->y, 1e-9);
        $this->assertEqualsWithDelta(0.0, $d->z, 1e-9);
    }

    public function testForwardRightAndUpFollowTheNodeAndItsParents(): void
    {
        $parent = new Node3D();
        $parent->rotationY = M_PI / 2;   // turns -z into -x
        $n = new Node3D();
        $n->setScale(3.0);               // directions stay unit length
        $parent->addChild($n);
        $v = Vector3::get();
        $n->forward($v);
        $this->assertEqualsWithDelta([-1.0, 0.0, 0.0], [$v->x, $v->y, $v->z], 1e-9);
        $n->right($v);
        $this->assertEqualsWithDelta([0.0, 0.0, -1.0], [$v->x, $v->y, $v->z], 1e-9);
        $n->up($v);
        $this->assertEqualsWithDelta([0.0, 1.0, 0.0], [$v->x, $v->y, $v->z], 1e-9);
        $v->release();
    }

    public function testInvalidGeometryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Geometry([0, 0, 0, 0, 1, 0, 0, 0], [0, 1, 2]);
    }

    public function testRenderingManyFramesDoesNotLeakPooledObjectsOrMemory(): void
    {
        $scene = $this->scene();
        for ($n = 0; $n < 20; ++$n) {
            $m = $this->box($scene, 0xFF0000);
            $m->material->alpha = $n % 2 ? 0.5 : 1.0;
            $m->x = $n * 0.1;
        }
        $this->engine->render();
        $matrices = Matrix4::pooledCount();
        $items = \Phame\Rendering\DrawItem::pooledCount();
        $before = memory_get_usage();
        for ($i = 0; $i < 100; ++$i) {
            $this->engine->render();
        }
        $this->assertSame($matrices, Matrix4::pooledCount(), 'every matrix taken during a frame is given back');
        $this->assertSame($items, \Phame\Rendering\DrawItem::pooledCount());
        $this->assertLessThan(64 * 1024, memory_get_usage() - $before);
        $this->engine->gl->checkError("after 3D frames");
    }

    public function testDestroyedNodesGiveTheirMatricesBack(): void
    {
        $node = new Node3D();
        $node->getWorldMatrix();
        $before = Matrix4::pooledCount();
        unset($node);
        $this->assertSame($before + 2, Matrix4::pooledCount(), 'local and world matrix');
    }

    public function testPickReturnsTheMeshUnderThePointAndNullElsewhere(): void
    {
        $scene = $this->scene();
        $box = $this->box($scene, 0xFF0000);
        $this->assertSame($box, $scene->pick(32, 24));
        $this->assertNull($scene->pick(2, 2));
    }

    public function testPickAgreesWithWhatIsRendered(): void
    {
        $scene = $this->scene();
        $box = $this->box($scene, 0xFF0000, 1.0);
        $box->x = 1.5;
        $box->rotationY = 0.4;
        $this->engine->render();
        $checked = 0;
        for ($y = 1; $y < 48; $y += 3) {
            for ($x = 1; $x < 64; $x += 3) {
                $rendered = $this->pixel($x, $y)[0] > 128;
                $picked = $scene->pick($x + 0.5, $y + 0.5) === $box;
                // the bounding box of a rotated box is bigger than the box: a pick may only hit more, never less
                $this->assertTrue(!$rendered || $picked, "pixel $x,$y is drawn but not picked");
                ++$checked;
            }
        }
        $this->assertGreaterThan(300, $checked);
    }

    public function testPickChoosesTheNearestMeshAndSkipsInvisibleOnes(): void
    {
        $scene = $this->scene();
        $near = $this->box($scene, 0x00FF00);
        $far = $this->box($scene, 0xFF0000, 4.0);
        $far->z = -3.0;
        $this->assertSame($near, $scene->pick(32, 24));
        $near->visible = false;
        $this->assertSame($far, $scene->pick(32, 24));
    }

    public function testPickRespectsParentTransforms(): void
    {
        $scene = $this->scene();
        $pivot = new Node3D();
        $pivot->x = 10.0;
        $scene->root->addChild($pivot);
        $mesh = new Mesh(Geometry::box(1, 1, 1), new Material());
        $pivot->addChild($mesh);
        $this->assertNull($scene->pick(32, 24), 'the mesh was carried off to x = 10');
        $scene->camera->x = 10.0;
        $this->assertSame($mesh, $scene->pick(32, 24));
    }

    public function testRayThroughTheCenterFollowsTheCamera(): void
    {
        $scene = $this->scene();
        $scene->camera->setPosition(1, 2, 3);
        $scene->camera->lookAt(1, 2, -7);
        $o = Vector3::get();
        $d = Vector3::get();
        $scene->ray(32, 24, $o, $d);
        $this->assertEqualsWithDelta(1.0, $o->x, 1e-9);
        $this->assertEqualsWithDelta(3.0, $o->z, 1e-9);
        $this->assertEqualsWithDelta(-1.0, $d->z, 1e-9);
        $this->assertEqualsWithDelta(1.0, $d->length(), 1e-9);
    }

    public function testWorldBoundsAndIntersects(): void
    {
        $scene = $this->scene();
        $a = $this->box($scene, 0xFF0000, 2.0);
        $b = $this->box($scene, 0xFF0000, 2.0);
        $b->x = 1.9;
        $min = Vector3::get();
        $max = Vector3::get();
        $b->worldBounds($min, $max);
        $this->assertEqualsWithDelta(0.9, $min->x, 1e-9);
        $this->assertEqualsWithDelta(2.9, $max->x, 1e-9);
        $this->assertTrue($a->intersects($b));
        $b->x = 2.1;
        $this->assertFalse($a->intersects($b));
        $b->x = 0.0;
        $b->rotationZ = M_PI / 4;
        $b->worldBounds($min, $max);
        $this->assertEqualsWithDelta(sqrt(2), $max->x, 1e-9, 'a rotated box has a larger box');
    }

    public function testFindByNameSearchesDescendants(): void
    {
        $root = new Node3D();
        $a = new Node3D();
        $b = new Node3D();
        $b->name = 'propeller';
        $root->addChild($a);
        $a->addChild($b);
        $this->assertSame($b, $root->findByName('propeller'));
        $this->assertNull($root->findByName('rudder'));
    }

    public function testMaterialAlphaIsClamped(): void
    {
        $m = new Material(alpha: 1.5);
        $this->assertSame(1.0, $m->alpha);
        $m->alpha = -2.0;
        $this->assertSame(0.0, $m->alpha);
    }
}
