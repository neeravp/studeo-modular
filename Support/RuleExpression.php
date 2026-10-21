<?php

namespace Studeo\Modular\Support;

/**
 * A validation rule that is PHP code, such as Rule::enum(X::class), rather than a string
 * rule. Emitters print it as written; plain string rules are printed quoted.
 */
final readonly class RuleExpression
{
    public function __construct(public string $code) {}
}
