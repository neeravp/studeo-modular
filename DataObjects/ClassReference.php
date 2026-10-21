<?php

namespace Studeo\Modular\DataObjects;

use Illuminate\Support\Str;

final readonly class ClassReference
{
    public function __construct(
        public string $fqcn,
        public string $class,
        public string $namespace,
        public string $variable,
    ) {}

    public static function resolve(ModuleDefinition $module, string $name, string $subNamespace): self
    {
        $clean = trim($name);
        $fqcn = str_contains($clean, '\\')
            ? trim($clean, '\\')
            : $module->namespace.'\\'.$subNamespace.'\\'.Str::studly(trim($clean));

        $class = class_basename($fqcn);
        $namespace = Str::beforeLast($fqcn, '\\');
        $variable = Str::camel($class);

        return new self(
            fqcn: $fqcn,
            class: $class,
            namespace: $namespace,
            variable: $variable,
        );
    }
}
