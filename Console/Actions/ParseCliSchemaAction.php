<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\RawRelationship;
use Studeo\Modular\DataObjects\RawSchema;
use Studeo\Modular\DataObjects\RelationshipDefinition;
use Studeo\Modular\DataObjects\SchemaDefinition;
use Studeo\Modular\Support\Schema\CliFieldReader;

/**
 * Reads the compact CLI form, e.g. "title:string:nullable,user:belongsTo", into a RawSchema,
 * and (for callers that skip validation) builds definitions from it directly.
 */
class ParseCliSchemaAction
{
    public function __construct(
        private readonly CliFieldReader $fieldReader = new CliFieldReader,
        private readonly ParseCliRelationshipAction $relationships = new ParseCliRelationshipAction,
        private readonly BuildSchemaDefinitionAction $builder = new BuildSchemaDefinitionAction,
    ) {}

    public function execute(string $input): SchemaDefinition
    {
        return $this->builder->schema($this->read($input));
    }

    public function read(string $input): RawSchema
    {
        $fields = [];
        $relationships = [];

        foreach ($this->tokenize($input) as $token) {
            $this->relationships->isRelationship($token)
                ? $relationships[] = $this->readRelationship($token)
                : $fields[] = $this->fieldReader->readField($token);
        }

        return new RawSchema(fields: $fields, relationships: $relationships);
    }

    public function parseFieldFromString(string $token): FieldDefinition
    {
        return $this->builder->field($this->fieldReader->readField($token));
    }

    public function parseRelationshipFromString(string $token): RelationshipDefinition
    {
        return $this->builder->relationship($this->readRelationship($token));
    }

    public function readRelationship(string $token): RawRelationship
    {
        return $this->relationships->read($token);
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $input): array
    {
        $rawTokens = preg_split('/,(?![^(]*\))/', $input) ?: [];

        return array_values(array_filter(array_map('trim', $rawTokens), fn (string $token): bool => $token !== ''));
    }
}
