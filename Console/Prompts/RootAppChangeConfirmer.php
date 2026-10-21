<?php

namespace Studeo\Modular\Console\Prompts;

use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Studeo\Modular\Console\Actions\CheckInertiaVueAction;
use Studeo\Modular\Console\Actions\CheckLivewireAction;
use Studeo\Modular\Console\Actions\CheckViteConfigurationAction;
use Studeo\Modular\Console\Actions\InstallInertiaVueAction;
use Studeo\Modular\Console\Actions\InstallLivewireAction;
use Studeo\Modular\Console\Actions\ScaffoldInertiaFrontendAction;
use Studeo\Modular\DataObjects\InertiaVueStatus;
use Studeo\Modular\DataObjects\ViteStatus;
use Studeo\Modular\Support\FrontendInstructions;

use function Laravel\Prompts\confirm;

/**
 * The root app belongs to the user: nothing here writes to it or runs an installer in it without
 * a yes. Without a terminal to ask, the same text is printed as instructions and nothing runs.
 */
final class RootAppChangeConfirmer
{
    public function __construct(private readonly Container $container) {}

    public function ensureInertiaVueIsReady(PromptConsole $console): void
    {
        $status = $this->container->make(CheckInertiaVueAction::class)->execute();
        $installer = $this->container->make(InstallInertiaVueAction::class);

        if ($status->isUnsupported()) {
            throw new RuntimeException($this->unsupportedInertiaMessage($status, $installer->upgradeCommands($status)));
        }

        if (! $status->isInstalled()) {
            $this->installInertiaVue($console, $status, $installer);
        }
    }

    public function ensureLivewireIsReady(PromptConsole $console): void
    {
        if ($this->container->make(CheckLivewireAction::class)->execute()->isInstalled()) {
            return;
        }

        $command = implode(' ', InstallLivewireAction::COMMAND);

        $console->line("Livewire 4 is required to generate Livewire components. To install it:\n  {$command}");

        if (! $this->confirmed($console, 'Livewire 4 is not installed in this project. Install it with composer require livewire/livewire:^4.0?')) {
            throw new RuntimeException("Livewire is not installed. Nothing was changed. Install it with:\n  {$command}");
        }

        $console->infoBadge('Installing Livewire 4 with Composer...');
        $this->container->make(InstallLivewireAction::class)->execute();
    }

    public function ensureViteIsConfigured(PromptConsole $console): void
    {
        $status = $this->container->make(CheckViteConfigurationAction::class)->execute();

        $this->printCustomFrontendInstructions($console, $status);

        if ($status->pendingWrites() === []) {
            return;
        }

        $scaffold = $this->container->make(ScaffoldInertiaFrontendAction::class);
        $this->printPendingWrites($console, $status, $scaffold->plan($status));

        if ($this->confirmed($console, 'Write the Vite and Inertia files listed above?')) {
            $scaffold->execute($status);

            return;
        }

        $console->line('Nothing was written to your project. Create or change the files above yourself.');
    }

    private function confirmed(PromptConsole $console, string $question): bool
    {
        return $console->canPrompt() && confirm($question);
    }

    /**
     * @param  array<int, array<int, string>>  $commands
     */
    private function unsupportedInertiaMessage(InertiaVueStatus $status, array $commands): string
    {
        $found = implode(', ', array_map(fn (string $package): string => $package.' '.$this->majorOf($status, $package).'.x', $status->unsupported()));

        return "Inertia {$found} is installed, and this package supports Inertia ".InertiaVueStatus::SUPPORTED_MAJOR." only. Nothing was changed. Upgrade it yourself with:\n".$this->indented($commands);
    }

    private function majorOf(InertiaVueStatus $status, string $package): ?int
    {
        return $package === InertiaVueStatus::SERVER ? $status->serverMajor : $status->clientMajor;
    }

    private function installInertiaVue(PromptConsole $console, InertiaVueStatus $status, InstallInertiaVueAction $installer): void
    {
        $commands = $installer->commands($status);

        $console->line("Inertia 3 and Vue are required to generate Vue components. To install them:\n".$this->indented($commands));

        if (! $this->confirmed($console, 'Inertia 3 and Vue are not installed in this project. Install them with the commands above?')) {
            throw new RuntimeException('Inertia 3 and Vue are not installed. Nothing was changed. Run the commands above yourself, then run this command again.');
        }

        $console->infoBadge('Installing Inertia 3 and Vue...');
        $installer->execute($status);
    }

    /**
     * @param  array<string, string>  $plan  path => contents
     */
    private function printPendingWrites(PromptConsole $console, ViteStatus $status, array $plan): void
    {
        $console->line('The Vite and Inertia root files need these changes:');

        foreach ($plan as $path => $contents) {
            $console->line("<comment>{$status->pendingWrites()[$path]} {$path}</comment>");
            $console->line($contents);
        }
    }

    private function printCustomFrontendInstructions(PromptConsole $console, ViteStatus $status): void
    {
        $instructions = FrontendInstructions::for($status);

        if ($instructions !== '') {
            $console->line('<comment>Notice:</comment> '.$instructions);
        }
    }

    /**
     * @param  array<int, array<int, string>>  $commands
     */
    private function indented(array $commands): string
    {
        return implode("\n", array_map(fn (array $command): string => '  '.implode(' ', $command), $commands));
    }
}
