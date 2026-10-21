<?php

namespace Studeo\Modular\Console\Prompts;

use InvalidArgumentException;
use Studeo\Modular\Console\Actions\DiscoverVueComponentsAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentDefinition;
use Studeo\Modular\Exceptions\NoTerminalException;

use function Laravel\Prompts\select;

/**
 * The Vue component or Inertia page a command works on: the one named on the command line,
 * otherwise the one chosen from the module's components, narrowed to pages or components when the
 * command says which it wants. A module with none of that kind is said so and gives none.
 * Without a terminal a missing name is an error that shows the example, never a select.
 */
final class VueComponentInputResolver
{
    public function __construct(private readonly DiscoverVueComponentsAction $discoverer) {}

    /**
     * @param  array<string>|string|null  $name  the value of the command's name argument; null means choose
     * @param  string  $example  a complete command that names the component, shown when there is no terminal to choose in
     * @param  bool|null  $isPage  true for pages only, false for components only, null for both
     */
    public function resolve(
        PromptConsole $console,
        ModuleDefinition $module,
        array|string|null $name,
        string $example,
        string $promptVerb = 'select',
        ?bool $isPage = null,
    ): ?VueComponentDefinition {
        return $name !== null
            ? $this->resolveExplicitComponent($module, $name, $isPage)
            : $this->resolveInteractiveComponent($console, $module, $promptVerb, $isPage, $example);
    }

    /**
     * @param  array<string>|string  $given
     */
    private function resolveExplicitComponent(
        ModuleDefinition $module,
        array|string $given,
        ?bool $isPage,
    ): VueComponentDefinition {
        $name = (string) $given;
        $component = $this->discoverer->find($module, $name, $isPage);

        return $component ?? $this->failComponentNotFound($module, $name, $isPage);
    }

    private function resolveInteractiveComponent(
        PromptConsole $console,
        ModuleDefinition $module,
        string $promptVerb,
        ?bool $isPage,
        string $example,
    ): ?VueComponentDefinition {
        $components = $this->filterComponentsByType($this->discoverer->forModule($module), $isPage);

        return empty($components)
            ? $this->warnNoComponentsFound($console, $module, $isPage)
            : $this->promptForVueComponent($console, $components, $module, $promptVerb, $example);
    }

    /**
     * @param  array<string, VueComponentDefinition>  $components
     */
    private function promptForVueComponent(
        PromptConsole $console,
        array $components,
        ModuleDefinition $module,
        string $promptVerb,
        string $example,
    ): VueComponentDefinition {
        if (! $console->canPrompt()) {
            throw new NoTerminalException('the [name] argument', $example);
        }

        $selectedKey = select(
            label: "Which Vue component would you like to {$promptVerb} from [{$module->name}]?",
            options: $this->formatPromptOptions($components),
        );

        return $components[(string) $selectedKey];
    }

    /**
     * @param  array<string, VueComponentDefinition>  $components
     * @return array<string, VueComponentDefinition>
     */
    private function filterComponentsByType(array $components, ?bool $isPage): array
    {
        if ($isPage === null) {
            return $components;
        }

        return array_filter(
            $components,
            fn (VueComponentDefinition $c): bool => $c->isPage() === $isPage
        );
    }

    /**
     * @param  array<string, VueComponentDefinition>  $components
     * @return array<string, string>
     */
    private function formatPromptOptions(array $components): array
    {
        return array_map(
            fn (VueComponentDefinition $c): string => "{$c->name} ({$c->displayType()})",
            $components
        );
    }

    private function failComponentNotFound(ModuleDefinition $module, string $name, ?bool $isPage): never
    {
        $type = $this->resolveComponentTypeLabel($isPage);

        throw new InvalidArgumentException("{$type} [{$name}] does not exist in module [{$module->name}].");
    }

    private function warnNoComponentsFound(PromptConsole $console, ModuleDefinition $module, ?bool $isPage): null
    {
        $type = $this->resolveComponentTypePlural($isPage);
        $console->warn("No {$type} found in module [{$module->name}].");

        return null;
    }

    private function resolveComponentTypeLabel(?bool $isPage): string
    {
        return match ($isPage) {
            true => 'Vue Page',
            false => 'Vue Component',
            null => 'Vue component',
        };
    }

    private function resolveComponentTypePlural(?bool $isPage): string
    {
        return match ($isPage) {
            true => 'Vue Pages',
            false => 'Vue Components',
            null => 'Vue components',
        };
    }
}
