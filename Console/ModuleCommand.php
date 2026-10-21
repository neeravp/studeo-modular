<?php

namespace Studeo\Modular\Console;

use Closure;
use Illuminate\Console\Command;
use RuntimeException;
use Studeo\Modular\Console\Concerns\ChecksPrompting;
use Studeo\Modular\Console\Concerns\ReportsFailures;
use Studeo\Modular\Console\Prompts\PromptConsole;
use Studeo\Modular\Exceptions\NoTerminalException;
use Studeo\Modular\Support\SchemaWarnings;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

abstract class ModuleCommand extends Command implements PromptConsole
{
    use ChecksPrompting;
    use ReportsFailures;

    /**
     * Notices never fail a command; they are printed to stderr once the command has run.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = parent::execute($input, $output);

        foreach (app(SchemaWarnings::class)->flush() as $warning) {
            $this->output->getErrorStyle()->writeln('<comment>Notice: '.$warning->render().'</comment>');
        }

        return $status;
    }

    public function infoBadge(string $message): void
    {
        $this->components->info($message);
    }

    public function announceModule(string $message): void
    {
        $this->line($message);
    }

    protected function displayCreated(string $type, string $name, string $moduleName, ?string $path = null): void
    {
        $this->info("{$type} [{$name}] created successfully in module [{$moduleName}].");

        if ($path !== null) {
            $this->line("<comment>Path:</comment> {$path}");
        }
    }

    /**
     * The value of an option declared without an array mode: a string, a boolean or null.
     * It is the same value $this->option() returns, with the type the signature guarantees.
     */
    protected function scalarOption(string $name): string|bool|null
    {
        /** @var string|bool|null $value */
        $value = $this->option($name);

        return $value;
    }

    /**
     * The value of a value option (declared as --name=): a string, or null when it was not given.
     * It is the same value $this->option() returns, with the type the signature guarantees.
     */
    protected function stringOption(string $name): ?string
    {
        /** @var string|null $value */
        $value = $this->option($name);

        return $value;
    }

    /**
     * The value of a single-value argument: a string, or null when it was not given.
     * It is the same value $this->argument() returns, with the type the signature guarantees.
     */
    protected function stringArgument(string $name): ?string
    {
        /** @var string|null $value */
        $value = $this->argument($name);

        return $value;
    }

    /**
     * An optional-value option that defaults to false: false when it was not given, the empty
     * string for a bare flag or an empty value, and the value otherwise.
     */
    protected function resolveTemplateOption(string $optionName): ?string
    {
        $value = $this->scalarOption($optionName);

        return match (true) {
            $value === false => null,
            in_array($value, [null, true, ''], true) => '',
            default => (string) $value,
        };
    }

    /**
     * @param  string  $example  a sample name for the error that a missing name gets without a terminal
     */
    protected function resolveNameArgument(string $label, string $placeholder, string $example, string $argument = 'name'): string
    {
        $name = $this->stringArgument($argument);

        if (filled($name)) {
            return $name;
        }

        return $this->promptForMissingName($label, $placeholder, $example, $argument);
    }

    /**
     * Asks for an argument that was not given. Without an interactive terminal that is an error
     * that shows a complete command, never a prompt.
     */
    protected function promptForMissingName(string $label, string $placeholder, string $example, string $argument = 'name'): string
    {
        if (! $this->canPrompt()) {
            throw new NoTerminalException("the [{$argument}] argument", "{$this->getName()} {$example} --module=Blog");
        }

        return text(
            label: $label,
            placeholder: $placeholder,
            required: true,
        );
    }

    /**
     * Refuses, without a terminal, a command whose prompts would need what was not given.
     *
     * @param  array<string, bool>  $given  what a prompt would ask for => whether the command line gave it
     */
    protected function ensureGivenWithoutTerminal(array $given, string $example): void
    {
        if ($this->canPrompt()) {
            return;
        }

        $missing = array_keys(array_filter($given, fn (bool $isGiven): bool => ! $isGiven));

        if ($missing !== []) {
            throw new NoTerminalException(implode(' and ', $missing), $example);
        }
    }

    /**
     * The name argument, or a prompt for it, held to the same rule either way.
     * Without an interactive terminal a missing name is an error, never a prompt.
     *
     * @param  Closure(string): mixed  $check  throws RuntimeException for a name that breaks the rule
     */
    protected function resolveCheckedName(string $label, string $placeholder, Closure $check, string $missingMessage): string
    {
        return $this->resolveCheckedValue($this->stringArgument('name'), $label, $placeholder, $check, $missingMessage);
    }

    /**
     * A value given on the command line, or a prompt for it, held to the same rule either way.
     * Without an interactive terminal a missing value is an error, never a prompt. The value
     * given is returned as it is: the command's own action checks it again.
     *
     * @param  Closure(string): mixed  $check  throws RuntimeException for a value that breaks the rule
     */
    protected function resolveCheckedValue(?string $given, string $label, string $placeholder, Closure $check, string $missingMessage): string
    {
        if (filled($given)) {
            return $given;
        }

        if (! $this->canPrompt()) {
            throw new RuntimeException($missingMessage);
        }

        return text(
            label: $label,
            placeholder: $placeholder,
            required: true,
            validate: $this->promptValidator($check),
        );
    }

    /**
     * What a prompt asks of an answer: the same check as the command-line value, with the
     * check's own message as the prompt's error.
     *
     * @param  Closure(string): mixed  $check
     * @return Closure(string): ?string
     */
    protected function promptValidator(Closure $check): Closure
    {
        return function (string $value) use ($check): ?string {
            try {
                $check($value);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }

            return null;
        };
    }
}
