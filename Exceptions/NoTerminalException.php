<?php

namespace Studeo\Modular\Exceptions;

use RuntimeException;

/**
 * A command that needs an answer only a prompt can give, run where no prompt can be shown. The
 * message names what is missing and shows one complete command that would not have asked.
 */
final class NoTerminalException extends RuntimeException
{
    public function __construct(string $missing, string $example)
    {
        parent::__construct(ucfirst($missing)." must be given when the command is not interactive, e.g. {$example}.");
    }
}
