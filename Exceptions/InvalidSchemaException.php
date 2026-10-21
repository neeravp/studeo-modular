<?php

namespace Studeo\Modular\Exceptions;

use InvalidArgumentException;
use Studeo\Modular\DataObjects\SchemaIssue;

/**
 * A schema that cannot be generated from. The message lists every issue found, each with the
 * valid alternatives, not just the first.
 */
final class InvalidSchemaException extends InvalidArgumentException
{
    /**
     * @param  array<int, SchemaIssue>  $issues
     */
    public function __construct(public readonly array $issues, ?\Throwable $previous = null)
    {
        $errors = array_values(array_filter($issues, fn (SchemaIssue $issue): bool => $issue->isError()));
        $lines = array_map(fn (SchemaIssue $issue): string => '  - '.$issue->render(), $issues);

        parent::__construct(
            'Invalid schema: '.count($errors).' '.(count($errors) === 1 ? 'problem' : 'problems')." found.\n".implode("\n", $lines),
            0,
            $previous,
        );
    }
}
