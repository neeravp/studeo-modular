<?php

/**
 * Actions are single-purpose and return values; commands are the conductors that ask and print.
 * ReportModuleLoadFailuresAction is the one boot-time exception.
 */
const ACTION_CONSOLE_EXCEPTIONS = [
    'ReportModuleLoadFailuresAction',
];

const PROMPT_FUNCTIONS = [
    'text', 'textarea', 'password', 'confirm', 'select', 'multiselect', 'suggest', 'search', 'multisearch',
    'pause', 'spin', 'progress', 'info', 'warning', 'error', 'alert', 'note', 'intro', 'outro', 'table', 'form',
];

const CONSOLE_METHODS = [
    'ask', 'confirm', 'choice', 'secret', 'anticipate', 'info', 'line', 'warn', 'error', 'comment', 'newLine',
    'writeln', 'components', 'askQuestion',
];

/**
 * What the code of a file does with the console, read from its tokens so strings and comments
 * never count.
 *
 * @return array<int, string>
 */
function consoleUses(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn ($token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $uses = [];

    foreach ($tokens as $index => $token) {
        $text = is_array($token) ? $token[1] : $token;
        $previous = $tokens[$index - 1] ?? null;
        $previousText = is_array($previous) ? $previous[1] : $previous;
        $isCall = ($tokens[$index + 1] ?? null) === '(';
        $isMember = in_array($previousText, ['->', '?->', '::'], true);

        $uses[] = match (true) {
            is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_contains($text, 'Laravel\\Prompts') => "imports {$text}",
            is_array($token) && $token[0] === T_STRING && str_contains($text, 'OutputInterface') => "uses {$text}",
            is_array($token) && $token[0] === T_NAME_QUALIFIED && str_contains($text, 'OutputInterface') => "uses {$text}",
            is_array($token) && in_array($token[0], [T_ECHO, T_PRINT], true) => "uses {$text}",
            is_array($token) && $token[0] === T_STRING && in_array($text, ['STDERR', 'STDOUT'], true) => "uses {$text}",
            is_array($token) && $token[0] === T_STRING && $isCall && ! $isMember && $previousText !== 'function' && in_array($text, PROMPT_FUNCTIONS, true) => "calls {$text}()",
            is_array($token) && $token[0] === T_STRING && $isMember && $previousText !== '::' && in_array($text, CONSOLE_METHODS, true) && ($isCall || $text === 'components') => "calls ->{$text}",
            default => null,
        };
    }

    return array_values(array_filter($uses));
}

/**
 * @return array<string, string>
 */
function actionSources(): array
{
    $sources = [];

    foreach ([...glob(dirname(__DIR__, 2).'/Console/Actions/*.php'), dirname(__DIR__, 2).'/Support/ResolveModuleManifestAction.php'] as $path) {
        $sources[basename($path, '.php')] = file_get_contents($path);
    }

    return $sources;
}

it('has Actions in the directory it scans', function () {
    expect(actionSources())->not->toBeEmpty()->toHaveKey('InstallModuleAction');
});

it('recognises prompts and console output when it sees them', function () {
    $source = "<?php\nuse function Laravel\\Prompts\\confirm;\nuse Symfony\\Component\\Console\\Output\\OutputInterface;\nclass A { function f(OutputInterface \$o) { \$o->writeln('x'); \$this->info('y'); confirm('z'); echo 'q'; } }";

    expect(consoleUses($source))->toBe([
        'imports Laravel\\Prompts\\confirm',
        'uses Symfony\\Component\\Console\\Output\\OutputInterface',
        'uses OutputInterface',
        'calls ->writeln',
        'calls ->info',
        'calls confirm()',
        'uses echo',
    ]);
});

it('does not mistake strings, comments or domain methods for console use', function () {
    $source = "<?php\nclass A { /** it prints the echo line */ function f() { \$x = 'echo info()'; \$this->write('a'); \$this->preconditions->table(\$a); return \$text('action'); } }";

    expect(consoleUses($source))->toBe([]);
});

it('keeps prompts and console output out of every Action', function (string $name) {
    expect(consoleUses(actionSources()[$name]))->toBe([], "{$name} prompts or prints");
})->with(fn () => array_values(array_diff(array_keys(actionSources()), ACTION_CONSOLE_EXCEPTIONS)));

it('leaves the old installers behind', function () {
    foreach (['EnsureInertiaVueInstalledAction', 'EnsureLivewireInstalledAction', 'EnsureModularViteConfiguredAction'] as $retired) {
        expect(actionSources())->not->toHaveKey($retired);
    }
});

it('asks through canPrompt in the installer commands', function (string $command) {
    expect(file_get_contents(dirname(__DIR__, 2)."/Console/{$command}.php"))->not->toContain('isInteractive');
})->with(['ModuleInstallCommand', 'ModuleLivewireCommand', 'ModuleVueCommand', 'ModulePolicyCommand']);
