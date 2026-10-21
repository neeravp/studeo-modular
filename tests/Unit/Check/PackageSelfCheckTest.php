<?php

use Symfony\Component\Process\Process;

const SELF_CHECK_BASELINE = __DIR__.'/../../self-check-baseline.txt';

/**
 * A throwaway package root that is a git repository tracking $files (root-relative path =>
 * contents); $untracked are written to disk but never added.
 *
 * @param  array<string, string>  $files
 * @param  array<string, string>  $untracked
 */
function selfCheckRoot(array $files, array $untracked = []): string
{
    $root = sys_get_temp_dir().'/modular-self-check-'.bin2hex(random_bytes(6));

    foreach ([...$files, ...$untracked] as $relative => $contents) {
        if (! is_dir(dirname("{$root}/{$relative}"))) {
            mkdir(dirname("{$root}/{$relative}"), 0777, true);
        }

        file_put_contents("{$root}/{$relative}", $contents);
    }

    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

    selfCheckGit($root, ['init', '-q']);
    array_map(fn (string $relative) => selfCheckGit($root, ['add', '--', $relative]), array_keys($files));

    return $root;
}

/**
 * @param  list<string>  $arguments
 */
function selfCheckGit(string $root, array $arguments): void
{
    $process = new Process(['git', ...$arguments], $root);
    $process->mustRun();
}

function busyFileIn(string $namespace): string
{
    return "<?php\n\nnamespace {$namespace};\n\nfinal class Busy\n{\n    public function go(\$a, \$b, \$c)\n    {\n        if (\$a) {\n            return 1;\n        }\n        if (\$b) {\n            return 2;\n        }\n        if (\$c) {\n            return 3;\n        }\n\n        return 0;\n    }\n}\n";
}

it('keeps the package inside its own limits, with a baseline that can only shrink', function () {
    $differences = selfCheckDifferences(selfCheckKeysInFreshProcess(dirname(__DIR__, 3)), selfCheckBaseline(SELF_CHECK_BASELINE));

    expect($differences['new'])->toBe([], 'violations missing from tests/self-check-baseline.txt')
        ->and($differences['stale'])->toBe([], 'baseline entries that are no longer violated; delete them');
});

it('checks every top-level source directory with three rules and the tests with file length alone', function () {
    $root = selfCheckRoot([
        'Check/Busy.php' => busyFileIn('Pkg\\Check'),
        'Zeta/Busy.php' => busyFileIn('Pkg\\Zeta'),
        'Zeta/Naming.php' => "<?php\n\nnamespace Pkg\\Zeta;\n\nfinal class Naming\n{\n    public function doWork(): void {}\n}\n",
        'Zeta/Long.php' => "<?php\n".str_repeat("\$a = 1;\n", 240),
        'tests/LongTest.php' => "<?php\n".str_repeat("\$a = 1;\n", 240),
        'tests/BusyTest.php' => busyFileIn('Pkg\\Tests'),
        'vendor/Busy.php' => busyFileIn('Vendor'),
        'node_modules/Busy.php' => busyFileIn('Node'),
        'docs/readme.txt' => 'no php here',
    ]);

    expect(selfCheckKeys(selfCheckViolations($root)))->toBe([
        'file-length Zeta/Long.php file',
        'file-length tests/LongTest.php file',
        'method-complexity Check/Busy.php Busy::go',
        'method-complexity Zeta/Busy.php Busy::go',
        'method-naming Zeta/Naming.php Naming::doWork',
    ]);
});

it('runs the CAP 1 rules over the source directories but not over tests', function () {
    $cap1 = "<?php\n\nnamespace Pkg\\Support;\n\nfinal class Wide\n{\n    public function go(\$a, \$b)\n    {\n        if (\$a && \$b) {\n            foreach (\$a as \$c) {\n                echo \$c;\n            }\n        }\n".str_repeat("        \$x = 1;\n", 8)."    }\n}\n";
    $root = selfCheckRoot(['Support/Wide.php' => $cap1, 'tests/WideTest.php' => $cap1]);

    expect(selfCheckKeys(selfCheckViolations($root)))->toBe([
        'condition-extraction Support/Wide.php Wide::go',
        'nesting-depth Support/Wide.php Wide::go',
        'step-count Support/Wide.php Wide::go',
    ]);
});

it('fails on a seeded violation that the baseline does not list', function () {
    $root = selfCheckRoot(['Support/Seeded.php' => busyFileIn('Pkg\\Support')]);

    $differences = selfCheckDifferences(selfCheckKeys(selfCheckViolations($root)), []);

    expect($differences['new'])->toBe(['method-complexity Support/Seeded.php Busy::go'])
        ->and($differences['stale'])->toBe([]);
});

it('fails on a baseline entry that is no longer violated', function () {
    $root = selfCheckRoot(['Support/Clean.php' => "<?php\n\nnamespace Pkg\\Support;\n\nfinal class Clean {}\n"]);

    $differences = selfCheckDifferences(selfCheckKeys(selfCheckViolations($root)), ['file-length Support/Clean.php file']);

    expect($differences['new'])->toBe([])
        ->and($differences['stale'])->toBe(['file-length Support/Clean.php file']);
});

it('passes when the baseline matches exactly, and keys carry no line numbers', function () {
    $root = selfCheckRoot(['Support/Seeded.php' => busyFileIn('Pkg\\Support')]);
    $keys = selfCheckKeys(selfCheckViolations($root));

    expect(selfCheckDifferences($keys, $keys))->toBe(['new' => [], 'stale' => []])
        ->and($keys[0])->not->toMatch('/:\d+/');
});

it('reads a baseline of sorted unique keys, one per line', function () {
    $baseline = selfCheckBaseline(SELF_CHECK_BASELINE);

    $sorted = array_values(array_unique($baseline));
    sort($sorted);

    expect($baseline)->toBe($sorted)
        ->and(str_contains((string) file_get_contents(SELF_CHECK_BASELINE), dirname(__DIR__, 3)))->toBeFalse();
});

it('checks tracked directories only: an untracked top-level directory holding PHP is ignored', function () {
    $root = selfCheckRoot(
        ['Support/Clean.php' => "<?php\n\nnamespace Pkg\\Support;\n\nfinal class Clean {}\n"],
        ['Scratch/Busy.php' => busyFileIn('Pkg\\Scratch')],
    );

    expect(selfCheckSourceDirectories($root))->toBe(['Support'])
        ->and(selfCheckKeys(selfCheckViolations($root)))->toBe([]);
});

it('discovers the tracked top-level directories that hold PHP, and none by name but the three exclusions', function () {
    $root = selfCheckRoot([
        'Alpha/A.php' => "<?php\n",
        'Gamma/B.php' => "<?php\n",
        'Beta/Deep/C.php' => "<?php\n",
        'Root.php' => "<?php\n",
        'docs/readme.txt' => 'x',
        'vendor/D.php' => "<?php\n",
        'node_modules/E.php' => "<?php\n",
        'tests/F.php' => "<?php\n",
    ]);

    expect(selfCheckSourceDirectories($root))->toBe(['Alpha', 'Beta', 'Gamma']);
});

it('fails with a clear message when git cannot list the files, and never scans the disk instead', function () {
    $root = sys_get_temp_dir().'/modular-self-check-nogit-'.bin2hex(random_bytes(6));
    mkdir("{$root}/Support", 0777, true);
    file_put_contents("{$root}/Support/Busy.php", busyFileIn('Pkg\\Support'));
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

    expect(fn () => selfCheckViolations($root))->toThrow(RuntimeException::class, 'The self-check needs git to list the tracked PHP files');
});
