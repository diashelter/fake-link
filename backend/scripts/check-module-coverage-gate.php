<?php

declare(strict_types=1);

/**
 * Per-module coverage gate.
 *
 * Usage: php check-module-coverage-gate.php [storage-base-path]
 *
 * storage-base-path defaults to __DIR__.'/../storage/coverage/modules'.
 * The optional argument is provided by tests to inject fixture report paths.
 *
 * Each module threshold: [minLines%, minMethods%]
 * Parsing logic is identical to check-auth-coverage-gate.php (PCOV HTML report).
 */

/** @var array<string, array{lines: float, methods: float}> */
$moduleThresholds = [
    'Auth' => ['lines' => 80.0, 'methods' => 80.0],
    'Links' => ['lines' => 90.0, 'methods' => 85.0],
    'Redirects' => ['lines' => 90.0, 'methods' => 85.0],
];

$storageBase = isset($argv[1]) ? rtrim($argv[1], '/') : __DIR__.'/../storage/coverage/modules';

$allFailures = [];

foreach ($moduleThresholds as $module => $thresholds) {
    $reportPath = "{$storageBase}/{$module}/index.html";

    if (! is_file($reportPath)) {
        $allFailures[] = sprintf('[%s] coverage report not found at %s. Run pest with --coverage first.', $module, $reportPath);

        continue;
    }

    $html = file_get_contents($reportPath);

    if ($html === false || ! preg_match(
        '/<td class="(?:warning|success|danger)">Total<\/td>(.*?)<\/tr>/s',
        $html,
        $rowMatch,
    )) {
        $allFailures[] = sprintf('[%s] Unable to parse coverage totals from %s.', $module, $reportPath);

        continue;
    }

    if (! preg_match_all('/aria-valuenow="([\d.]+)"/', $rowMatch[1], $percentMatches) || count($percentMatches[1]) < 2) {
        $allFailures[] = sprintf('[%s] Unable to read line and method coverage percentages from %s.', $module, $reportPath);

        continue;
    }

    $lineCoverage = (float) $percentMatches[1][0];
    $methodCoverage = (float) $percentMatches[1][1];

    $moduleFailures = [];

    if ($lineCoverage < $thresholds['lines']) {
        $moduleFailures[] = sprintf('line coverage %.2f%% is below %.0f%%', $lineCoverage, $thresholds['lines']);
    }

    if ($methodCoverage < $thresholds['methods']) {
        $moduleFailures[] = sprintf(
            'method coverage %.2f%% is below %.0f%% (PCOV branch proxy)',
            $methodCoverage,
            $thresholds['methods'],
        );
    }

    if ($moduleFailures !== []) {
        foreach ($moduleFailures as $failure) {
            $allFailures[] = sprintf('[%s] %s', $module, $failure);
        }
    } else {
        fwrite(
            STDOUT,
            sprintf(
                "%s module coverage gate passed: lines %.2f%%, methods %.2f%% (branch proxy).\n",
                $module,
                $lineCoverage,
                $methodCoverage,
            ),
        );
    }
}

if ($allFailures !== []) {
    fwrite(STDERR, "Module coverage gate failed:\n - ".implode("\n - ", $allFailures)."\n");

    exit(1);
}

exit(0);
