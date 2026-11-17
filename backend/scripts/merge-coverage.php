<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\Report\Facade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

require dirname(__DIR__).'/vendor/autoload.php';

if ($argc < 5 || ! is_numeric($argv[2]) || (float) $argv[2] < 0 || (float) $argv[2] > 100) {
    fwrite(STDERR, "Usage: php scripts/merge-coverage.php OUTPUT_DIRECTORY MINIMUM_LINE_PERCENT INPUT.cov INPUT.cov [...]\n");
    exit(1);
}

try {
    $report = Facade::fromSerializedData((new Merger)->merge(array_slice($argv, 3)));
    $report->renderClover($argv[1].'/clover.xml');
    $report->renderHtml($argv[1].'/html');
    $metrics = simplexml_load_file($argv[1].'/clover.xml')->project->metrics;
    $total = (int) $metrics['statements'];
    $covered = (int) $metrics['coveredstatements'];
    $percent = $total > 0 ? 100 * $covered / $total : 0;
    printf("Backend line coverage: %.2f%% (%d/%d); minimum: %s%%\n", $percent, $covered, $total, $argv[2]);
    if ($percent < (float) $argv[2]) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
