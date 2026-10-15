<?php

namespace Phame\GL;

use FFI;

/**
 * OpenGL 3.3 core, loaded through a platform's proc-address function (wglGetProcAddress, glXGetProcAddress,
 * SDL_GL_GetProcAddress, ...). This class knows nothing else about the platform. Call any entry point below as a method without
 * the gl prefix: `$gl->drawElements(...)`. A PHP string can be passed wherever a `const void*` is expected.
 *
 * Offsets into buffers are declared `intptr_t` rather than `const void*`, so that a plain int can be passed.
 */
final class GL
{
    public const COLOR_BUFFER_BIT = 0x4000;
    public const DEPTH_BUFFER_BIT = 0x100;
    public const DEPTH_TEST = 0xB71;
    public const CULL_FACE = 0xB44;
    public const LESS = 0x201;
    public const LEQUAL = 0x203;
    public const BACK = 0x405;
    public const TRIANGLES = 0x4;
    public const UNSIGNED_BYTE = 0x1401;
    public const UNSIGNED_SHORT = 0x1403;
    public const FLOAT = 0x1406;
    public const RGBA = 0x1908;
    public const TEXTURE_2D = 0xDE1;
    public const TEXTURE0 = 0x84C0;
    public const TEXTURE_MAG_FILTER = 0x2800;
    public const TEXTURE_MIN_FILTER = 0x2801;
    public const TEXTURE_WRAP_S = 0x2802;
    public const TEXTURE_WRAP_T = 0x2803;
    public const NEAREST = 0x2600;
    public const LINEAR = 0x2601;
    public const CLAMP_TO_EDGE = 0x812F;
    public const REPEAT = 0x2901;
    public const BLEND = 0xBE2;
    public const SCISSOR_TEST = 0xC11;
    public const ZERO = 0;
    public const ONE = 1;
    public const SRC_COLOR = 0x300;
    public const ONE_MINUS_SRC_COLOR = 0x301;
    public const SRC_ALPHA = 0x302;
    public const ONE_MINUS_SRC_ALPHA = 0x303;
    public const DST_COLOR = 0x306;
    public const ARRAY_BUFFER = 0x8892;
    public const ELEMENT_ARRAY_BUFFER = 0x8893;
    public const STREAM_DRAW = 0x88E0;
    public const STATIC_DRAW = 0x88E4;
    public const FRAGMENT_SHADER = 0x8B30;
    public const VERTEX_SHADER = 0x8B31;
    public const COMPILE_STATUS = 0x8B81;
    public const LINK_STATUS = 0x8B82;
    public const UNPACK_ALIGNMENT = 0xCF5;
    public const PACK_ALIGNMENT = 0xD05;
    public const FRAMEBUFFER = 0x8D40;
    public const COLOR_ATTACHMENT0 = 0x8CE0;
    public const FRAMEBUFFER_COMPLETE = 0x8CD5;
    public const FRAMEBUFFER_BINDING = 0x8CA6;
    public const RENDERBUFFER = 0x8D41;
    public const DEPTH_ATTACHMENT = 0x8D00;
    public const DEPTH_COMPONENT24 = 0x81A6;
    public const VIEWPORT = 0xBA2;

    private const FUNCTIONS = [
        'glGetError' => 'uint32_t()',
        'glClearColor' => 'void(float,float,float,float)',
        'glClear' => 'void(uint32_t)',
        'glViewport' => 'void(int,int,int,int)',
        'glScissor' => 'void(int,int,int,int)',
        'glEnable' => 'void(uint32_t)',
        'glDisable' => 'void(uint32_t)',
        'glDepthFunc' => 'void(uint32_t)',
        'glDepthMask' => 'void(uint8_t)',
        'glCullFace' => 'void(uint32_t)',
        'glBlendFunc' => 'void(uint32_t,uint32_t)',
        'glGenTextures' => 'void(int,uint32_t*)',
        'glDeleteTextures' => 'void(int,const uint32_t*)',
        'glBindTexture' => 'void(uint32_t,uint32_t)',
        'glActiveTexture' => 'void(uint32_t)',
        'glTexImage2D' => 'void(uint32_t,int,int,int,int,int,uint32_t,uint32_t,const void*)',
        'glTexParameteri' => 'void(uint32_t,uint32_t,int)',
        'glPixelStorei' => 'void(uint32_t,int)',
        'glReadPixels' => 'void(int,int,int,int,uint32_t,uint32_t,void*)',
        'glGenBuffers' => 'void(int,uint32_t*)',
        'glDeleteBuffers' => 'void(int,const uint32_t*)',
        'glBindBuffer' => 'void(uint32_t,uint32_t)',
        'glBufferData' => 'void(uint32_t,intptr_t,const void*,uint32_t)',
        'glGenVertexArrays' => 'void(int,uint32_t*)',
        'glDeleteVertexArrays' => 'void(int,const uint32_t*)',
        'glBindVertexArray' => 'void(uint32_t)',
        'glEnableVertexAttribArray' => 'void(uint32_t)',
        'glVertexAttribPointer' => 'void(uint32_t,int,uint32_t,uint8_t,int,intptr_t)',
        'glDrawElements' => 'void(uint32_t,int,uint32_t,intptr_t)',
        'glCreateShader' => 'uint32_t(uint32_t)',
        'glDeleteShader' => 'void(uint32_t)',
        'glShaderSource' => 'void(uint32_t,int,const char* const*,const int*)',
        'glCompileShader' => 'void(uint32_t)',
        'glGetShaderiv' => 'void(uint32_t,uint32_t,int*)',
        'glGetShaderInfoLog' => 'void(uint32_t,int,int*,char*)',
        'glCreateProgram' => 'uint32_t()',
        'glDeleteProgram' => 'void(uint32_t)',
        'glAttachShader' => 'void(uint32_t,uint32_t)',
        'glLinkProgram' => 'void(uint32_t)',
        'glGetProgramiv' => 'void(uint32_t,uint32_t,int*)',
        'glGetProgramInfoLog' => 'void(uint32_t,int,int*,char*)',
        'glUseProgram' => 'void(uint32_t)',
        'glGetUniformLocation' => 'int(uint32_t,const char*)',
        'glUniform1i' => 'void(int,int)',
        'glUniform1f' => 'void(int,float)',
        'glUniform3f' => 'void(int,float,float,float)',
        'glUniformMatrix3fv' => 'void(int,int,uint8_t,const void*)',
        'glUniformMatrix4fv' => 'void(int,int,uint8_t,const void*)',
        'glUniform4f' => 'void(int,float,float,float,float)',
        'glUniform3fv' => 'void(int,int,const void*)',
        'glUniform4fv' => 'void(int,int,const void*)',
        'glUniform2f' => 'void(int,float,float)',
        'glGetIntegerv' => 'void(uint32_t,int*)',
        'glGenFramebuffers' => 'void(int,uint32_t*)',
        'glDeleteFramebuffers' => 'void(int,const uint32_t*)',
        'glBindFramebuffer' => 'void(uint32_t,uint32_t)',
        'glFramebufferTexture2D' => 'void(uint32_t,uint32_t,uint32_t,uint32_t,int)',
        'glCheckFramebufferStatus' => 'uint32_t(uint32_t)',
        'glGenRenderbuffers' => 'void(int,uint32_t*)',
        'glDeleteRenderbuffers' => 'void(int,const uint32_t*)',
        'glBindRenderbuffer' => 'void(uint32_t,uint32_t)',
        'glRenderbufferStorage' => 'void(uint32_t,uint32_t,int,int)',
        'glFramebufferRenderbuffer' => 'void(uint32_t,uint32_t,uint32_t,uint32_t)',
    ];

    /** @var array<string, \FFI\CData> */
    private array $fn = [];
    private FFI $types;

    /**
     * Needs a current GL context.
     *
     * @param \Closure(string): ?\FFI\CData $getProcAddress resolves a GL function name to its address, or null
     */
    public function __construct(\Closure $getProcAddress)
    {
        $cdef = '';
        foreach (self::FUNCTIONS as $name => $sig) {
            preg_match('/^(.*?)\((.*)\)$/', $sig, $m);
            $cdef .= "typedef {$m[1]} (*PFN_$name)({$m[2]});\n";
        }
        $this->types = FFI::cdef($cdef);
        foreach (self::FUNCTIONS as $name => $_) {
            $address = $getProcAddress($name);
            if ($address === null || FFI::isNull($address)) {
                throw new \RuntimeException("OpenGL function $name is not available: OpenGL 3.3 core is required");
            }
            $this->fn[$name] = $this->types->cast("PFN_$name", $address);
        }
    }

    public function __call(string $name, array $args): mixed
    {
        return ($this->fn['gl' . ucfirst($name)] ?? throw new \BadMethodCallException("Unknown GL function gl$name"))(...$args);
    }

    /** Allocates C memory of the given type, e.g. `int[1]`. Freed when the returned value goes out of scope. */
    public function alloc(string $type): \FFI\CData
    {
        return $this->types->new($type);
    }

    /** Reinterprets C memory as another type, e.g. a `char[]` buffer as `const char*`. */
    public function cast(string $type, \FFI\CData $data): \FFI\CData
    {
        return $this->types->cast($type, $data);
    }

    /** One generated object id, as from glGen*. */
    public function gen(string $what): int
    {
        $id = $this->types->new('uint32_t[1]');
        $this->{'gen' . $what}(1, $id);
        return $id[0];
    }

    public function delete(string $what, int $id): void
    {
        $buf = $this->types->new('uint32_t[1]');
        $buf[0] = $id;
        $this->{'delete' . $what}(1, $buf);
    }

    /** Throws if the GL error flag is set. For tests and debugging: it forces a pipeline sync on some drivers. */
    public function checkError(string $where = ''): void
    {
        $e = $this->getError();
        if ($e !== 0) {
            throw new \RuntimeException(sprintf('OpenGL error 0x%04X %s', $e, $where));
        }
    }
}
