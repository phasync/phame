<?php

namespace Phame\Tests;

use PHPUnit\Framework\TestCase;
use Phame\Engine;

/** A test with a headless engine: a real OpenGL context, 64x48 stage, black background. */
abstract class GlTestCase extends TestCase
{
    protected Engine $engine;

    protected function setUp(): void
    {
        $this->engine = $this->createEngine(64, 48);
    }

    /** Skips the test where no headless OpenGL 3.3 context exists (SDL's offscreen driver is a Linux feature). */
    protected function createEngine(int $w, int $h, ?int $windowWidth = null, ?int $windowHeight = null): Engine
    {
        try {
            return new Engine($w, $h, headless: true, windowWidth: $windowWidth, windowHeight: $windowHeight);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('No headless OpenGL context: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->gl->checkError('at the end of the test');
            $this->engine->dispose();
        }
    }

    /** Renders a frame and returns the pixel at ($x,$y) as [r,g,b,a]. */
    protected function renderAndPick(int $x, int $y): array
    {
        $this->engine->render();
        return $this->pixel($x, $y);
    }

    /** The pixel of the frame that was last rendered. @return array{int, int, int, int} */
    protected function pixel(int $x, int $y): array
    {
        [$w, , $rgba] = $this->engine->readPixels();
        return array_values(unpack('C4', $rgba, ($y * $w + $x) * 4));
    }

    protected function assertPixel(array $expected, int $x, int $y, int $tolerance = 2, string $message = ''): void
    {
        $actual = $this->pixel($x, $y);
        foreach ($expected as $i => $v) {
            $this->assertEqualsWithDelta($v, $actual[$i], $tolerance, "$message pixel ($x,$y) expected [" . implode(',', $expected) . '] got [' . implode(',', $actual) . ']');
        }
    }
}
