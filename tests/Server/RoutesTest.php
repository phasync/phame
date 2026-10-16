<?php

namespace Phame\Tests\Server;

use PHPUnit\Framework\TestCase;

/**
 * Starts a real, isolated swerve instance (its own port and TMPDIR, so its own phame-rocks.lock) and checks the
 * static routes nginx's /phame prefix-stripping hands to it: the index, and each demo's page.
 */
class RoutesTest extends TestCase
{
    private const PORT = 18799;
    private static $process;
    private static array $pipes = [];
    private static string $tmp;

    public static function setUpBeforeClass(): void
    {
        self::$tmp = sys_get_temp_dir() . '/phame-routes-test-' . getmypid();
        mkdir(self::$tmp);
        $server = dirname(__DIR__, 2) . '/server';
        self::$process = proc_open(
            ['php', 'vendor/bin/swerve', '--http=127.0.0.1:' . self::PORT, '--public=public', '-w', '1', 'swerve.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            self::$pipes,
            $server,
            ['TMPDIR' => self::$tmp] + getenv(),
        );
        if (self::$process === false) {
            self::fail('could not start swerve');
        }
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(100_000);
        }
        self::fail('swerve did not start listening within 10s');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null && self::$process !== false) {
            $status = proc_get_status(self::$process);
            if ($status['pid'] ?? null) {
                posix_kill($status['pid'], SIGTERM);   // by PID only, never pkill -f
            }
            proc_close(self::$process);
        }
        foreach ([self::$tmp . '/phame-rocks.lock', self::$tmp] as $path) {
            @unlink($path);
            @rmdir($path);
        }
    }

    /** @return array{int, string} [status, body] */
    private function get(string $path): array
    {
        $conn = fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 5.0);
        $this->assertNotFalse($conn, "could not connect: $errstr");
        fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
        $response = '';
        while (!feof($conn)) {
            $response .= fread($conn, 65536);
        }
        fclose($conn);
        [$head, $body] = explode("\r\n\r\n", $response, 2) + ['', ''];
        preg_match('#^HTTP/1\.[01] (\d+)#', $head, $m);
        return [(int) ($m[1] ?? 0), $body];
    }

    public function testIndexListsBothDemos(): void
    {
        [$status, $body] = $this->get('/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('rocks-in-box/', $body);
        $this->assertStringContainsString('solar-system-war/', $body);
    }

    public function testRocksInBoxPageResponds(): void
    {
        [$status, $body] = $this->get('/rocks-in-box/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<canvas', $body);
    }

    public function testSolarSystemWarPageResponds(): void
    {
        [$status, $body] = $this->get('/solar-system-war/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('phame.js', $body);
    }

    public function testRocksInBoxWithoutTrailingSlashRedirects(): void
    {
        [$status] = $this->get('/rocks-in-box');
        $this->assertSame(301, $status);
    }
}
