<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Filesystem\Filesystem;
use Studeo\Modular\Console\Generation\StubRenderer;
use Studeo\Modular\DataObjects\LivewireFormPlan;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\LivewireFormContent;

/**
 * Writes what a planned form needs and leaves alone what exists: the Action and its Input
 * (through the writer every form generator shares) and the Form class.
 */
final class WriteLivewireFormAction
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly WriteFormActionAction $actions,
        private readonly StubRenderer $stubRenderer,
    ) {}

    /**
     * @return array{created: array<int, string>, reused: array<int, string>, notices: array<int, string>}
     */
    public function execute(ModuleDefinition $module, LivewireFormPlan $plan, SchemaDefinition $schema): array
    {
        $written = $this->actions->execute($module, $plan->action, $schema);

        $this->files->ensureDirectoryExists(dirname($plan->formPath));
        $this->files->put($plan->formPath, LivewireFormContent::form($this->files->get($this->stubRenderer->path('livewire.form.php.stub')), $plan));

        return [...$written, 'created' => [...$written['created'], $plan->formPath]];
    }
}
