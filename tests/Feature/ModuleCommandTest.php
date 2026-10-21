<?php

use Studeo\Modular\Console\ModuleCommand;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

it('resolves a module through the picker', function () {
    $command = new class extends ModuleCommand
    {
        protected $signature = 'test:module {--module=}';

        public ?ModuleDefinition $resolvedModule = null;

        public function handle(ModulePicker $picker): int
        {
            $this->resolvedModule = $picker->resolve($this, $this->option('module'));

            return self::SUCCESS;
        }
    };

    $this->app->make('Illuminate\Contracts\Console\Kernel')
        ->registerCommand($command);

    $this->artisan('test:module', [
        '--module' => 'Account',
    ])
        ->assertSuccessful();

    expect($command->resolvedModule)
        ->toBeInstanceOf(ModuleDefinition::class)
        ->and($command->resolvedModule->name)
        ->toBe('Account')
        ->and($command->resolvedModule->namespace)
        ->toBe('Studeo\\Account')
        ->and($command->resolvedModule->path)
        ->toBe(
            $this->app->basePath('modules/Account')
        );
});
