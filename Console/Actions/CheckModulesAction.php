<?php

namespace Studeo\Modular\Console\Actions;

use PhpParser\Error;
use Studeo\Check\Engine\CheckResult;
use Studeo\Check\Engine\RuleSet;
use Studeo\Check\Engine\Severity;
use Studeo\Check\Engine\SourceFile;
use Studeo\Check\Engine\Violation;
use Studeo\Modular\Check\ModuleSource;
use Studeo\Modular\DataObjects\ModuleDefinition;

/**
 * Runs a rule set over modules. Files are parsed and never loaded; a file that does not parse is
 * a `syntax` finding and the run goes on.
 */
final class CheckModulesAction
{
    public function __construct(private readonly DiscoverModulesAction $discovery) {}

    /**
     * @param  list<ModuleDefinition>  $modules
     */
    public function execute(array $modules, RuleSet $rules): CheckResult
    {
        $prefixes = $this->namespacePrefixes($modules);
        $perModule = array_map(fn (ModuleDefinition $module): array => $this->check(new ModuleSource($module, $prefixes, base_path()), $rules), $modules);
        $violations = array_merge(...$perModule);
        usort($violations, fn (Violation $a, Violation $b): int => [$a->file, $a->line, $a->rule] <=> [$b->file, $b->line, $b->rule]);

        return new CheckResult($violations);
    }

    /**
     * @return list<Violation>
     */
    private function check(ModuleSource $source, RuleSet $rules): array
    {
        $perRule = array_map(fn ($rule): array => $rule->violations($source), $rules->rules());

        return [...$this->syntaxProblems($source), ...array_merge(...$perRule)];
    }

    /**
     * @return list<Violation>
     */
    private function syntaxProblems(ModuleSource $source): array
    {
        $broken = array_filter($source->files(), fn (SourceFile $file): bool => $file->syntaxError() !== null);

        return array_values(array_map($this->syntaxViolation(...), $broken));
    }

    private function syntaxViolation(SourceFile $file): Violation
    {
        /** @var Error $error */
        $error = $file->syntaxError();

        return new Violation('syntax', $file->displayPath, max(1, $error->getStartLine()), 'file', Severity::Error, "does not parse: {$error->getRawMessage()}. Fix the syntax; no other rule can check this file until it parses.");
    }

    /**
     * Namespace prefix => module name, for every module that can be read plus the ones checked.
     *
     * @param  list<ModuleDefinition>  $modules
     * @return array<string, string>
     */
    private function namespacePrefixes(array $modules): array
    {
        $prefixes = [];

        foreach ([...$this->discovery->execute(), ...$modules] as $module) {
            $prefixes[$module->namespace.'\\'] = $module->name;
        }

        return $prefixes;
    }
}
