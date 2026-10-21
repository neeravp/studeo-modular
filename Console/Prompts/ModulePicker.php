<?php

namespace Studeo\Modular\Console\Prompts;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\ResolveModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;

use function Laravel\Prompts\select;

/**
 * The module a command works in: the one given (its manifest must be usable), otherwise the only
 * discovered module, or the one chosen from a list. Directories that discovery skips are never
 * offered.
 */
final class ModulePicker
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string>|bool|string|null  $given  the value of the command's module option; null means pick
     */
    public function resolve(PromptConsole $console, array|bool|string|null $given): ModuleDefinition
    {
        return $given === null ? $this->pickModule($console) : $this->resolveGivenModule((string) $given);
    }

    /**
     * @param  array<string, string>  $skipped  directory name => reason
     */
    public function noModulesMessage(array $skipped): string
    {
        $lines = ['No modules found. Create one with [php artisan module:make Blog].'];

        foreach ($skipped as $directory => $reason) {
            $lines[] = "Skipped [{$directory}]: {$reason}";
        }

        return implode("\n", $lines);
    }

    private function resolveGivenModule(string $given): ModuleDefinition
    {
        return blank($given)
            ? throw new InvalidArgumentException('The module name is required.')
            : $this->container->make(ResolveModuleAction::class)->execute($given);
    }

    private function pickModule(PromptConsole $console): ModuleDefinition
    {
        $discovery = $this->container->make(DiscoverModulesAction::class);
        $modules = $discovery->execute();

        return match (count($modules)) {
            0 => throw new RuntimeException($this->noModulesMessage($discovery->skipped())),
            1 => $this->useOnly($console, reset($modules)),
            default => $this->chooseModule($console, $modules),
        };
    }

    private function useOnly(PromptConsole $console, ModuleDefinition $module): ModuleDefinition
    {
        $console->announceModule("Using module [{$module->name}].");

        return $module;
    }

    /**
     * @param  array<string, ModuleDefinition>  $modules
     */
    private function chooseModule(PromptConsole $console, array $modules): ModuleDefinition
    {
        $names = array_keys($modules);

        if (! $console->canPrompt()) {
            throw new RuntimeException('There is more than one module ('.implode(', ', $names).'). Pass --module=<name> to choose one.');
        }

        return $modules[(string) select(label: 'Which module should this belong to?', options: $names)];
    }
}
