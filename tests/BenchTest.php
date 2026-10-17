<?php

namespace Phame\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every benchmark scene still builds and runs (briefly, in its own process, as bench/run.php runs them). */
final class BenchTest extends TestCase
{
    public static function scenes(): array
    {
        return [['crowded box'], ['ragdolls'], ['open world'], ['gravity']];
    }

    #[DataProvider('scenes')]
    public function testSceneRuns(string $scene): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bench/run.php') . ' ' . escapeshellarg("--scene=$scene") . ' --seconds=0.2 2>&1';
        exec($cmd, $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $result = json_decode((string) end($output), true);
        $this->assertIsArray($result, implode("\n", $output));
        $this->assertArrayHasKey('events', $result);
    }
}
