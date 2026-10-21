<?php

namespace Studeo\Modular\Console;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use InvalidArgumentException;
use Laravel\Prompts\Prompt;
use RuntimeException;
use Studeo\Check\Engine\CheckResult;
use Studeo\Check\Engine\Violation;
use Studeo\Modular\Console\Actions\CheckModulesAction;
use Studeo\Modular\Console\Actions\DiscoverModulesAction;
use Studeo\Modular\Console\Actions\ResolveRuleSetAction;
use Studeo\Modular\Console\Prompts\ModulePicker;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\ModuleManifest;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class ModuleCheckCommand extends ModuleCommand
{
    private const UNUSABLE = 2;

    protected $signature = 'module:check
        {--module= : The name of the module}
        {--all : Check every module}
        {--format=text : How to print the findings: text or json}';

    protected $description = 'Check a module against the architecture rules';

    /**
     * Exit 0 when there are no errors (warnings never fail), 1 for any error, 2 when a module
     * cannot be read, a directory was skipped under --all, the rule configuration is invalid, or the
     * check itself fails. With --format=json standard output holds only the findings array: on
     * exit 2 from a failure it holds nothing, and every message goes to stderr.
     */
    public function handle(ResolveRuleSetAction $ruleSets, CheckModulesAction $checker, DiscoverModulesAction $discovery, ModulePicker $picker): int
    {
        try {
            $this->assertUsableOptions();
            $rules = $ruleSets->execute();
            $result = $checker->execute($this->modulesToCheck($discovery, $picker), $rules);
        } catch (Throwable $exception) {
            return $this->reportFailure($exception);
        }

        $this->printFindings($result);
        $skipped = $this->skippedDirectories($discovery);
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
            throw new InvalidArgumentException('Pass either --module or --all, not both.');
        }
    }

    private function asksForBoth(): bool
    {
        return $this->option('all') && $this->option('module') !== null;
    }

    /**
     * @return list<ModuleDefinition>
     */
    private function modulesToCheck(DiscoverModulesAction $discovery, ModulePicker $picker): array
    {
        return $this->option('all') ? $this->everyModule($discovery, $picker) : [$this->readableModule($picker)];
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

    private function readableModule(ModulePicker $picker): ModuleDefinition
    {
        $module = $this->pickModule($picker);

        if (! is_file($module->path.'/'.ModuleManifest::FILE)) {
            throw new RuntimeException("Module [{$module->name}] has no ".ModuleManifest::FILE." in {$module->path}.");
        }

        return $module;
    }

    private function pickModule(ModulePicker $picker): ModuleDefinition
    {
        if (! $this->isJson()) {
            return $picker->resolve($this, $this->scalarOption('module'));
        }

        return $this->pickModuleDrawingOnStderr($picker);
    }

    private function pickModuleDrawingOnStderr(ModulePicker $picker): ModuleDefinition
    {
        $components = $this->components;
        $this->drawPromptsOn($this->output->getErrorStyle());

        try {
            return $picker->resolve($this, $this->scalarOption('module'));
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
     * @return array<string, string> directory name => reason, only for --all
     */
    private function skippedDirectories(DiscoverModulesAction $discovery): array
    {
        return $this->option('all') ? $discovery->skipped() : [];
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

    private function printFindings(CheckResult $result): void
    {
        if ($this->isJson()) {
            $this->printJson($result);

            return;
        }

        $this->printText($result);
    }

    private function printJson(CheckResult $result): void
    {
        $this->line((string) json_encode(array_map(fn (Violation $violation): array => $violation->toArray(), $result->violations), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function printText(CheckResult $result): void
    {
        foreach ($result->violations as $violation) {
            $this->line("{$violation->file}:{$violation->line} [{$violation->rule}] {$violation->message}");
        }

        $this->line($this->summary($result));
    }

    private function summary(CheckResult $result): string
    {
        $errors = $result->errorCount();
        $warnings = $result->warningCount();

        return "{$errors} ".($errors === 1 ? 'error' : 'errors').", {$warnings} ".($warnings === 1 ? 'warning' : 'warnings').'.';
    }

    private function isJson(): bool
    {
        return $this->option('format') === 'json';
    }
}
