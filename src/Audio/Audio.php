<?php

namespace Phame\Audio;

use FFI;
use Phame\Platform\SDL;

/**
 * The mixer, at `$engine->audio` (opened on first use). Every frame it mixes the playing voices with SDL's mixer
 * and queues a little ahead of the device. Without an audio device (a server, a test) it runs silently, keeping time,
 * so voices still end and dispatch COMPLETE.
 */
final class Audio
{
    public const RATE = 44100;
    /** Bytes kept queued ahead of the device: 50 ms. */
    private const AHEAD = 8820;
    private const CHUNK = 16384;

    /** Master volume, 0..1. */
    public float $volume = 1.0;
    public readonly bool $silent;
    private int $device = 0;
    private FFI\CData $buffer;
    /** @var list<Voice> */
    private array $voices = [];

    /** Headless engines (tests, servers) mix silently on game-clock time, so that sounds end deterministically. */
    public function __construct(bool $headless = false)
    {
        $sdl = SDL::ffi();
        $this->buffer = $sdl->new('uint8_t[' . self::CHUNK . ']');
        if (!$headless && $sdl->SDL_InitSubSystem(SDL::INIT_AUDIO) === 0) {
            $want = $sdl->new('SDL_AudioSpec');
            $want->freq = self::RATE;
            $want->format = SDL::AUDIO_S16LSB;
            $want->channels = 2;
            $want->samples = 1024;
            $this->device = $sdl->SDL_OpenAudioDevice(null, 0, FFI::addr($want), null, 0);
        }
        $this->silent = $this->device === 0;
        if (!$this->silent) {
            $sdl->SDL_PauseAudioDevice($this->device, 0);
        }
    }

    public function play(Sound $sound, float $volume = 1.0, bool $loop = false): Voice
    {
        return $this->voices[] = new Voice($sound, $volume, $loop);
    }

    public function stopAll(): void
    {
        foreach ($this->voices as $voice) {
            $voice->stop();
        }
    }

    public int $voiceCount {
        get => count($this->voices);
    }

    /** @internal Once per frame: keeps the device fed. $seconds is real time, used when silent. */
    public function pump(float $seconds): void
    {
        if ($this->silent) {
            $this->mix(min(self::CHUNK, (int) ($seconds * self::RATE) * 4), false);
            return;
        }
        $need = self::AHEAD - SDL::ffi()->SDL_GetQueuedAudioSize($this->device);
        if ($need >= 4) {
            $bytes = min(self::CHUNK, $need & ~3);
            $this->mix($bytes, true);
            SDL::ffi()->SDL_QueueAudio($this->device, $this->buffer, $bytes);
        }
    }

    /** @internal Mixes the next $bytes of every voice into the buffer (if $render) and advances them. */
    public function mix(int $bytes, bool $render = true): void
    {
        $sdl = SDL::ffi();
        if ($render) {
            FFI::memset($this->buffer, 0, $bytes);
        }
        $live = 0;
        for ($i = 0; $i < count($this->voices); ++$i) {   // a COMPLETE listener may start voices: they mix too
            $voice = $this->voices[$i];
            if ($voice->playing) {
                $volume = (int) round(max(0.0, min(1.0, $voice->volume * $this->volume)) * 128);
                $done = 0;
                while ($done < $bytes && $voice->playing) {
                    $take = min($bytes - $done, $voice->sound->length - $voice->position);
                    if ($render && $volume > 0 && $take > 0) {
                        $sdl->SDL_MixAudioFormat(FFI::addr($this->buffer[$done]), FFI::addr($voice->sound->data[$voice->position]), SDL::AUDIO_S16LSB, $take, $volume);
                    }
                    $done += $take;
                    $voice->position += $take;
                    if ($voice->position >= $voice->sound->length) {
                        if ($voice->loop && $voice->sound->length > 0) {
                            $voice->position = 0;
                        } else {
                            $voice->finish();
                        }
                    }
                }
            }
            if ($voice->playing) {
                $this->voices[$live++] = $voice;
            }
        }
        array_splice($this->voices, $live);
    }

    /** @internal The first $bytes of the last mix, for tests. */
    public function mixed(int $bytes): string
    {
        return FFI::string($this->buffer, $bytes);
    }

    public function dispose(): void
    {
        if ($this->device !== 0) {
            SDL::ffi()->SDL_CloseAudioDevice($this->device);
            $this->device = 0;
        }
    }
}
