<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Loads every PHP class of a module in a fresh PHP process with stdin closed (see tests/fixtures/load-module.php).
 * The process prints a JSON object with the `loaded` names and the `errors` found; a fatal error
 * ends it with a non-zero exit code and its message on stderr.
 */
function loadModuleInFreshProcess(string $modulePath): Process
{
    $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', dirname(__DIR__).'/fixtures/load-module.php', dirname(__DIR__, 2).'/vendor/autoload.php', $modulePath]);
    $process->setInput('');
    $process->run();

    return $process;
}

/**
 * A hand-written module (Studeo\Blog) holding the given files, module-relative path => contents.
 *
 * @param  array<string, string>  $files
 */
function brokenModule(object $test, array $files): string
{
    $files['module.json'] = json_encode(['name' => 'Blog', 'namespace' => 'Studeo\Blog']);

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname("{$test->brokenPath}/{$path}"));
        File::put("{$test->brokenPath}/{$path}", $contents);
    }

    return $test->brokenPath;
}
