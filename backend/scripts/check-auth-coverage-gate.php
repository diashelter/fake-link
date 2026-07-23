<?php

declare(strict_types=1);

$reportPath = __DIR__.'/../storage/coverage/modules/Auth/index.html';
$minimumPercent = 80.0;

if (! is_file($reportPath)) {
    fwrite(STDERR, "Auth coverage report not found at {$reportPath}. Run pest with --coverage first.\n");

    exit(1);
}

$html = file_get_contents($reportPath);

if ($html === false || ! preg_match(
    '/<td class="(?:warning|success|danger)">Total<\/td>(.*?)<\/tr>/s',
    $html,
    $rowMatch,
)) {
    fwrite(STDERR, "Unable to parse Auth coverage totals from {$reportPath}.\n");

    exit(1);
}

if (! preg_match_all('/aria-valuenow="([\d.]+)"/', $rowMatch[1], $percentMatches) || count($percentMatches[1]) < 2) {
    fwrite(STDERR, "Unable to read line and method coverage percentages from {$reportPath}.\n");

    exit(1);
}

$lineCoverage = (float) $percentMatches[1][0];
$methodCoverage = (float) $percentMatches[1][1];

$failures = [];

if ($lineCoverage < $minimumPercent) {
    $failures[] = sprintf('line coverage %.2f%% is below %.0f%%', $lineCoverage, $minimumPercent);
}

if ($methodCoverage < $minimumPercent) {
    $failures[] = sprintf(
        'method coverage %.2f%% is below %.0f%% (PCOV branch proxy)',
        $methodCoverage,
        $minimumPercent,
    );
}

if ($failures !== []) {
    fwrite(STDERR, "Auth module coverage gate failed:\n - ".implode("\n - ", $failures)."\n");

    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "Auth module coverage gate passed: lines %.2f%%, methods %.2f%% (branch proxy).\n",
        $lineCoverage,
        $methodCoverage,
    ),
);

exit(0);
