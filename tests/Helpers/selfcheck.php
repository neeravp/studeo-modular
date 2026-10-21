<?php

use Studeo\Check\Engine\Rule;
use Studeo\Check\Engine\Scope;
use Studeo\Check\Engine\Violation;
use Studeo\Check\Rules\ConditionExtraction;
use Studeo\Check\Rules\FileLength;
use Studeo\Check\Rules\MethodComplexity;
use Studeo\Check\Rules\MethodNaming;
use Studeo\Check\Rules\NestingDepth;
use Studeo\Check\Rules\StepCount;
use Studeo\Modular\Check\ModuleSource;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * The package's own limits: complexity, the CAP 1 rules, file length and naming over every top-level source
 * directory of the root, file length alone over tests/. The directories are the root's
 * top-level directories that hold tracked PHP (see selfCheckSourceDirectories()).
 *
 * @return list<Violation>
 */
function selfCheckViolations(string $root): array
{
    $rules = selfCheckSourceRules();
    $sources = array_map(fn (string $directory): array => selfCheckRun($root, $directory, $rules), selfCheckSourceDirectories($root));
    $tests = is_dir("{$root}/tests") ? selfCheckRun($root, 'tests', [FileLength::options(scope: Scope::ALL)]) : [];

    return array_merge($tests, ...$sources);
}

/**
 * @return list<Rule>
 */
function selfCheckSourceRules(): array
{
    $whole = fn (string $rule): Rule => $rule::options(scope: Scope::ALL);

    return [...array_map($whole, [MethodComplexity::class, StepCount::class, NestingDepth::class, ConditionExtraction::class, FileLength::class]), MethodNaming::options()];
}

/**
 * The keys of a package root, computed in a fresh PHP process so the parsed trees never stay in
 * the memory of the test run.
 *
 * @return list<string>
 */
function selfCheckKeysInFreshProcess(string $root): array
{
    $process = new Process([PHP_BINARY, dirname(__DIR__).'/fixtures/self-check.php', $root]);
    $process->setTimeout(120);
    $process->setInput('');
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The top-level directories of the root that hold tracked PHP files, minus the vendored and test
 * directories. Only git decides what is tracked; there is no fallback to the disk.
 *
 * @return list<string>
 */
function selfCheckSourceDirectories(string $root): array
{
    $segments = array_map(fn (string $path): string => explode('/', $path)[0], array_filter(selfCheckTrackedPhpFiles($root), fn (string $path): bool => str_contains($path, '/')));
    $directories = array_diff(array_unique($segments), ['vendor', 'node_modules', 'tests']);
    sort($directories);

    return array_values($directories);
}

/**
 * @return list<string>
 */
function selfCheckTrackedPhpFiles(string $root): array
{
    $process = new Process(['git', 'ls-files', '--', '*.php'], $root);

    try {
        $process->mustRun();
    } catch (ExceptionInterface $exception) {
        throw new RuntimeException("The self-check needs git to list the tracked PHP files in {$root}: {$exception->getMessage()}", 0, $exception);
    }

    return array_values(array_filter(explode("\n", $process->getOutput())));
}

/**
 * @param  list<Rule>  $rules
 * @return list<Violation>
 */
function selfCheckRun(string $root, string $directory, array $rules): array
{
    $source = new ModuleSource(new ModuleDefinition($directory, $directory, "{$root}/{$directory}"), [], $root);

    return array_merge([], ...array_map(fn (Rule $rule): array => $rule->violations($source), $rules));
}

/**
 * Stable keys, "rule file subject", sorted and unique. Line numbers are never part of a key.
 *
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function selfCheckKeys(array $violations): array
{
    $keys = array_unique(array_map(fn (Violation $violation): string => "{$violation->rule} {$violation->file} {$violation->subject}", $violations));
    sort($keys);

    return array_values($keys);
}

/**
 * @return list<string>
 */
function selfCheckBaseline(string $path): array
{
    return array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: [])));
}

/**
 * @param  list<string>  $actual
 * @param  list<string>  $baseline
 * @return array{new: list<string>, stale: list<string>}
 */
function selfCheckDifferences(array $actual, array $baseline): array
{
    return ['new' => array_values(array_diff($actual, $baseline)), 'stale' => array_values(array_diff($baseline, $actual))];
}
