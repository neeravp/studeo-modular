<?php

namespace Studeo\Modular\DataObjects;

use Illuminate\Support\Str;

/**
 * The contents of a module's module.json. Everything else about the module
 * (provider class, PSR-4 mappings) is derived by convention.
 */
final readonly class ModuleManifest
{
    public const string FILE = 'module.json';

    public function __construct(
        public string $name,
        public string $namespace,
    ) {}

    /**
     * The one key a module uses for config, views, translations and component names.
     */
    public static function keyFor(string $name): string
    {
        return Str::kebab($name);
    }

    public function key(): string
    {
        return self::keyFor($this->name);
    }

    public function provider(): string
    {
        return $this->namespace.'\\'.$this->name.'ServiceProvider';
    }

    /**
     * @return array<string, string> namespace prefix => path relative to the module root
     */
    public function psr4(): array
    {
        return [
            $this->namespace.'\\' => '',
            $this->namespace.'\\Database\\Factories\\' => 'database/factories/',
            $this->namespace.'\\Database\\Seeders\\' => 'database/seeders/',
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            ['name' => $this->name, 'namespace' => $this->namespace],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ).PHP_EOL;
    }
}
