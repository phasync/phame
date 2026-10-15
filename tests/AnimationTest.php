<?php

namespace Phame\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phame\Animation\{Juggler, Transitions, Tween};

final class AnimationTest extends TestCase
{
    public function testLinearTweenInterpolatesAndEndsExactlyOnTheTarget(): void
    {
        $o = new \stdClass();
        $o->x = 10.0;
        $j = new Juggler();
        $j->tween($o, 2.0, ['x' => 30.0]);
        $j->advanceTime(0.5);
        $this->assertEqualsWithDelta(15.0, $o->x, 1e-9);
        $j->advanceTime(1.0);
        $this->assertEqualsWithDelta(25.0, $o->x, 1e-9);
        $j->advanceTime(5.0);
        $this->assertSame(30.0, $o->x, 'overshooting time must not overshoot the value');
    }

    public function testOnCompleteFiresOnceAndTheTweenIsRemoved(): void
    {
        $o = new \stdClass();
        $o->x = 0.0;
        $j = new Juggler();
        $n = 0;
        $j->tween($o, 1.0, ['x' => 1.0], Transitions::LINEAR, function () use (&$n) { ++$n; });
        $j->advanceTime(1.0);
        $j->advanceTime(1.0);
        $j->advanceTime(1.0);
        $this->assertSame(1, $n);
    }

    public function testDelayedCallPassesArgumentsAndFiresOnce(): void
    {
        $j = new Juggler();
        $got = [];
        $j->delayCall(function (...$a) use (&$got) { $got[] = $a; }, 1.0, 'a', 2);
        $j->advanceTime(0.99);
        $this->assertSame([], $got);
        $j->advanceTime(0.02);
        $j->advanceTime(5.0);
        $this->assertSame([['a', 2]], $got);
    }

    public function testAnimatablesAddedWhileAdvancingDoNotRunInTheSameStep(): void
    {
        $j = new Juggler();
        $order = [];
        $j->delayCall(function () use ($j, &$order) {
            $order[] = 'outer';
            $j->delayCall(function () use (&$order) { $order[] = 'inner'; }, 0.0);
        }, 0.0);
        $j->advanceTime(0.1);
        $this->assertSame(['outer'], $order);
        $j->advanceTime(0.1);
        $this->assertSame(['outer', 'inner'], $order);
    }

    public function testRemoveTweensStopsAllTweensOfATarget(): void
    {
        $a = new \stdClass();
        $a->x = 0.0;
        $b = new \stdClass();
        $b->x = 0.0;
        $j = new Juggler();
        $j->tween($a, 1.0, ['x' => 10.0]);
        $j->tween($b, 1.0, ['x' => 10.0]);
        $j->removeTweens($a);
        $j->advanceTime(1.0);
        $this->assertSame(0.0, $a->x);
        $this->assertSame(10.0, $b->x);
    }

    public function testTweenDelay(): void
    {
        $o = new \stdClass();
        $o->x = 0.0;
        $j = new Juggler();
        $t = $j->tween($o, 1.0, ['x' => 10.0]);
        $t->delay = 1.0;
        $j->advanceTime(0.5);
        $this->assertSame(0.0, $o->x);
        $j->advanceTime(1.0);   // 0.5 into the tween
        $this->assertEqualsWithDelta(5.0, $o->x, 1e-9);
    }

    public function testElapsedTime(): void
    {
        $j = new Juggler();
        $j->advanceTime(0.25);
        $j->advanceTime(0.5);
        $this->assertSame(0.75, $j->elapsedTime);
    }

    #[DataProvider('transitions')]
    public function testEveryTransitionStartsAtZeroAndEndsAtOne(string $name): void
    {
        $f = Transitions::get($name);
        $this->assertEqualsWithDelta(0.0, $f(0.0), 1e-9);
        $this->assertEqualsWithDelta(1.0, $f(1.0), 1e-9);
    }

    public static function transitions(): array
    {
        return array_map(fn ($n) => [$n], [Transitions::LINEAR, Transitions::EASE_IN, Transitions::EASE_OUT, Transitions::EASE_IN_OUT, Transitions::EASE_OUT_BACK, Transitions::EASE_OUT_BOUNCE]);
    }

    public function testPropertyAddedToARunningTweenStartsFromItsCurrentValue(): void
    {
        $o = new \stdClass();
        $o->x = 0.0;
        $o->y = 100.0;
        $juggler = new Juggler();
        $tween = $juggler->tween($o, 1.0, ['x' => 10.0]);
        $juggler->advanceTime(0.5);
        $tween->animate('y', 200.0);   // used to raise "Undefined array key" on the next update
        $juggler->advanceTime(0.25);
        $this->assertEqualsWithDelta(7.5, $o->x, 1e-9);
        $this->assertGreaterThan(100.0, $o->y);
        $juggler->advanceTime(1.0);
        $this->assertEqualsWithDelta(10.0, $o->x, 1e-9);
        $this->assertEqualsWithDelta(200.0, $o->y, 1e-9);
    }

    public function testJugglerKeepsRunningObjectsAcrossRemovals(): void
    {
        $juggler = new Juggler();
        $log = [];
        $a = $juggler->delayCall(function () use (&$log) { $log[] = 'a'; }, 1.0);
        $b = $juggler->delayCall(function () use (&$log) { $log[] = 'b'; }, 1.0);
        $c = $juggler->delayCall(function () use (&$log) { $log[] = 'c'; }, 2.0);
        $juggler->remove($b);
        $juggler->advanceTime(1.0);
        $juggler->advanceTime(1.0);
        $this->assertSame(['a', 'c'], $log);
    }

    public function testWholeNumberOfFramesIsNotOneFrameLateThroughFloatError(): void
    {
        $juggler = new Juggler();
        $o = new \stdClass();
        $o->x = 0.0;
        $called = false;
        $juggler->delayCall(function () use (&$called) { $called = true; }, 0.5);
        $tween = $juggler->tween($o, 0.5, ['x' => 1.0]);
        for ($i = 0; $i < 10; ++$i) {
            $juggler->advanceTime(0.05);
        }
        $this->assertTrue($called, 'ten steps of 0.05 reach 0.5');
        $this->assertTrue($tween->isComplete);
        $this->assertSame(1.0, $o->x);
    }

    private function counter(): \stdClass
    {
        $o = new \stdClass();
        $o->x = 0.0;
        return $o;
    }

    public function testRepeatReachesTheEndValueEachCycleAndCompletesOnce(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 1.0);
        $tween->animate('x', 10.0);
        $tween->repeatCount = 3;
        $completed = 0;
        $tween->onComplete = function () use (&$completed) { ++$completed; };
        foreach ([1, 2, 3] as $cycle) {
            $tween->advanceTime(0.5);
            $this->assertEqualsWithDelta(5.0, $o->x, 1e-9, "middle of cycle $cycle");
            $tween->advanceTime(0.5);
            $this->assertSame(10.0, $o->x, "end of cycle $cycle");
            $this->assertSame($cycle === 3, $tween->isComplete);
        }
        $tween->advanceTime(1.0);
        $this->assertSame(1, $completed);
    }

    public function testRepeatCarriesLeftoverTimeIntoTheNextCycle(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 1.0);
        $tween->animate('x', 10.0);
        $tween->repeatCount = 2;
        $tween->advanceTime(1.25);
        $this->assertEqualsWithDelta(2.5, $o->x, 1e-9);
        $tween->advanceTime(0.5);
        $this->assertEqualsWithDelta(7.5, $o->x, 1e-9);
        $this->assertFalse($tween->isComplete);
        $tween->advanceTime(0.5);
        $this->assertTrue($tween->isComplete);
    }

    public function testOneHugeStepSkipsWholeCyclesAndStillCompletes(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 0.1);
        $tween->animate('x', 1.0);
        $tween->repeatCount = 3;
        $tween->advanceTime(100.0);
        $this->assertTrue($tween->isComplete);
        $this->assertSame(1.0, $o->x);
    }

    public function testRepeatForeverNeverCompletes(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 0.5);
        $tween->animate('x', 4.0);
        $tween->repeatCount = 0;
        $completed = false;
        $tween->onComplete = function () use (&$completed) { $completed = true; };
        for ($i = 0; $i < 1000; ++$i) {
            $tween->advanceTime(0.1);
        }
        $this->assertFalse($tween->isComplete);
        $this->assertFalse($completed);
        $this->assertTrue($tween->isPlaying);
        $this->assertGreaterThanOrEqual(0.0, $o->x);
        $this->assertLessThanOrEqual(4.0, $o->x);
    }

    public function testReverseAlternatesDirection(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 1.0);
        $tween->animate('x', 10.0);
        $tween->repeatCount = 0;
        $tween->reverse = true;
        $tween->advanceTime(0.25);
        $this->assertEqualsWithDelta(2.5, $o->x, 1e-9, 'forward');
        $tween->advanceTime(0.75);
        $this->assertSame(10.0, $o->x, 'turning point');
        $tween->advanceTime(0.25);
        $this->assertEqualsWithDelta(7.5, $o->x, 1e-9, 'coming back');
        $tween->advanceTime(0.75);
        $this->assertSame(0.0, $o->x, 'back at the start');
        $tween->advanceTime(0.5);
        $this->assertEqualsWithDelta(5.0, $o->x, 1e-9, 'forward again');
    }

    #[DataProvider('reverseCounts')]
    public function testReverseLandsOnTheEndOrTheStartDependingOnTheCount(int $count, float $expected): void
    {
        $o = $this->counter();
        $o->x = 2.0;
        $tween = new Tween($o, 0.5);
        $tween->animate('x', 12.0);
        $tween->repeatCount = $count;
        $tween->reverse = true;
        $completed = 0;
        $tween->onComplete = function () use (&$completed) { ++$completed; };
        for ($i = 0; $i < $count * 10; ++$i) {
            $tween->advanceTime(0.05);
        }
        $this->assertTrue($tween->isComplete);
        $this->assertSame($expected, $o->x);
        $this->assertSame(1, $completed);
    }

    public static function reverseCounts(): iterable
    {
        yield 'one repetition ends at the end' => [1, 12.0];
        yield 'two repetitions end at the start' => [2, 2.0];
        yield 'three repetitions end at the end' => [3, 12.0];
        yield 'four repetitions end at the start' => [4, 2.0];
    }

    public function testReverseRetracesAnEasedPath(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 1.0, Transitions::EASE_OUT);
        $tween->animate('x', 10.0);
        $tween->repeatCount = 2;
        $tween->reverse = true;
        $tween->advanceTime(0.75);
        $forward = $o->x;
        $tween->advanceTime(0.25);
        $tween->advanceTime(0.25);
        $this->assertEqualsWithDelta($forward, $o->x, 1e-9, 'a quarter into the way back is where it was a quarter before the end');
    }

    public function testOnStartFiresOnceOnTheFirstAdvanceAndBeforeAnythingMoves(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 1.0);
        $tween->animate('x', 10.0);
        $tween->repeatCount = 2;
        $seen = [];
        $tween->onStart = function () use ($o, &$seen) { $seen[] = $o->x; };
        $this->assertFalse($tween->isPlaying);
        $this->assertSame([], $seen, 'not started by being created');
        $tween->advanceTime(0.5);
        $tween->advanceTime(0.5);
        $tween->advanceTime(0.5);
        $this->assertSame([0.0], $seen);
        $this->assertTrue($tween->isPlaying);
        $tween->advanceTime(0.5);
        $this->assertFalse($tween->isPlaying, 'complete');
        $this->assertSame([0.0], $seen);
    }

    public function testOnStartCanMoveTheTargetAndTheTweenStartsFromThere(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 1.0);
        $tween->animate('x', 10.0);
        $tween->onStart = function () use ($o) { $o->x = 8.0; };
        $tween->advanceTime(0.5);
        $this->assertEqualsWithDelta(9.0, $o->x, 1e-9);
    }

    public function testDelayIsRespectedBeforeOnStart(): void
    {
        $o = $this->counter();
        $juggler = new Juggler();
        $tween = $juggler->tween($o, 1.0, ['x' => 10.0]);
        $tween->delay = 0.5;
        $started = 0;
        $tween->onStart = function () use (&$started) { ++$started; };
        $juggler->advanceTime(0.25);
        $this->assertSame(0, $started);
        $this->assertFalse($tween->isPlaying);
        $this->assertSame(0.0, $o->x);
        $juggler->advanceTime(0.25);
        $this->assertSame(1, $started, 'the delay ended exactly now');
        $this->assertTrue($tween->isPlaying);
        $juggler->advanceTime(0.25);
        $this->assertEqualsWithDelta(2.5, $o->x, 1e-9);
        $this->assertSame(1, $started);
    }

    public function testOnUpdateFiresOncePerAdvanceAcrossRepetitions(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 1.0);
        $tween->animate('x', 1.0);
        $tween->repeatCount = 3;
        $updates = 0;
        $tween->onUpdate = function () use (&$updates) { ++$updates; };
        for ($i = 0; $i < 6; ++$i) {
            $tween->advanceTime(0.5);
        }
        $this->assertSame(6, $updates);
    }

    public function testRepeatedStepsOfFiftyMillisecondsDoNotSlipAFramePerCycle(): void
    {
        $o = $this->counter();
        $tween = new Tween($o, 0.5);
        $tween->animate('x', 1.0);
        $tween->repeatCount = 4;
        for ($i = 0; $i < 39; ++$i) {
            $tween->advanceTime(0.05);
            $this->assertFalse($tween->isComplete, "step $i");
        }
        $tween->advanceTime(0.05);
        $this->assertTrue($tween->isComplete, 'four cycles of ten steps, no slip');
    }

    public function testLateAddedPropertyStillStartsLazilyWithRepeats(): void
    {
        $o = $this->counter();
        $o->y = 100.0;
        $tween = new Tween($o, 1.0);
        $tween->animate('x', 10.0);
        $tween->repeatCount = 2;
        $tween->advanceTime(0.5);
        $o->y = 50.0;
        $tween->animate('y', 0.0);
        $tween->advanceTime(0.25);
        // y joined at 50 when 0.75 of the cycle remained: it is 0.75 of the way to 0 now
        $this->assertEqualsWithDelta(50.0 - 50.0 * 0.75, $o->y, 1e-9);
    }
}
