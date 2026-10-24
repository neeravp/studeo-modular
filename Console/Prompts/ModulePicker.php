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
 * offered. A command that can act on every module asks through resolveOrAll(), which adds an All
 * modules choice to the list.
 */
final class ModulePicker
{
    public const ALL_MODULES = 'All modules';

    private const BELONGS_TO_LABEL = 'Which module should this belong to?';

    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string>|bool|string|null  $given  the value of the command's module option; null means pick
     */
    public function resolve(PromptConsole $console, array|bool|string|null $given): ModuleDefinition
    {
        return $given === null ? $this->pickModule($console) : $this->resolveGivenModule((string) $given);
    }

    /**
     * Like resolve(), but a list of several modules ends with an All modules choice, asked with
     * the given label; null means every module.
     */
    public function resolveOrAll(PromptConsole $console, ?string $given, string $label): ?ModuleDefinition
    {
        return $given === null ? $this->pickModuleOrAll($console, $label) : $this->resolveGivenModule($given);
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

    private function pickModuleOrAll(PromptConsole $console, string $label): ?ModuleDefinition
    {
        $discovery = $this->container->make(DiscoverModulesAction::class);
        $modules = $discovery->execute();

        return match (count($modules)) {
            0 => throw new RuntimeException($this->noModulesMessage($discovery->skipped())),
            1 => $this->useOnly($console, reset($modules)),
            default => $this->chooseModuleOrAll($console, $modules, $label),
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
        return $modules[$this->askForName($console, array_keys($modules), array_keys($modules), self::BELONGS_TO_LABEL)];
    }

    /**
     * @param  array<string, ModuleDefinition>  $modules
     */
    private function chooseModuleOrAll(PromptConsole $console, array $modules, string $label): ?ModuleDefinition
    {
        $names = array_keys($modules);
        $answer = $this->askForName($console, $names, [...$names, self::ALL_MODULES], $label);

        return $answer === self::ALL_MODULES ? null : $modules[$answer];
    }

    /**
     * @param  list<string>  $names  the modules, for the message when there is no terminal
     * @param  list<string>  $options  what the list offers
     */
    private function askForName(PromptConsole $console, array $names, array $options, string $label): string
    {
        if (! $console->canPrompt()) {
            throw new RuntimeException('There is more than one module ('.implode(', ', $names).'). Pass --module=<name> to choose one.');
        }

        return (string) select(label: $label, options: $options);
    }
}
