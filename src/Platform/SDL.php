<?php

namespace Phame\Platform;

use FFI;

/** The SDL2 binding: the window, the OpenGL context, the event queue and audio. */
final class SDL
{
    public const INIT_VIDEO = 0x20;
    public const INIT_AUDIO = 0x10;
    public const AUDIO_S16LSB = 0x8010;
    public const WINDOW_OPENGL = 0x2;
    public const WINDOW_HIDDEN = 0x8;
    public const WINDOW_RESIZABLE = 0x20;
    public const WINDOW_ALLOW_HIGHDPI = 0x2000;

    public const GL_DEPTH_SIZE = 6;
    public const GL_CONTEXT_MAJOR_VERSION = 17;
    public const GL_CONTEXT_MINOR_VERSION = 18;
    public const GL_CONTEXT_FLAGS = 20;
    public const GL_CONTEXT_PROFILE_MASK = 21;
    public const GL_FORWARD_COMPATIBLE_FLAG = 2;
    public const GL_PROFILE_CORE = 1;

    public const EVENT_QUIT = 0x100;
    public const EVENT_WINDOW = 0x200;
    public const EVENT_KEY_DOWN = 0x300;
    public const EVENT_KEY_UP = 0x301;
    public const EVENT_MOUSE_MOTION = 0x400;
    public const EVENT_MOUSE_DOWN = 0x401;
    public const EVENT_MOUSE_UP = 0x402;
    public const EVENT_MOUSE_WHEEL = 0x403;

    public const WINDOW_EVENT_SIZE_CHANGED = 6;

    private static ?FFI $ffi = null;

    public static function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(<<<'C'
            typedef struct SDL_Window SDL_Window;

            typedef struct { uint32_t type; uint32_t timestamp; } SDL_CommonEvent;
            typedef struct {
                uint32_t type; uint32_t timestamp; uint32_t windowID;
                uint8_t event; uint8_t p1; uint8_t p2; uint8_t p3; int32_t data1; int32_t data2;
            } SDL_WindowEvent;
            typedef struct {
                uint32_t type; uint32_t timestamp; uint32_t windowID;
                uint8_t state; uint8_t repeat; uint8_t p2; uint8_t p3;
                struct { int32_t scancode; int32_t sym; uint16_t mod; uint32_t unused; } keysym;
            } SDL_KeyboardEvent;
            typedef struct {
                uint32_t type; uint32_t timestamp; uint32_t windowID; uint32_t which; uint32_t state;
                int32_t x; int32_t y; int32_t xrel; int32_t yrel;
            } SDL_MouseMotionEvent;
            typedef struct {
                uint32_t type; uint32_t timestamp; uint32_t windowID; uint32_t which;
                uint8_t button; uint8_t state; uint8_t clicks; uint8_t p1; int32_t x; int32_t y;
            } SDL_MouseButtonEvent;
            typedef struct {
                uint32_t type; uint32_t timestamp; uint32_t windowID; uint32_t which;
                int32_t x; int32_t y; uint32_t direction;
            } SDL_MouseWheelEvent;
            typedef union {
                uint32_t type;
                SDL_CommonEvent common;
                SDL_WindowEvent window;
                SDL_KeyboardEvent key;
                SDL_MouseMotionEvent motion;
                SDL_MouseButtonEvent button;
                SDL_MouseWheelEvent wheel;
                uint8_t padding[56];
            } SDL_Event;

            int SDL_Init(uint32_t flags);
            void SDL_Quit(void);
            const char* SDL_GetError(void);
            int SDL_SetHint(const char* name, const char* value);
            int SDL_GL_SetAttribute(int attr, int value);
            SDL_Window* SDL_CreateWindow(const char* title, int x, int y, int w, int h, uint32_t flags);
            void SDL_DestroyWindow(SDL_Window* window);
            void* SDL_GL_CreateContext(SDL_Window* window);
            void SDL_GL_DeleteContext(void* context);
            int SDL_GL_MakeCurrent(SDL_Window* window, void* context);
            void* SDL_GL_GetProcAddress(const char* name);
            int SDL_GL_SetSwapInterval(int interval);
            void SDL_GL_SwapWindow(SDL_Window* window);
            void SDL_GL_GetDrawableSize(SDL_Window* window, int* w, int* h);
            void SDL_GetWindowSize(SDL_Window* window, int* w, int* h);
            int SDL_PollEvent(SDL_Event* event);
            void SDL_SetWindowTitle(SDL_Window* window, const char* title);

            typedef struct SDL_RWops SDL_RWops;
            typedef struct SDL_AudioStream SDL_AudioStream;
            typedef struct {
                int freq; uint16_t format; uint8_t channels; uint8_t silence; uint16_t samples; uint16_t padding;
                uint32_t size; void* callback; void* userdata;
            } SDL_AudioSpec;
            int SDL_InitSubSystem(uint32_t flags);
            uint32_t SDL_OpenAudioDevice(const char* device, int iscapture, const SDL_AudioSpec* desired, SDL_AudioSpec* obtained, int allowed_changes);
            void SDL_CloseAudioDevice(uint32_t dev);
            void SDL_PauseAudioDevice(uint32_t dev, int pause_on);
            int SDL_QueueAudio(uint32_t dev, const void* data, uint32_t len);
            uint32_t SDL_GetQueuedAudioSize(uint32_t dev);
            void SDL_MixAudioFormat(uint8_t* dst, const uint8_t* src, uint16_t format, uint32_t len, int volume);
            SDL_RWops* SDL_RWFromFile(const char* file, const char* mode);
            SDL_AudioSpec* SDL_LoadWAV_RW(SDL_RWops* src, int freesrc, SDL_AudioSpec* spec, uint8_t** audio_buf, uint32_t* audio_len);
            void SDL_FreeWAV(uint8_t* audio_buf);
            SDL_AudioStream* SDL_NewAudioStream(uint16_t src_format, uint8_t src_channels, int src_rate, uint16_t dst_format, uint8_t dst_channels, int dst_rate);
            int SDL_AudioStreamPut(SDL_AudioStream* stream, const void* buf, int len);
            int SDL_AudioStreamFlush(SDL_AudioStream* stream);
            int SDL_AudioStreamAvailable(SDL_AudioStream* stream);
            int SDL_AudioStreamGet(SDL_AudioStream* stream, void* buf, int len);
            void SDL_FreeAudioStream(SDL_AudioStream* stream);
            C, self::library());
    }

    /** The SDL2 shared library for this OS. Set PHAME_SDL2 to a path to use another one. */
    private static function library(): string
    {
        if (($path = getenv('PHAME_SDL2')) !== false && $path !== '') {
            return $path;
        }
        return match (PHP_OS_FAMILY) {
            'Windows' => 'SDL2.dll',
            'Darwin' => is_file('/opt/homebrew/lib/libSDL2.dylib') ? '/opt/homebrew/lib/libSDL2.dylib' : 'libSDL2-2.0.0.dylib',
            default => 'libSDL2-2.0.so.0',
        };
    }
}
