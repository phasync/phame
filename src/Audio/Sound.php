<?php

namespace Phame\Audio;

use FFI;
use Phame\Engine;
use Phame\Platform\SDL;

/**
 * A sound effect or piece of music, decoded once into the mixer's format (44.1 kHz, 16 bit, stereo).
 * `$sound->play()` starts it and returns its Voice. WAV files are supported; convert OGG/MP3 to WAV.
 */
final class Sound
{
    /** The samples: interleaved stereo signed 16 bit, little endian. */
    public readonly FFI\CData $data;
    /** Length in bytes (4 per sample frame). */
    public readonly int $length;

    private function __construct(string $pcm)
    {
        $this->length = strlen($pcm) & ~3;
        $this->data = SDL::ffi()->new('uint8_t[' . max(4, $this->length) . ']');
        FFI::memcpy($this->data, $pcm, $this->length);
    }

    public float $duration {
        get => $this->length / (Audio::RATE * 4);
    }

    public static function load(string $wavPath): self
    {
        $sdl = SDL::ffi();
        $spec = $sdl->new('SDL_AudioSpec');
        $buffer = $sdl->new('uint8_t*');
        $length = $sdl->new('uint32_t');
        $rw = $sdl->SDL_RWFromFile($wavPath, 'rb');
        if ($rw === null || $sdl->SDL_LoadWAV_RW($rw, 1, FFI::addr($spec), FFI::addr($buffer), FFI::addr($length)) === null) {
            throw new \RuntimeException("Cannot load the sound $wavPath: " . $sdl->SDL_GetError());
        }
        try {
            $stream = $sdl->SDL_NewAudioStream($spec->format, $spec->channels, $spec->freq, SDL::AUDIO_S16LSB, 2, Audio::RATE);
            if ($stream === null) {
                throw new \RuntimeException("Cannot convert the sound $wavPath: " . $sdl->SDL_GetError());
            }
            $sdl->SDL_AudioStreamPut($stream, $buffer, $length->cdata);
            $sdl->SDL_AudioStreamFlush($stream);
            $available = $sdl->SDL_AudioStreamAvailable($stream);
            $out = $sdl->new("uint8_t[$available]");
            $got = $sdl->SDL_AudioStreamGet($stream, $out, $available);
            $sdl->SDL_FreeAudioStream($stream);
            return new self(FFI::string($out, max(0, $got)));
        } finally {
            $sdl->SDL_FreeWAV($buffer);
        }
    }

    /** From raw samples: interleaved stereo, signed 16 bit little endian, 44.1 kHz. */
    public static function fromPcm(string $pcm): self
    {
        return new self($pcm);
    }

    /**
     * A generated beep, for prototypes and jams: 'square', 'sine', 'saw' or 'noise', sliding linearly from $from Hz
     * to $to Hz (the same if null) and fading out over its length.
     */
    public static function tone(float $from, float $seconds, string $wave = 'square', ?float $to = null, float $volume = 0.3): self
    {
        $to ??= $from;
        $n = (int) ($seconds * Audio::RATE);
        $pcm = '';
        $phase = 0.0;
        for ($i = 0; $i < $n; ++$i) {
            $t = $i / max(1, $n);
            $phase += ($from + ($to - $from) * $t) / Audio::RATE;
            $p = $phase - floor($phase);
            $v = match ($wave) {
                'sine' => sin($p * 2 * M_PI),
                'saw' => 2 * $p - 1,
                'noise' => mt_rand() / mt_getrandmax() * 2 - 1,
                default => $p < 0.5 ? 1.0 : -1.0,
            };
            $sample = (int) ($v * $volume * (1 - $t) * 32767);
            $pcm .= pack('v2', $sample & 0xFFFF, $sample & 0xFFFF);
        }
        return new self($pcm);
    }

    /** Starts the sound. $volume is 0..1; a looping sound plays until its Voice is stopped. */
    public function play(float $volume = 1.0, bool $loop = false): Voice
    {
        return Engine::current()->audio->play($this, $volume, $loop);
    }
}
