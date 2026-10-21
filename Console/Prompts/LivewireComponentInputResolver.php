<?php

namespace Studeo\Modular\Console\Prompts;

use InvalidArgumentException;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Exceptions\NoTerminalException;

use function Laravel\Prompts\select;

/**
 * The Livewire component a command works on: the one named on the command line, otherwise the one
 * chosen from the module's components. A module without components is said so and gives none.
 * Without a terminal a missing name is an error that shows the example, never a select.
 */
final class LivewireComponentInputResolver
{
    public function __construct(private readonly DiscoverLivewireComponentsAction $discoverer) {}

    /**
     * @param  array<string>|string|null  $name  the value of the command's name argument; null means choose
     * @param  string  $example  a complete command that names the component, shown when there is no terminal to choose in
     */
    public function resolve(
        PromptConsole $console,
        ModuleDefinition $module,
        array|string|null $name,
        string $example,
        string $promptVerb = 'select',
    ): ?LivewireComponentDefinition {
        return $name !== null
            ? $this->resolveExplicitComponent($module, $name)
            : $this->resolveInteractiveComponent($console, $module, $promptVerb, $example);
    }

    /**
     * @param  array<string>|string  $given
     */
    private function resolveExplicitComponent(ModuleDefinition $module, array|string $given): LivewireComponentDefinition
    {
        $name = (string) $given;
        $component = $this->discoverer->find($module, $name);

        return $component ?? $this->failComponentNotFound($module, $name);
    }

    private function resolveInteractiveComponent(
        PromptConsole $console,
        ModuleDefinition $module,
        string $promptVerb,
        string $example,
    ): ?LivewireComponentDefinition {
        $components = $this->discoverer->forModule($module);

        return empty($components)
            ? $this->warnNoComponentsFound($console, $module)
            : $this->promptForLivewireComponent($console, $components, $module, $promptVerb, $example);
    }

    /**
     * @param  array<LivewireComponentDefinition>  $components
     */
    private function promptForLivewireComponent(
        PromptConsole $console,
        array $components,
        ModuleDefinition $module,
        string $promptVerb,
        string $example,
    ): LivewireComponentDefinition {
        if (! $console->canPrompt()) {
            throw new NoTerminalException('the [name] argument', $example);
        }

        $options = $this->formatPromptOptions($components);

        $selectedName = select(
            label: "Which Livewire component would you like to {$promptVerb} from [{$module->name}]?",
            options: $options,
        );

        return $this->discoverer->find($module, (string) $selectedName);
    }

    /**
     * @param  array<LivewireComponentDefinition>  $components
     * @return array<string, string>
     */
    private function formatPromptOptions(array $components): array
    {
        $options = [];
        foreach ($components as $component) {
            $options[$component->name] = "{$component->name} ({$component->type})";
        }

        return $options;
    }

    private function failComponentNotFound(ModuleDefinition $module, string $name): never
    {
        throw new InvalidArgumentException("Livewire component [{$name}] does not exist in module [{$module->name}].");
    }

    private function warnNoComponentsFound(PromptConsole $console, ModuleDefinition $module): null
    {
        $console->warn("No Livewire components found in module [{$module->name}].");

        return null;
    }
}
