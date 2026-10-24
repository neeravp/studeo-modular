<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use InvalidArgumentException;
use Laravel\Prompts\Prompt;
use RuntimeException;
use Studeo\Check\Engine\CheckResult;
use Studeo\Modular\Console\Actions\CheckModulesAction;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\FormatCheckFindingsAction;
use Studeo\Modular\Console\Actions\ResolveModuleArgumentAction;
use Studeo\Modular\Console\Actions\ResolveRuleSetAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class ModuleCheckCommand extends ModuleCommand
{
    private const UNUSABLE = 2;

    private const PICKER_LABEL = 'Which module should be checked?';

    protected $signature = 'module:check
        {module? : The module name or its folder under the modules root}
        {--all : Check every module without asking}
        {--format=text : How to print the findings: text or json}';

    protected $description = 'Check one module, or every module, against the architecture rules';

    /**
     * Which modules: the one given as a name or folder; otherwise every module when --all is passed
     * or there is no terminal to ask in; otherwise the one chosen from a list that ends with All
     * modules.
     *
     * Exit 0 when there are no errors (warnings never fail), 1 for any error, 2 when a module
     * cannot be read, the argument is not a module, a directory was skipped while every module was
     * checked, the rule configuration is invalid, or the check itself fails. With --format=json
     * standard output holds only the findings array: on exit 2 from a failure it holds nothing, and
     * every message goes to stderr.
     */
    public function handle(ResolveRuleSetAction $ruleSets, CheckModulesAction $checker, DiscoverModulesAction $discovery, ModulePicker $picker, ResolveModuleArgumentAction $arguments, FormatCheckFindingsAction $format): int
    {
        try {
            $this->assertUsableOptions();
            $rules = $ruleSets->execute();
            $chosen = $this->chosenModule($picker, $arguments->execute($this->stringArgument('module')));
            $result = $checker->execute($this->modulesToCheck($chosen, $discovery, $picker), $rules);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->printFindings($format, $result);
        $skipped = $this->skippedDirectories($chosen, $discovery);
        $this->reportSkipped($skipped);

        return $this->exitCode($result, $skipped);
    }

    protected function failureExitCode(): int
    {
        return self::UNUSABLE;
    }

    protected function writeFailure(string $message, string $trace): void
    {
        $errors = $this->output->getErrorStyle();
        $errors->writeln("<error>{$message}</error>");

        if ($this->output->isVerbose()) {
            $errors->writeln($trace);
        }
    }

    public function announceModule(string $message): void
    {
        if ($this->isJson()) {
            $this->output->getErrorStyle()->writeln($message);

            return;
        }

        $this->line($message);
    }

    private function assertUsableOptions(): void
    {
        if (! in_array($this->option('format'), ['text', 'json'], true)) {
            throw new InvalidArgumentException('The --format option must be text or json.');
        }

        if ($this->asksForBoth()) {
            throw new InvalidArgumentException('Pass either a module or --all, not both.');
        }
    }

    private function asksForBoth(): bool
    {
        return $this->option('all') && $this->argument('module') !== null;
    }

    /**
     * @return list<ModuleDefinition>
     */
    private function modulesToCheck(?ModuleDefinition $chosen, DiscoverModulesAction $discovery, ModulePicker $picker): array
    {
        return $chosen === null ? $this->everyModule($discovery, $picker) : [$this->readableModule($chosen)];
    }

    /**
     * The one module to check; null means every module, whether asked for, chosen from the list or implied.
     */
    private function chosenModule(ModulePicker $picker, ?string $given): ?ModuleDefinition
    {
        return $this->isEveryModuleImplied() ? null : $this->pickModule($picker, $given);
    }

    /**
     * Every module is checked without asking: --all was passed, or no module was given and there is
     * no terminal to ask in.
     */
    private function isEveryModuleImplied(): bool
    {
        return $this->option('all') || ($this->argument('module') === null && ! $this->canPrompt());
    }

    /**
     * @return list<ModuleDefinition>
     */
    private function everyModule(DiscoverModulesAction $discovery, ModulePicker $picker): array
    {
        $modules = $discovery->execute();

        if ($modules === []) {
            throw new RuntimeException($picker->noModulesMessage($discovery->skipped()));
        }

        return array_values($modules);
    }

    private function readableModule(ModuleDefinition $module): ModuleDefinition
    {
        if (! is_file($module->path.'/'.ModuleManifest::FILE)) {
            throw new RuntimeException("Module [{$module->name}] has no ".ModuleManifest::FILE." in {$module->path}.");
        }

        return $module;
    }

    private function pickModule(ModulePicker $picker, ?string $given): ?ModuleDefinition
    {
        if (! $this->isJson()) {
            return $picker->resolveOrAll($this, $given, self::PICKER_LABEL);
        }

        return $this->pickModuleDrawingOnStderr($picker, $given);
    }

    private function pickModuleDrawingOnStderr(ModulePicker $picker, ?string $given): ?ModuleDefinition
    {
        $components = $this->components;
        $this->drawPromptsOn($this->output->getErrorStyle());

        try {
            return $picker->resolveOrAll($this, $given, self::PICKER_LABEL);
        } finally {
            $this->components = $components;
            $this->restorePrompts();
        }
    }

    /**
     * Both ways a select is drawn: Prompts' own renderer, and the fallback through the components.
     */
    private function drawPromptsOn(SymfonyStyle $errors): void
    {
        Prompt::setOutput($errors);
        $this->components = new Factory(new OutputStyle($this->input, $errors));
    }

    /**
     * @return array<string, string> directory name => reason, only when every module is checked
     */
    private function skippedDirectories(?ModuleDefinition $chosen, DiscoverModulesAction $discovery): array
    {
        return $chosen === null ? $discovery->skipped() : [];
    }

    /**
     * @param  array<string, string>  $skipped
     */
    private function reportSkipped(array $skipped): void
    {
        foreach ($skipped as $directory => $reason) {
            $this->output->getErrorStyle()->writeln("<error>Skipped [{$directory}]: {$reason}</error>");
        }
    }

    /**
     * @param  array<string, string>  $skipped
     */
    private function exitCode(CheckResult $result, array $skipped): int
    {
        return match (true) {
            $skipped !== [] => self::UNUSABLE,
            $result->errorCount() > 0 => self::FAILURE,
            default => self::SUCCESS,
        };
    }

    private function printFindings(FormatCheckFindingsAction $format, CheckResult $result): void
    {
        $lines = $this->isJson() ? [$format->json($result)] : $format->text($result);

        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    private function isJson(): bool
    {
        return $this->option('format') === 'json';
    }
}
