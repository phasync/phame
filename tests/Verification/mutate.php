<?php

/**
 * Mutation testing (Phase 0, layer 7; see docs/PHYSICS-PLAN.md): applies deliberate bugs to a *copy* of
 * src/Physics/Space.php, one at a time, and runs tests/Verification against each copy, to prove the suite would
 * actually catch a real regression there - not just pass by construction.
 *
 * src/ is never written: each mutant is a text copy in a temp directory, and a small bootstrap file declares the
 * mutated Phame\Physics\Space class (by requiring that file) before vendor/autoload.php's class map is ever asked
 * for it, so the real file is never touched, read-only or otherwise. The temp directory is removed on exit, success,
 * failure or Ctrl-C alike (register_shutdown_function() plus a SIGINT handler).
 *
 * Usage: php8.4 tests/Verification/mutate.php (and php8.5 ...). Exits 0 if every mutant was caught, 1 if any
 * survived (reported, not turned into new tests - that is a follow-up step).
 */

$root = dirname(__DIR__, 2);
$spaceFile = $root . '/src/Physics/Space.php';
$original = file_get_contents($spaceFile);
if ($original === false) {
    fwrite(STDERR, "Could not read {$spaceFile}\n");
    exit(1);
}

$tmpBase = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/phame-mutants-' . getmypid();
if (!mkdir($tmpBase, 0700, true) && !is_dir($tmpBase)) {
    fwrite(STDERR, "Could not create {$tmpBase}\n");
    exit(1);
}
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}
register_shutdown_function(static fn () => rrmdir($GLOBALS['tmpBase']));
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use ($tmpBase) {
        rrmdir($tmpBase);
        exit(130);
    });
}

/**
 * Each mutant: a one-line textual change to Space.php, applied with preg_replace and a match limit of 1 (so a search
 * string that appears more than once - see "wrong t0" - only mutates the first, specific occurrence). The scene it
 * should break is named for the report, not run separately: tests/Verification is the judge for all of them.
 */
$mutants = [
    'flip the sign choosing the quadratic\'s root (sphereImpact): picks the wrong, earlier/later contact time' => [
        'search' => '/return \(\$c \/ \(-\$b \+ sqrt\(\$disc\)\)\);/',
        'replace' => 'return ($c / (-$b - sqrt($disc)));',
    ],
    'skip the wall check entirely: a confined body never bounces off the box' => [
        'search' => '/foreach \(!\$a->confined \? \[\] : \[/',
        'replace' => 'foreach (true ? [] : [',
    ],
    'drop the tether event: a tether never goes taut' => [
        'search' => '/foreach \(\$this->tethers\[spl_object_id\(\$a\)\] \?\? \[\] as \[\$b, \$length\]\) \{/',
        'replace' => 'foreach ([] as [$b, $length]) {',
    ],
    'change a PROBE_TOLERANCE comparison from <= to <: a part contact right at the tolerance boundary is missed' => [
        'search' => '/if \(\$gap <= self::PROBE_TOLERANCE \|\| \$gap > 2\.0 \* self::PROBE_TOLERANCE \|\| !\$this->closing\(\$body\)\) \{/',
        'replace' => 'if ($gap < self::PROBE_TOLERANCE || $gap > 2.0 * self::PROBE_TOLERANCE || !$this->closing($body)) {',
    ],
    'skip the cell-crossing rescan: a body that crosses into a new cell never looks for neighbours there' => [
        'search' => '/foreach \(\$this->cells\[\$new \+ \(\$o\[0\] << 42\) \+ \(\$o\[1\] << 21\) \+ \$o\[2\]\] \?\? \[\] as \$b\) \{/',
        'replace' => 'foreach ([] as $b) {',
    ],
    'use the wrong t0 in a description: predict() evaluates a body\'s position as if no time had passed' => [
        'search' => '/\$age = \$now - \$a->mt;/',
        'replace' => '$age = 0.0;',
        'limit' => 1,
    ],
];

$php = PHP_BINARY;
$bootstrap = $tmpBase . '/bootstrap.php';
$survived = [];
foreach ($mutants as $name => $m) {
    $mutated = preg_replace($m['search'], $m['replace'], $original, $m['limit'] ?? -1, $count);
    if ($count < 1) {
        fwrite(STDERR, "SKIP (pattern not found - src/ may have changed): {$name}\n");
        continue;
    }
    $mutantFile = $tmpBase . '/Space.php';
    file_put_contents($mutantFile, $mutated);
    file_put_contents($bootstrap, "<?php\nrequire " . var_export($root . '/vendor/autoload.php', true) . ";\nrequire " . var_export($mutantFile, true) . ";\n");

    $cmd = sprintf('%s %s --bootstrap %s %s 2>&1',
        escapeshellarg($php),
        escapeshellarg($root . '/vendor/bin/phpunit'),
        escapeshellarg($bootstrap),
        escapeshellarg($root . '/tests/Verification'),
    );
    exec($cmd, $output, $exit);
    $caught = $exit !== 0;
    echo ($caught ? 'KILLED ' : 'SURVIVED') . "  {$name}\n";
    if (!$caught) {
        $survived[] = $name;
    }
    $output = [];
}

echo "\n" . count($mutants) . ' mutants, ' . count($survived) . " survived.\n";
foreach ($survived as $name) {
    echo " - {$name}\n";
}
exit($survived === [] ? 0 : 1);
