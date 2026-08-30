<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * Builds a minimal PCOV HTML report fixture with the given line and method coverage percentages.
 *
 * The regex the gate script uses:
 *   /<td class="(?:warning|success|danger)">Total<\/td>(.*?)<\/tr>/s
 *   /aria-valuenow="([\d.]+)"/  (first = lines, second = methods)
 */
function buildCoverageFixture(float $lineCoverage, float $methodCoverage): string
{
    return sprintf(
        '<table><tr><td class="success">Total</td>'.
        '<div aria-valuenow="%.2f"></div>'.
        '<div aria-valuenow="%.2f"></div>'.
        '</tr></table>',
        $lineCoverage,
        $methodCoverage,
    );
}

/**
 * Writes per-module fixture files under a temp directory and returns the base path.
 *
 * @param  array<string, array{lines: float, methods: float}>  $modules
 */
function setupFixtureBase(array $modules): string
{
    $base = sys_get_temp_dir().'/coverage-gate-test-'.uniqid('', true);

    foreach ($modules as $module => $coverage) {
        $dir = "{$base}/{$module}";
        mkdir($dir, 0755, true);
        file_put_contents(
            "{$dir}/index.html",
            buildCoverageFixture($coverage['lines'], $coverage['methods']),
        );
    }

    return $base;
}

/**
 * Runs the gate script with the given base path and returns [exitCode, stdout, stderr].
 *
 * @return array{int, string, string}
 */
function runGateScript(string $basePath): array
{
    $script = __DIR__.'/../../scripts/check-module-coverage-gate.php';
    $cmd = sprintf('php %s %s 2>/tmp/gate-stderr-%s', escapeshellarg($script), escapeshellarg($basePath), getmypid());
    $stdout = '';
    $exitCode = 0;
    exec($cmd, $outputLines, $exitCode);
    $stdout = implode("\n", $outputLines);
    $stderrFile = '/tmp/gate-stderr-'.getmypid();
    $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';
    @unlink($stderrFile);

    return [$exitCode, $stdout, $stderr];
}

describe('ModuleCoverageGate', function () {
    it('exits 0 when all modules are above their thresholds', function () {
        $base = setupFixtureBase([
            'Auth' => ['lines' => 85.0, 'methods' => 82.0],
            'Links' => ['lines' => 95.0, 'methods' => 90.0],
            'Redirects' => ['lines' => 92.0, 'methods' => 88.0],
        ]);

        [$exitCode, $stdout] = runGateScript($base);

        expect($exitCode)->toBe(0);
        expect($stdout)->toContain('Auth module coverage gate passed');
        expect($stdout)->toContain('Links module coverage gate passed');
        expect($stdout)->toContain('Redirects module coverage gate passed');
    });

    it('exits 1 when a module is below threshold', function () {
        $base = setupFixtureBase([
            'Auth' => ['lines' => 85.0, 'methods' => 82.0],
            'Links' => ['lines' => 75.0, 'methods' => 80.0],   // lines below 90%, methods below 85%
            'Redirects' => ['lines' => 92.0, 'methods' => 88.0],
        ]);

        [$exitCode, , $stderr] = runGateScript($base);

        expect($exitCode)->toBe(1);
        expect($stderr)->toContain('[Links]');
        expect($stderr)->toContain('line coverage');
    });

    it('exits 1 when a module report is missing', function () {
        $base = setupFixtureBase([
            'Auth' => ['lines' => 85.0, 'methods' => 82.0],
            // Links report intentionally absent
            'Redirects' => ['lines' => 92.0, 'methods' => 88.0],
        ]);

        [$exitCode, , $stderr] = runGateScript($base);

        expect($exitCode)->toBe(1);
        expect($stderr)->toContain('[Links]');
        expect($stderr)->toContain('not found');
    });

    it('exits 1 when method coverage is below threshold but line coverage passes', function () {
        $base = setupFixtureBase([
            'Auth' => ['lines' => 85.0, 'methods' => 82.0],
            'Links' => ['lines' => 95.0, 'methods' => 80.0],   // methods below 85%
            'Redirects' => ['lines' => 92.0, 'methods' => 88.0],
        ]);

        [$exitCode, , $stderr] = runGateScript($base);

        expect($exitCode)->toBe(1);
        expect($stderr)->toContain('[Links]');
        expect($stderr)->toContain('method coverage');
    });
});
