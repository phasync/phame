<?php

namespace Phame\GL;

/** A linked vertex + fragment program. Attribute locations are given in the GLSL source with `layout(location = n)`. */
final class Shader
{
    public readonly int $id;
    /** @var array<string, int> */
    private array $uniforms = [];

    public function __construct(private readonly GL $gl, string $vertexSource, string $fragmentSource)
    {
        $vs = $this->compile(GL::VERTEX_SHADER, $vertexSource);
        $fs = $this->compile(GL::FRAGMENT_SHADER, $fragmentSource);
        $program = $gl->createProgram();
        $gl->attachShader($program, $vs);
        $gl->attachShader($program, $fs);
        $gl->linkProgram($program);
        $gl->deleteShader($vs);
        $gl->deleteShader($fs);
        $status = $gl->alloc('int[1]');
        $gl->getProgramiv($program, GL::LINK_STATUS, $status);
        if ($status[0] === 0) {
            $log = $this->infoLog(fn ($len, $n, $buf) => $gl->getProgramInfoLog($program, $len, $n, $buf), $program, 'Program');
            $gl->deleteProgram($program);
            throw new \RuntimeException("Shader link failed: $log");
        }
        $this->id = $program;
    }

    public function use(): void
    {
        $this->gl->useProgram($this->id);
    }

    public function uniform(string $name): int
    {
        return $this->uniforms[$name] ??= $this->gl->getUniformLocation($this->id, $name);
    }

    /** Sets a float, vec2, vec3 or vec4 uniform, depending on how many values are given. The shader must be in use. */
    public function set(string $name, float $x, ?float $y = null, ?float $z = null, ?float $w = null): void
    {
        $at = $this->uniform($name);
        match (true) {
            $y === null => $this->gl->uniform1f($at, $x),
            $z === null => $this->gl->uniform2f($at, $x, $y),
            $w === null => $this->gl->uniform3f($at, $x, $y, $z),
            default => $this->gl->uniform4f($at, $x, $y, $z, $w),
        };
    }

    /** Sets an int or sampler uniform. The shader must be in use. */
    public function setInt(string $name, int $value): void
    {
        $this->gl->uniform1i($this->uniform($name), $value);
    }

    public function dispose(): void
    {
        $this->gl->deleteProgram($this->id);
    }

    private function compile(int $type, string $source): int
    {
        $gl = $this->gl;
        $shader = $gl->createShader($type);
        $text = $gl->alloc('char[' . (strlen($source) + 1) . ']');
        \FFI::memcpy($text, $source, strlen($source));
        $lines = $gl->alloc('const char*[1]');
        $lines[0] = $gl->cast('const char*', $text);
        $gl->shaderSource($shader, 1, $lines, null);
        $gl->compileShader($shader);
        $status = $gl->alloc('int[1]');
        $gl->getShaderiv($shader, GL::COMPILE_STATUS, $status);
        if ($status[0] === 0) {
            $log = $this->infoLog(fn ($len, $n, $buf) => $gl->getShaderInfoLog($shader, $len, $n, $buf), $shader, 'Shader');
            $gl->deleteShader($shader);
            throw new \RuntimeException(($type === GL::VERTEX_SHADER ? 'Vertex' : 'Fragment') . " shader failed to compile: $log");
        }
        return $shader;
    }

    private function infoLog(callable $fetch, int $object, string $what): string
    {
        $buf = $this->gl->alloc('char[4096]');
        $length = $this->gl->alloc('int[1]');
        $fetch(4096, $length, $buf);
        return \FFI::string($buf, $length[0]);
    }
}
