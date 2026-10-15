<?php

namespace Phame\Tests;

use Phame\Async;
use Phame\Audio\Sound;
use Phame\Events\Event;

final class AudioTest extends GlTestCase
{
    private function constant(int $sample, int $frames): Sound
    {
        return Sound::fromPcm(str_repeat(pack('v2', $sample & 0xFFFF, $sample & 0xFFFF), $frames));
    }

    private function samples(string $bytes): array
    {
        return array_values(unpack('s*', $bytes));
    }

    public function testVoicesMixWithVolumeAndClip(): void
    {
        $audio = $this->engine->audio;
        $audio->play($this->constant(1000, 100));
        $audio->play($this->constant(1000, 100), 0.5);
        $audio->mix(16);
        $this->assertSame(array_fill(0, 8, 1500), $this->samples($audio->mixed(16)));

        $audio->stopAll();
        $audio->mix(16);
        $audio->play($this->constant(30000, 100));
        $audio->play($this->constant(30000, 100));
        $audio->mix(16);
        $this->assertSame(32767, $this->samples($audio->mixed(16))[0], 'clipped, not wrapped');
    }

    public function testVoiceEndsWithCompleteAndLoopsWrap(): void
    {
        $audio = $this->engine->audio;
        $audio->stopAll();
        $audio->mix(4);
        $short = $audio->play($this->constant(100, 2));   // 8 bytes
        $completed = false;
        $short->addEventListener(Event::COMPLETE, function () use (&$completed) { $completed = true; });
        $loop = $audio->play(Sound::fromPcm(pack('v4', 1, 1, 2, 2)), 1.0, true);
        $audio->mix(16);
        $this->assertTrue($completed);
        $this->assertFalse($short->playing);
        $this->assertSame([101, 101, 102, 102, 1, 1, 2, 2], $this->samples($audio->mixed(16)), 'the loop wraps, the short one stops');
        $this->assertSame(1, $audio->voiceCount);
        $loop->stop();
        $audio->mix(4);
        $this->assertSame(0, $audio->voiceCount);
    }

    public function testCoroutineCanWaitForASoundInTheGameLoop(): void
    {
        $done = null;
        $this->engine->maxFrames = 30;
        $this->engine->clock->forcedDelta = 0.05;
        $this->engine->run(function ($engine) use (&$done) {
            Async::awaitEvent(Sound::tone(440, 0.2)->play(), Event::COMPLETE);
            $done = $engine->clock->frame;
        });
        $this->assertNotNull($done, 'a 0.2 s tone completes while the game runs, also without a sound card');
    }

    public function testToneHasTheRequestedLength(): void
    {
        $this->assertEqualsWithDelta(0.25, Sound::tone(880, 0.25, 'sine', 440)->duration, 1e-4);
    }

    public function testWavFilesAreConvertedToTheMixerFormat(): void
    {
        $mono = str_repeat(pack('v', 1000), 22050);   // 1 s of mono 16 bit at 22.05 kHz
        $wav = 'RIFF' . pack('V', 36 + strlen($mono)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 22050, 44100, 2, 16) . 'data' . pack('V', strlen($mono)) . $mono;
        $path = sys_get_temp_dir() . '/phame-test-' . getmypid() . '.wav';
        file_put_contents($path, $wav);
        try {
            $sound = Sound::load($path);
        } finally {
            unlink($path);
        }
        $this->assertEqualsWithDelta(1.0, $sound->duration, 0.05, 'resampled to 44.1 kHz stereo');
        $this->expectException(\RuntimeException::class);
        Sound::load('/nonexistent.wav');
    }
}
