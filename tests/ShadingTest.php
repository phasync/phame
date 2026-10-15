<?php

namespace Phame\Tests;

use Phame\GL\Shader;
use Phame\Rendering\MeshShader;
use Phame\Scene\{Geometry, Material, Mesh, PointLight, Scene3D};

final class ShadingTest extends GlTestCase
{
    /** A scene with no sun and no ambient light, looking at the origin from z=5, filling the 64x48 stage. */
    private function darkScene(): Scene3D
    {
        $scene = new Scene3D(64, 48);
        $scene->background = 0x000000;
        $scene->lightColor = 0x000000;
        $scene->ambient = 0x000000;
        $this->engine->stage->addChild($scene);
        return $scene;
    }

    private function wall(Scene3D $scene, Material $material): Mesh
    {
        $wall = new Mesh(Geometry::box(6, 6, 0.2), $material);
        $scene->root->addChild($wall);
        return $wall;
    }

    public function testPointLightLightsSurfacesNearIt(): void
    {
        $scene = $this->darkScene();
        $this->wall($scene, new Material(0xFFFFFF));
        $this->engine->render();
        $this->assertSame(0, $this->pixel(32, 24)[0], 'dark without lights');

        $light = new PointLight(0xFF8000, 1.0, 12.0);
        $light->z = 3;
        $scene->root->addChild($light);
        $this->engine->render();
        $lit = $this->pixel(32, 24);
        $this->assertGreaterThan(100, $lit[0]);
        $this->assertEqualsWithDelta($lit[0] / 2, $lit[1], 6, 'orange: green is half of red');
        $this->assertSame(0, $lit[2]);

        $light->visible = false;
        $this->engine->render();
        $this->assertSame(0, $this->pixel(32, 24)[0], 'an invisible light does not light');
    }

    public function testPointLightFadesWithDistanceAndStopsAtRange(): void
    {
        $scene = $this->darkScene();
        $this->wall($scene, new Material(0xFFFFFF));
        $light = new PointLight(0xFFFFFF, 1.0, 4.0);
        $light->z = 1;
        $scene->root->addChild($light);
        $this->engine->render();
        $centre = $this->pixel(32, 24)[0];
        $edge = $this->pixel(14, 24)[0];
        $this->assertGreaterThan($edge, $centre);
        $light->z = 6;
        $this->engine->render();
        $this->assertSame(0, $this->pixel(32, 24)[0], 'beyond its range it gives nothing');
    }

    public function testSpecularHighlightOnlyForShinyMaterials(): void
    {
        $scene = $this->darkScene();
        $sphere = new Mesh(Geometry::sphere(2.0, 32, 24), new Material(0x000000, specular: 0.0));
        $scene->root->addChild($sphere);
        $light = new PointLight(0xFFFFFF, 1.0, 20.0);
        $light->z = 6;
        $scene->root->addChild($light);
        $this->engine->render();
        $this->assertSame(0, $this->pixel(32, 24)[0], 'a black matte sphere shows nothing');
        $sphere->material->specular = 1.0;
        $sphere->material->shininess = 16;
        $this->engine->render();
        $this->assertGreaterThan(120, $this->pixel(32, 24)[0], 'the highlight is at the middle');
        $this->assertLessThan(60, $this->pixel(32, 24 - 12)[0], 'and gone towards the rim');
    }

    public function testEmissiveIsUnaffectedByLighting(): void
    {
        $scene = $this->darkScene();
        $this->wall($scene, new Material(0x000000, emissive: 0x336699));
        $this->engine->render();
        $this->assertPixel([0x33, 0x66, 0x99, 255], 32, 24);
    }

    public function testFogBlendsWithDistance(): void
    {
        $scene = $this->darkScene();
        $scene->fogColor = 0x808080;
        $scene->fogNear = 2.0;
        $scene->fogFar = 6.0;
        $this->wall($scene, new Material(0xFF0000, unlit: true));
        $this->engine->render();
        // the wall's front is 4.9 from the camera: (4.9 - 2) / 4 = 72.5% of the way to the fog color
        $this->assertPixel([163, 93, 93, 255], 32, 24, 3);
    }

    public function testFogHidesWhatIsBeyondItsFarDistance(): void
    {
        $scene = $this->darkScene();
        $scene->fogColor = 0x204060;
        $scene->fogNear = 1.0;
        $scene->fogFar = 3.0;
        $this->wall($scene, new Material(0xFFFFFF, unlit: true));
        $this->engine->render();
        $this->assertPixel([0x20, 0x40, 0x60, 255], 32, 24);
    }

    public function testCustomShaderDrawsTheSurface(): void
    {
        $scene = $this->darkScene();
        $this->wall($scene, new Material(shader: new MeshShader('vec4 shade() { return vec4(0.0, 1.0, 0.0, 1.0); }')));
        $this->engine->render();
        $this->assertPixel([0, 255, 0, 255], 32, 24);
    }

    public function testCustomShaderGetsUniformsTimeAndMesh(): void
    {
        $scene = $this->darkScene();
        $seen = null;
        $shader = new MeshShader(<<<'GLSL'
            uniform float uPulse;
            vec4 shade() { return vec4(uPulse, uTime > 0.0 ? 1.0 : 0.0, 0.0, 1.0); }
            GLSL, uniforms: function (Shader $s, Mesh $mesh) use (&$seen) {
            $seen = $mesh->name;
            $s->set('uPulse', 0.5);
        });
        $this->wall($scene, new Material(shader: $shader))->name = 'wall';
        $this->engine->renderFrame(0.1);
        $this->assertSame('wall', $seen);
        $this->assertPixel([128, 255, 0, 255], 32, 24, 2);
    }

    public function testDisplaceMovesVerticesAndCanBeLitWithFlatNormals(): void
    {
        $scene = $this->darkScene();
        $shader = new MeshShader(
            'vec4 shade() { return vec4(1.0); }',
            'vec3 displace(vec3 p, vec3 n, vec2 uv) { return p + vec3(0.0, 100.0, 0.0); }',
        );
        $this->wall($scene, new Material(shader: $shader));
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 32, 24, message: 'the wall was moved far away from the middle of the view');
    }

    public function testStandardMaterialStillRespectsSunAndPremultipliedTextures(): void
    {
        $scene = $this->darkScene();
        $scene->lightColor = 0xFFFFFF;
        $scene->lightDirection->set(0, 0, -1);
        $this->wall($scene, new Material(0x808080));
        $this->engine->render();
        $this->assertPixel([128, 128, 128, 255], 32, 24, 2);
    }

    public function testSceneInsideFilteredSprite(): void
    {
        $scene = $this->darkScene();
        $this->wall($scene, new Material(0xFFFFFF, unlit: true));
        $scene->filter = \Phame\Filters\ColorMatrixFilter::invert();
        $this->engine->render();
        $this->assertPixel([0, 0, 0, 255], 32, 24, 2, 'white wall inverted');
        $this->assertPixel([255, 255, 255, 255], 2, 2, 2, 'black background inverted');
    }
}
