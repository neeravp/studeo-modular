<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Check\Engine\CheckResult;
use Studeo\Check\Engine\Violation;

/**
 * The lines module:check prints for a result: the findings array as JSON, or one line per finding
 * and a summary as text.
 */
final class FormatCheckFindingsAction
{
    public function json(CheckResult $result): string
    {
        return (string) json_encode(array_map(fn (Violation $violation): array => $violation->toArray(), $result->violations), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    public function text(CheckResult $result): array
    {
        $lines = array_map(fn (Violation $violation): string => "{$violation->file}:{$violation->line} [{$violation->rule}] {$violation->message}", $result->violations);

        return [...$lines, $this->summary($result)];
    }

    private function summary(CheckResult $result): string
    {
        $errors = $result->errorCount();
        $warnings = $result->warningCount();

        return "{$errors} ".($errors === 1 ? 'error' : 'errors').", {$warnings} ".($warnings === 1 ? 'warning' : 'warnings').'.';
    }
}
