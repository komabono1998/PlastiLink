<?php
declare(strict_types=1);

/**
 * Run every test suite and print one summary. Exit code 0 only if everything passes.
 *   php tests/run_all.php            all suites
 *   php tests/run_all.php phase9     only suites whose file name contains "phase9"
 * Needs Apache + MariaDB running locally. NEVER run against a live database: the suites create and delete data.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$only = $argv[1] ?? '';
$suites = glob(__DIR__ . '/phase*.php') ?: [];
natsort($suites);
$total = ['passed' => 0, 'failed' => 0];
$bad = [];
$t0 = microtime(true);
foreach ($suites as $file) {
    $name = basename($file, '.php');
    if ($only !== '' && !str_contains($name, $only)) {
        continue;
    }
    $start = microtime(true);
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    preg_match('/(\d+) passed, (\d+) failed/', $text, $m);
    $p = (int) ($m[1] ?? 0);
    $f = (int) ($m[2] ?? 0);
    if (!$m) {
        $f = 1;
    }
    $total['passed'] += $p;
    $total['failed'] += $f;
    printf("%-28s %4d passed, %d failed  (%.1fs)%s\n", $name, $p, $f, microtime(true) - $start, $m ? '' : '  <-- DID NOT FINISH');
    if ($f > 0 || $code !== 0) {
        $bad[] = $name;
        foreach ($out as $line) {
            if (str_starts_with($line, '[FAIL]')) {
                echo "    $line\n";
            }
        }
        if (!$m) {
            echo '    ' . substr(preg_replace('/\s+/', ' ', strip_tags($text)), 0, 400) . "\n";
        }
    }
}
printf("\nTOTAL: %d passed, %d failed in %.0fs%s\n", $total['passed'], $total['failed'], microtime(true) - $t0, $bad ? '   FAILED: ' . implode(', ', $bad) : '   ALL GREEN');
exit($bad ? 1 : 0);
