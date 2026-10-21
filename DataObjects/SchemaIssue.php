<?php

namespace Studeo\Modular\DataObjects;

/**
 * One problem found in a schema. An error blocks generation; a warning is reported and
 * never changes an exit code.
 */
final readonly class SchemaIssue
{
    public const string ERROR = 'error';

    public const string WARNING = 'warning';

    /**
     * @param  array<int, string>  $alternatives  the valid choices, when there is a closed set
     */
    public function __construct(
        public string $path,
        public string $code,
        public string $message,
        public array $alternatives = [],
        public string $severity = self::ERROR,
    ) {}

    /**
     * @param  array<int, string>  $alternatives
     */
    public static function error(string $path, string $code, string $message, array $alternatives = []): self
    {
        return new self($path, $code, $message, $alternatives);
    }

    /**
     * @param  array<int, string>  $alternatives
     */
    public static function warning(string $path, string $code, string $message, array $alternatives = []): self
    {
        return new self($path, $code, $message, $alternatives, self::WARNING);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function render(): string
    {
        $valid = $this->alternatives === [] ? '' : ' Valid: '.implode(', ', $this->alternatives).'.';

        return "{$this->path}: {$this->message}{$valid}";
    }
}
