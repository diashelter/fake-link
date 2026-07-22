<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/**
 * QTOOL-26: smoke that static quality commands exit 0 on the baseline.
 * Invokes the same binaries as composer scripts lint / analyse / md.
 */
it('runs pint style check with exit code zero', function () {
    $result = Process::path(base_path())
        ->timeout(120)
        ->run(['./vendor/bin/pint', '--test']);

    $message = $result->errorOutput() !== '' ? $result->errorOutput() : $result->output();

    expect($result->exitCode())->toBe(0, $message);
});

it('runs phpstan analyse with exit code zero', function () {
    $result = Process::path(base_path())
        ->timeout(180)
        ->run(['./vendor/bin/phpstan', 'analyse', '--memory-limit=512M']);

    $message = $result->errorOutput() !== '' ? $result->errorOutput() : $result->output();

    expect($result->exitCode())->toBe(0, $message);
});

it('runs phpmd with exit code zero', function () {
    $result = Process::path(base_path())
        ->timeout(120)
        ->run([
            './vendor/bin/phpmd',
            'analyze',
            'app',
            '--format=text',
            '--ruleset=phpmd.xml',
        ]);

    $message = $result->errorOutput() !== '' ? $result->errorOutput() : $result->output();

    expect($result->exitCode())->toBe(0, $message);
});
