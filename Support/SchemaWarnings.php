<?php

namespace Studeo\Modular\Support;

use Studeo\Modular\DataObjects\SchemaIssue;

/**
 * The warnings a schema produced while it was parsed. A warning never fails the command; the
 * command base class prints these to stderr when it finishes.
 */
final class SchemaWarnings
{
    /** @var array<int, SchemaIssue> */
    private array $warnings = [];

    public function add(SchemaIssue $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * The collected warnings, which are then forgotten.
     *
     * @return array<int, SchemaIssue>
     */
    public function flush(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];

        return $warnings;
    }
}
