<?php

namespace Studeo\Modular\Console\Actions;

use InvalidArgumentException;
use Studeo\Modular\DataObjects\RawRelationship;
use Studeo\Modular\DataObjects\RelationshipDefinition;
use Studeo\Modular\Support\Schema\SchemaKeys;

/**
 * Reads one relationship of a structured schema (a string shorthand or a config map).
 */
class ParseStructuredRelationshipAction
{
    public function __construct(
        private readonly ParseCliSchemaAction $cliParser = new ParseCliSchemaAction,
        private readonly BuildSchemaDefinitionAction $builder = new BuildSchemaDefinitionAction,
    ) {}

    public function execute(string $name, mixed $config): RelationshipDefinition
    {
        $raw = $this->read($name, $config);

        return $raw->malformed === []
            ? $this->builder->relationship($raw)
            : throw new InvalidArgumentException("Invalid relationship configuration for [{$name}].");
    }

    public function read(string $name, mixed $config): RawRelationship
    {
        return match (true) {
            is_string($config) => $this->cliParser->readRelationship("{$name}:{$config}"),
            is_array($config) => $this->readConfig($name, $config),
            default => new RawRelationship($name, malformed: ['a relationship is a string like "belongsTo(User)" or a map of keys']),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function readConfig(string $name, array $config): RawRelationship
    {
        return new RawRelationship(
            name: $name,
            type: (string) ($config['type'] ?? 'belongsTo'),
            relatedModel: isset($config['model']) || isset($config['related']) ? (string) ($config['model'] ?? $config['related']) : null,
            foreignKey: isset($config['foreign_key']) ? (string) $config['foreign_key'] : null,
            ownerKey: isset($config['owner_key']) ? (string) $config['owner_key'] : null,
            unknownKeys: array_values(array_diff(array_map('strval', array_keys($config)), SchemaKeys::RELATIONSHIP)),
        );
    }
}
