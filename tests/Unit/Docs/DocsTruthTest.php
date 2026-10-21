<?php

use Illuminate\Support\Arr;
use Studeo\Modular\Check\DefaultRules;
use Studeo\Modular\Check\ModuleLayout;
use Studeo\Modular\Support\ModuleNamespace;
use Studeo\Modular\Tests\Support\PackageDocs;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Command\Command;

uses(TestCase::class);

it('lists in SPEC every command the package registers, and no other', function () {
    $section = PackageDocs::section('SPEC.md', '## 4. Commands');
    $documented = PackageDocs::matches('/`((?:module|modular):[a-z:]+)`/', explode('### Exit codes', $section)[0]);

    expect(PackageDocs::sorted($documented))->toBe(PackageDocs::sorted(array_keys(PackageDocs::commands())));
});

it('names in README only registered commands or their aliases, and every visible command', function () {
    $commands = PackageDocs::commands();
    $known = array_merge(array_keys($commands), ...array_map(fn (Command $command): array => $command->getAliases(), array_values($commands)));
    $visible = array_keys(array_filter($commands, fn (Command $command): bool => ! $command->isHidden()));
    $mentioned = PackageDocs::matches('/`((?:module|modular):[a-z:]+)`/', PackageDocs::read('README.md'));

    expect(array_diff(PackageDocs::sorted($mentioned), $known))->toBe([])
        ->and(array_diff($visible, $mentioned))->toBe([]);
});

it('names only command options that some command defines or that belong to another tool', function () {
    $defined = [];
    foreach (PackageDocs::commands() as $command) {
        $defined = [...$defined, ...array_map(fn ($option): string => '--'.$option->getName(), array_values($command->getDefinition()->getOptions()))];
    }
    $otherTools = ['--no-interaction', '--quiet', '--group', '--fail-on-skipped', '--order-by', '--memory-limit', '--test', '--dirty', '--parallel', '--tag'];
    $mentioned = [];
    foreach (['README.md', 'SPEC.md', 'API.md'] as $file) {
        $mentioned = [...$mentioned, ...PackageDocs::matches('/(?<![\w-])(--[a-z][a-z-]*)/', PackageDocs::read($file))];
    }

    expect(array_diff(PackageDocs::sorted($mentioned), $defined, $otherTools))->toBe([]);
});

it('states the single-letter shortcuts the commands really have', function () {
    $actual = [];
    foreach (PackageDocs::commands() as $command) {
        $actual = [...$actual, ...array_values(array_filter(array_map(fn ($option) => $option->getShortcut(), array_values($command->getDefinition()->getOptions()))))];
    }
    $line = PackageDocs::matches('/^- Options are long-form\.(.+)$/m', PackageDocs::read('SPEC.md'))[0] ?? '';

    expect(PackageDocs::sorted(array_map(fn (string $flag): string => ltrim($flag, '-'), PackageDocs::matches('/`(-[a-z])`/', $line))))->toBe(PackageDocs::sorted($actual));
});

it('lists in SPEC the directories a module may hold, as the layout constants declare them', function () {
    $spec = PackageDocs::read('SPEC.md');

    expect(PackageDocs::bulletEntries($spec, 'Class directories'))->toBe(ModuleLayout::CLASS_DIRECTORIES)
        ->and(PackageDocs::bulletEntries($spec, 'Data directories'))->toBe(ModuleLayout::DATA_DIRECTORIES);
});

it('lists in SPEC the reserved first namespace segments', function () {
    $line = PackageDocs::matches('/^(The first namespace segment may not be.+)$/m', PackageDocs::read('SPEC.md'))[0] ?? '';

    expect(PackageDocs::sorted(PackageDocs::matches('/`([A-Za-z]+)`/', $line)))->toBe(PackageDocs::sorted(ModuleNamespace::RESERVED));
});

it('documents exactly the keys of the configuration file', function () {
    $keys = array_keys(Arr::dot(config('modular')));
    $documented = PackageDocs::matches('/^\| `([a-z_.]+)` \|/m', PackageDocs::section('SPEC.md', '## 10. Configuration'));

    expect(PackageDocs::sorted($keys))->toBe(PackageDocs::sorted($documented));
});

it('lists in README the default rules that module:check runs', function () {
    $line = PackageDocs::matches('/^The default rules are (.+)$/m', PackageDocs::read('README.md'))[0] ?? '';
    $actual = array_map(fn ($rule): string => $rule->name(), (new DefaultRules)->rules()->rules());

    expect(PackageDocs::sorted(PackageDocs::matches('/`([a-z-]+)`/', $line)))->toBe(PackageDocs::sorted($actual));
});

it('states the majors of the optional integrations that the dependency files declare', function () {
    $composer = json_decode(PackageDocs::read('composer.json'), true);
    $npm = json_decode(PackageDocs::read('package.json'), true);
    $major = fn (string $constraint): string => preg_replace('/^\D*(\d+).*$/', '$1', $constraint);
    $readme = PackageDocs::read('README.md');

    expect(PackageDocs::matches('/^\| Livewire (\d+) \|/m', $readme))->toBe([$major($composer['require-dev']['livewire/livewire'])])
        ->and(PackageDocs::matches('/^\| Inertia (\d+) and Vue \d+ \|/m', $readme))->toBe([$major($composer['require-dev']['inertiajs/inertia-laravel'])])
        ->and(PackageDocs::matches('/^\| Inertia \d+ and Vue (\d+) \|/m', $readme))->toBe([$major($npm['devDependencies']['vue'])])
        ->and(PackageDocs::matches('/^\| Inertia (\d+) and Vue \d+ \|/m', $readme))->toBe([$major($npm['devDependencies']['@inertiajs/vue3'])]);
});
