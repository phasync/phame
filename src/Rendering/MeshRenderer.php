<?php

namespace Phame\Rendering;

use Phame\Engine;
use Phame\GL\GL;
use Phame\GL\Shader;
use Phame\Math\IntRect;
use Phame\Math\Matrix4;
use Phame\Scene\Mesh;
use Phame\Scene\Node3D;
use Phame\Scene\PointLight;
use Phame\Scene\Scene3D;
use Phame\Textures\Texture;

/** Draws the meshes of a Scene3D with depth testing, one draw call per mesh: opaque ones first, then transparent ones back to front. */
final class MeshRenderer
{
    private readonly MeshShader $standard;
    private ?MeshShader $current = null;
    private ?Shader $program = null;
    private ?Scene3D $scene = null;
    private string $pointPositions = '';
    private string $pointColors = '';
    private int $pointCount = 0;
    private readonly Texture $white;
    private readonly Matrix4 $identity;
    private readonly Matrix4 $view;
    private readonly Matrix4 $viewProjection;
    private readonly Matrix4 $mvp;
    /** @var list<DrawItem> */
    private array $opaque = [];
    /** @var list<DrawItem> */
    private array $transparent = [];
    private float $eyeX = 0.0;
    private float $eyeY = 0.0;
    private float $eyeZ = 0.0;

    public function __construct(private readonly GL $gl)
    {
        $this->standard = MeshShader::standard();
        $this->white = Texture::fromColor(1, 1, 0xFFFFFFFF);
        $this->identity = Matrix4::get();
        $this->view = Matrix4::get();
        $this->viewProjection = Matrix4::get();
        $this->mvp = Matrix4::get();
    }

    /**
     * @param IntRect $viewport the rectangle the scene is projected into, in drawable pixels (origin bottom left)
     * @param IntRect $scissor  the part of it that may be drawn on
     */
    public function render(Scene3D $scene, IntRect $viewport, IntRect $scissor): void
    {
        if ($viewport->width <= 0 || $viewport->height <= 0 || $scissor->width <= 0 || $scissor->height <= 0) {
            return;
        }
        $gl = $this->gl;
        $camera = $scene->camera;
        $camera->viewMatrix($this->view);
        $camera->projectionMatrix($this->viewProjection, $viewport->width / $viewport->height);
        $this->viewProjection->product($this->viewProjection, $this->view);
        $cameraWorld = $camera->getWorldMatrix();
        $this->eyeX = $cameraWorld->m12;
        $this->eyeY = $cameraWorld->m13;
        $this->eyeZ = $cameraWorld->m14;

        $this->pointPositions = $this->pointColors = '';
        $this->pointCount = 0;
        $this->collect($scene->root, $this->identity);
        if (count($this->transparent) > 1) {
            usort($this->transparent, static fn (DrawItem $a, DrawItem $b) => $b->distance <=> $a->distance);
        }

        $gl->viewport($viewport->x, $viewport->y, $viewport->width, $viewport->height);
        $gl->enable(GL::SCISSOR_TEST);
        $gl->scissor($scissor->x, $scissor->y, $scissor->width, $scissor->height);
        $gl->depthMask(1);
        if ($scene->background !== null) {
            $bg = $scene->background;
            $gl->clearColor((($bg >> 16) & 255) / 255, (($bg >> 8) & 255) / 255, ($bg & 255) / 255, 1.0);
            $gl->clear(GL::COLOR_BUFFER_BIT | GL::DEPTH_BUFFER_BIT);
        } else {
            $gl->clear(GL::DEPTH_BUFFER_BIT);
        }
        $gl->enable(GL::DEPTH_TEST);
        $gl->depthFunc(GL::LEQUAL);

        $this->scene = $scene;
        $this->current = null;

        $culling = null;
        $gl->disable(GL::BLEND);
        foreach ($this->opaque as $item) {
            $this->drawMesh($item, $culling);
            $item->release();
        }
        if ($this->transparent) {
            $gl->enable(GL::BLEND);
            $gl->blendFunc(GL::ONE, GL::ONE_MINUS_SRC_ALPHA);
            $gl->depthMask(0);
            foreach ($this->transparent as $item) {
                $this->drawMesh($item, $culling);
                $item->release();
            }
            $gl->depthMask(1);
        }
        $this->opaque = $this->transparent = [];
        $this->scene = null;

        $gl->disable(GL::DEPTH_TEST);
        $gl->disable(GL::CULL_FACE);
        $gl->disable(GL::SCISSOR_TEST);
        $gl->bindVertexArray(0);
    }

    public function dispose(): void
    {
        $this->standard->dispose();
        $this->white->dispose();
        $this->identity->release();
        $this->view->release();
        $this->viewProjection->release();
        $this->mvp->release();
    }

    private function collect(Node3D $node, Matrix4 $parentWorld): void
    {
        if (!$node->visible) {
            return;
        }
        $world = Matrix4::get()->product($parentWorld, $node->getLocalMatrix());
        if ($node instanceof PointLight && $this->pointCount < MeshShader::MAX_POINT_LIGHTS) {
            $c = $node->color;
            $k = $node->intensity / 255;
            $this->pointPositions .= pack('fff', $world->m12, $world->m13, $world->m14);
            $this->pointColors .= pack('ffff', (($c >> 16) & 255) * $k, (($c >> 8) & 255) * $k, ($c & 255) * $k, max($node->range, 0.0001));
            ++$this->pointCount;
        }
        $queued = false;
        if ($node instanceof Mesh && $node->material->alpha > 0.0) {
            $queued = true;
            if ($node->material->alpha < 1.0) {
                $dx = $world->m12 - $this->eyeX;
                $dy = $world->m13 - $this->eyeY;
                $dz = $world->m14 - $this->eyeZ;
                $this->transparent[] = DrawItem::get($node, $world, $dx * $dx + $dy * $dy + $dz * $dz);
            } else {
                $this->opaque[] = DrawItem::get($node, $world, 0.0);
            }
        }
        foreach ($node->children as $child) {
            $this->collect($child, $world);
        }
        if (!$queued) {
            $world->release();
        }
    }

    private function drawMesh(DrawItem $item, ?bool &$culling): void
    {
        $gl = $this->gl;
        $mesh = $item->mesh;
        $material = $mesh->material;
        $meshShader = $material->shader ?? $this->standard;
        if ($meshShader !== $this->current) {
            $this->current = $meshShader;
            $this->program = $meshShader->program($gl);
            $this->program->use();
            $this->applySceneUniforms($this->program);
        }
        $shader = $this->program;
        $doubleSided = $material->doubleSided;
        if ($culling !== !$doubleSided) {
            $doubleSided ? $gl->disable(GL::CULL_FACE) : $gl->enable(GL::CULL_FACE);
            $culling = !$doubleSided;
        }
        $texture = $material->texture ?? $this->white;
        $texture->gpu->bind(0);
        $this->mvp->product($this->viewProjection, $item->world);
        $gl->uniformMatrix4fv($shader->uniform('uMVP'), 1, 0, $this->mvp->pack());
        $gl->uniformMatrix4fv($shader->uniform('uModel'), 1, 0, $item->world->pack());
        $gl->uniformMatrix3fv($shader->uniform('uNormalMatrix'), 1, 0, $item->world->packNormalMatrix());
        $gl->uniform4f($shader->uniform('uUV'), $texture->u0, $texture->v0, $texture->u1 - $texture->u0, $texture->v1 - $texture->v0);
        $c = $material->color;
        $gl->uniform4f($shader->uniform('uColor'), (($c >> 16) & 255) / 255, (($c >> 8) & 255) / 255, ($c & 255) / 255, $material->alpha);
        $e = $material->emissive;
        $gl->uniform3f($shader->uniform('uEmissive'), (($e >> 16) & 255) / 255, (($e >> 8) & 255) / 255, ($e & 255) / 255);
        $gl->uniform1f($shader->uniform('uUnlit'), $material->unlit ? 1.0 : 0.0);
        $gl->uniform1f($shader->uniform('uPremultiplied'), $texture->premultiplied ? 1.0 : 0.0);
        $gl->uniform1f($shader->uniform('uSpecular'), $material->specular);
        $gl->uniform1f($shader->uniform('uShininess'), max($material->shininess, 1.0));
        $meshShader->uniforms?->__invoke($shader, $mesh);
        $mesh->geometry->draw();
    }

    private function applySceneUniforms(Shader $shader): void
    {
        $gl = $this->gl;
        $scene = $this->scene;
        $shader->setInt('uTexture', 0);
        $light = $scene->lightDirection;
        $len = $light->length() ?: 1.0;
        $shader->set('uLightDir', $light->x / $len, $light->y / $len, $light->z / $len);
        $this->uniformRgb($shader, 'uLightColor', $scene->lightColor);
        $this->uniformRgb($shader, 'uAmbient', $scene->ambient);
        $shader->set('uEye', $this->eyeX, $this->eyeY, $this->eyeZ);
        $shader->set('uTime', (float) Engine::current()->clock->time);
        $shader->setInt('uPointCount', $this->pointCount);
        if ($this->pointCount > 0) {
            $gl->uniform3fv($shader->uniform('uPointPos'), $this->pointCount, $this->pointPositions);
            $gl->uniform4fv($shader->uniform('uPointColor'), $this->pointCount, $this->pointColors);
        }
        if ($scene->fogColor === null) {
            $shader->set('uFogRange', 1.0e30, 2.0e30);
        } else {
            $this->uniformRgb($shader, 'uFogColor', $scene->fogColor);
            $shader->set('uFogRange', $scene->fogNear, $scene->fogFar);
        }
    }

    private function uniformRgb(Shader $shader, string $name, int $rgb): void
    {
        $shader->set($name, (($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255);
    }
}
