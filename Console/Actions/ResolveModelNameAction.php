<?php

namespace Studeo\Modular\Console\Actions;

use RuntimeException;
use Studeo\Modular\Console\Generation\NameSanitizer;

/**
 * Normalises a model name to a studly class name (flight becomes Flight). A model is always
 * Models/{Name}.php, and its factory, seeder and table take the name from it, so a path is an error.
 */
final class ResolveModelNameAction
{
    public function __construct(
        private readonly NameSanitizer $sanitizer,
    ) {}

    public function execute(string $name): string
    {
        $name = trim($name);

        if ($this->hasPath($name)) {
            throw new RuntimeException("Model name [{$name}] has a path in it: models, factories, seeders and tables are flat. Use one name, e.g. Role, not Admin/Role.");
        }

        return $this->sanitizer->className($name, 'model');
    }

    private function hasPath(string $name): bool
    {
        return str_contains($name, '/') || str_contains($name, '\\');
    }
}
