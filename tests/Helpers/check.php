<?php

use Illuminate\Support\Facades\File;
use Studeo\Check\Engine\Rule;
use Studeo\Check\Engine\Violation;
use Studeo\Modular\Check\ModuleSource;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Process\Process;

/**
 * A throwaway Blog module on disk (namespace Studeo\Blog) holding the given files, as
 * module-relative path => contents, next to the modules named in $others (name => namespace).
 *
 * @param  array<string, string>  $files
 * @param  array<string, string>  $others
 */
function checkSource(array $files, array $others = []): ModuleSource
{
    $base = sys_get_temp_dir().'/modular-check-'.bin2hex(random_bytes(6));
    $path = $base.'/modules/Blog';

    foreach ($files as $relative => $contents) {
        if (! is_dir(dirname("{$path}/{$relative}"))) {
            mkdir(dirname("{$path}/{$relative}"), 0777, true);
        }

        file_put_contents("{$path}/{$relative}", $contents);
    }

    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($base)));

    $prefixes = ['Studeo\\Blog\\' => 'Blog'];

    foreach ($others as $name => $namespace) {
        $prefixes[$namespace.'\\'] = $name;
    }

    return new ModuleSource(new ModuleDefinition('Blog', 'Studeo\\Blog', $path), $prefixes, $base);
}

/**
 * @param  array<string, string>  $files
 * @param  array<string, string>  $others
 * @return array<int, Violation>
 */
function runRule(Rule $rule, array $files, array $others = []): array
{
    return $rule->violations(checkSource($files, $others));
}

/**
 * The tuples a test compares: line, subject and message, so a rule's output is read at a glance.
 *
 * @param  array<int, Violation>  $violations
 * @return array<int, string>
 */
function summarise(array $violations): array
{
    return array_map(fn (Violation $violation): string => "{$violation->file}:{$violation->line} {$violation->subject}", $violations);
}

/**
 * A PHP file in a namespace of the Blog module.
 */
function phpFile(string $body, string $namespace = 'Studeo\\Blog\\Actions', string $uses = ''): string
{
    return "<?php\n\nnamespace {$namespace};\n\n{$uses}\n{$body}\n";
}

/**
 * A real artisan command in a fresh PHP process whose stdin is closed, in an app that loads the
 * package and a provider that can point the rule set at a fixture class.
 *
 * @param  array<string, string>  $env
 */
function checkCommand(string $work, array $arguments, array $env = []): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/testbench', ...$arguments], $work, ['APP_ENV' => 'local', ...$env]);
    $process->setTimeout(120);
    $process->setInput('');
    $process->run();

    return $process;
}

/** Runs a setup command that must succeed. */
function runOk(string $work, array $arguments): void
{
    $process = checkCommand($work, $arguments);

    expect($process->getExitCode())->toBe(0, checkOutput($process));
}

function checkOutput(Process $process): string
{
    return $process->getOutput().$process->getErrorOutput();
}

/** @param array<string, string> $files module-relative path => contents */
function seedModule(object $test, string $module, array $files): void
{
    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname("{$test->rootPath}/{$module}/{$path}"));
        File::put("{$test->rootPath}/{$module}/{$path}", $contents);
    }
}

function longFile(int $lines): string
{
    return "<?php\n\nnamespace Blog\\Services;\n\nfinal class Long\n{\n".str_repeat("    public int \$a = 1;\n", $lines - 7)."}\n";
}
