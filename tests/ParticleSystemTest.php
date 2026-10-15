<?php

namespace Phame\Tests;

use Phame\Display\ParticleSystem;
use Phame\Events\Event;

final class ParticleSystemTest extends GlTestCase
{
    private function system(int $capacity = 64): ParticleSystem
    {
        $p = new ParticleSystem($capacity);
        $p->seed(42);
        $p->x = 32;
        $p->y = 24;
        $p->speed = 0.0;
        $p->spread = 0.0;
        $p->startSize = $p->endSize = 10.0;
        $p->startAlpha = $p->endAlpha = 1.0;
        $p->lifetime = 10.0;
        return $p;
    }

    private function brightness(int $x, int $y): int
    {
        [$r, $g, $b] = $this->pixel($x, $y);
        return $r + $g + $b;
    }

    public function testCapacityIsRespectedAndABurstBeyondItIsTruncated(): void
    {
        $p = $this->system(8);
        $p->burst(5);
        $this->assertSame(5, $p->particleCount);
        $p->burst(100);
        $this->assertSame(8, $p->particleCount);
        $p->start();
        $p->advanceTime(5.0);
        $this->assertSame(8, $p->particleCount, 'emission stops when the storage is full');
    }

    public function testParticlesDieAfterTheirLifetime(): void
    {
        $p = $this->system();
        $p->lifetime = 0.5;
        $p->burst(10);
        $p->advanceTime(0.4);
        $this->assertSame(10, $p->particleCount);
        $p->advanceTime(0.2);
        $this->assertSame(0, $p->particleCount);
    }

    public function testLifetimeVarianceGivesStaggeredDeaths(): void
    {
        $p = $this->system();
        $p->lifetime = 1.0;
        $p->lifetimeVariance = 0.5;
        $p->burst(40);
        $p->advanceTime(1.0);
        $this->assertGreaterThan(0, $p->particleCount);
        $this->assertLessThan(40, $p->particleCount);
        $p->advanceTime(1.0);
        $this->assertSame(0, $p->particleCount);
    }

    public function testEmissionRateYieldsRateTimesElapsedTime(): void
    {
        $p = $this->system(1000);
        $p->emissionRate = 100.0;
        $p->start();
        $this->assertTrue($p->isEmitting);
        for ($i = 0; $i < 100; ++$i) {
            $p->advanceTime(0.01);
        }
        $this->assertEqualsWithDelta(100, $p->particleCount, 1);
    }

    public function testSeedMakesTheSimulationReproducible(): void
    {
        $counts = [];
        foreach ([1, 2] as $run) {
            $p = $this->system();
            $p->seed(7);
            $p->lifetime = 0.5;
            $p->lifetimeVariance = 0.4;
            $p->burst(50);
            $p->advanceTime(0.5);
            $counts[] = $p->particleCount;
        }
        $this->assertSame($counts[0], $counts[1]);
    }

    public function testGravityPullsParticlesDown(): void
    {
        $this->engine->stage->color = 0x000000;
        $p = $this->system();
        $p->y = 5;
        $p->gravityY = 100.0;
        $p->burst(1);
        $this->engine->stage->addChild($p);
        for ($i = 0; $i < 30; ++$i) {
            $this->engine->advance(1 / 60);
        }
        // half a second of 100 points/s² is about 12.5 points down
        $this->engine->render();
        $this->assertGreaterThan(300, $this->brightness(32, 18), 'the particle is where gravity took it');
        $this->assertLessThan(30, $this->brightness(32, 5), 'and it has left its birthplace');
    }

    public function testAdditiveDotsPaintNearTheEmitterAndNotFarAway(): void
    {
        $this->engine->stage->color = 0x000000;
        $p = $this->system();
        $p->burst(3);
        $this->engine->stage->addChild($p);
        $this->engine->render();
        $this->assertGreaterThan(300, $this->brightness(32, 24));
        $this->assertSame(0, $this->brightness(2, 2));
        $this->assertSame(0, $this->brightness(60, 44));
    }

    public function testTheColorFadesFromStartToEndOverTheLifetime(): void
    {
        $this->engine->stage->color = 0x000000;
        $p = $this->system();
        $p->lifetime = 1.0;
        $p->startColor = 0xFF0000;
        $p->endColor = 0x0000FF;
        $p->endAlpha = 1.0;
        $p->burst(4);
        $this->engine->stage->addChild($p);
        $this->engine->render();
        [$r, , $b] = $this->pixel(32, 24);
        $this->assertGreaterThan(150, $r);
        $this->assertLessThan(10, $b);
        for ($i = 0; $i < 30; ++$i) {
            $this->engine->advance(1 / 60);
        }
        $this->engine->render();
        [$r, , $b] = $this->pixel(32, 24);
        $this->assertEqualsWithDelta($r, $b, 40, 'halfway the red and blue are about equal');
    }

    public function testStopLetsExistingParticlesFinishButEmitsNoMore(): void
    {
        $p = $this->system();
        $p->lifetime = 1.0;
        $p->emissionRate = 50.0;
        $p->start();
        $p->advanceTime(0.5);
        $alive = $p->particleCount;
        $this->assertGreaterThan(0, $alive);
        $p->stop();
        $this->assertFalse($p->isEmitting);
        $p->advanceTime(0.25);
        $this->assertSame($alive, $p->particleCount, 'nothing new and nothing dead yet');
        $p->advanceTime(1.0);
        $this->assertSame(0, $p->particleCount);
    }

    public function testRemovingFromTheStageDetachesTheFrameListener(): void
    {
        $p = $this->system();
        $p->lifetime = 1.0;
        $p->burst(5);
        $this->assertFalse($p->hasEventListener(Event::ENTER_FRAME), 'not on the stage yet');
        $this->engine->stage->addChild($p);
        $this->assertTrue($p->hasEventListener(Event::ENTER_FRAME));
        $this->engine->advance(0.4);
        $this->assertSame(5, $p->particleCount);
        $this->engine->stage->removeChild($p);
        $this->assertFalse($p->hasEventListener(Event::ENTER_FRAME));
        $this->engine->advance(5.0);
        $this->assertSame(5, $p->particleCount, 'frozen while off the stage');
        $this->engine->stage->addChild($p);
        $this->engine->advance(5.0);
        $this->assertSame(0, $p->particleCount, 'running again once back');
    }

    public function testDisposeDetachesTheFrameListener(): void
    {
        $p = $this->system();
        $this->engine->stage->addChild($p);
        $p->dispose();
        $this->assertFalse($p->hasEventListener(Event::ENTER_FRAME));
    }

    public function testTheSystemIsNotTouchable(): void
    {
        $p = $this->system();
        $p->burst(5);
        $this->assertNull($p->hitTest(0, 0));
    }

    public function testALongRunDoesNotGrowMemory(): void
    {
        $p = $this->system(256);
        $p->speed = 60.0;
        $p->spread = M_PI;
        $p->angularVelocity = 3.0;
        $p->lifetime = 0.5;
        $p->lifetimeVariance = 0.2;
        $p->emissionRate = 400.0;
        $p->startColor = 0xFFCC00;
        $p->endColor = 0xFF0000;
        $p->endAlpha = 0.0;
        $p->endSize = 2.0;
        $p->start();
        $this->engine->stage->addChild($p);
        for ($i = 0; $i < 300; ++$i) {
            $this->engine->renderFrame(1 / 60);
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < 2000; ++$i) {
            $this->engine->renderFrame(1 / 60);
        }
        gc_collect_cycles();
        $after = memory_get_usage();
        $this->assertGreaterThan(0, $p->particleCount);
        $this->assertLessThan(64 * 1024, $after - $before, 'memory after 2000 frames, relative to after warm-up');
    }
}
