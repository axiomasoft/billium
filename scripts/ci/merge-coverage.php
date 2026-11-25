<?php

declare(strict_types=1);

/*
 * Merges the PHP coverage files written by parallel CI test jobs into one report.
 *
 * Usage: php scripts/ci/merge-coverage.php <output-dir> <file.cov>...
 * Writes <output-dir>/clover.xml and <output-dir>/html/, and prints a Markdown line summary.
 */

use SebastianBergmann\CodeCoverage\Node\Builder;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Serialization\Merger;
use SebastianBergmann\CodeCoverage\StaticAnalysis\FileAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingSourceAnalyser;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$output = array_shift($arguments);

if (! is_string($output) || $output === '' || $arguments === []) {
    fwrite(STDERR, "usage: php scripts/ci/merge-coverage.php <output-dir> <file.cov>...\n");
    exit(2);
}

$paths = [];
foreach ($arguments as $path) {
    if ($path === '' || ! is_file($path)) {
        fwrite(STDERR, "merge-coverage: not a file: {$path}\n");
        exit(2);
    }
    $paths[] = $path;
}

// Shards run the same PHP build and driver; git metadata is not recorded by the test jobs.
$merged = (new Merger)->merge($paths, requireMatchingGitInformation: false);
// Same analyser settings PHPUnit uses by default: honour @codeCoverageIgnore, keep deprecated code.
$analyser = new FileAnalyser(new ParsingSourceAnalyser, true, false);
$report = (new Builder($analyser))->build($merged['codeCoverage'], $merged['testResults'], $merged['basePath']);

if (! is_dir($output) && ! mkdir($output, 0755, true) && ! is_dir($output)) {
    fwrite(STDERR, "merge-coverage: cannot create {$output}\n");
    exit(1);
}

(new Clover)->process($report, $output.'/clover.xml');
(new HtmlReport)->process($report, $output.'/html');

$lines = $report->percentageOfExecutedLines();
$methods = $report->percentageOfTestedMethods();
$classes = $report->percentageOfTestedClassesAndTraits();

printf(
    "| Coverage | Covered | Total | Percent |\n|---|---:|---:|---:|\n| Lines | %d | %d | %s |\n| Methods | %d | %d | %s |\n| Classes | %d | %d | %s |\n\nMerged from %d coverage file(s).\n",
    $report->numberOfExecutedLines(),
    $report->numberOfExecutableLines(),
    $lines->asString(),
    $report->numberOfTestedMethods(),
    $report->numberOfMethods(),
    $methods->asString(),
    $report->numberOfTestedClassesAndTraits(),
    $report->numberOfClassesAndTraits(),
    $classes->asString(),
    count($paths),
);
