<?php

namespace Studeo\Modular\Console\Actions;

use Studeo\Modular\DataObjects\RawRelationship;

/**
 * Parses the relationship tokens of a compact CLI schema, e.g. "author:belongsTo(User)".
 */
class ParseCliRelationshipAction
{
    private const array RELATIONSHIP_TYPES = [
        'belongsto',
        'hasmany',
        'hasone',
        'belongstomany',
        'hasthrough',
        'hasmanythrough',
        'hasonethrough',
        'morphto',
        'morphone',
        'morphmany',
        'morphtomany',
    ];

    public function read(string $token): RawRelationship
    {
        $parts = $this->splitToken($token);
        $rawType = $this->extractTokenPart($parts, 1) ?? 'belongsTo';

        return new RawRelationship(
            name: trim($parts[0]),
            type: $this->resolveRelationshipType($rawType),
            relatedModel: $this->extractExplicitRelatedModel($rawType),
            foreignKey: $this->extractTokenPart($parts, 2),
            ownerKey: $this->extractTokenPart($parts, 3),
        );
    }

    public function isRelationship(string $token): bool
    {
        $parts = $this->splitToken($token);
        if (count($parts) < 2) {
            return false;
        }

        $baseType = $this->resolveRelationshipType($parts[1]);

        return in_array(strtolower($baseType), self::RELATIONSHIP_TYPES, true);
    }

    /**
     * @return array<int, string>
     */
    private function splitToken(string $token): array
    {
        return preg_split('/:(?![^(]*\))/', $token) ?: [];
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function extractTokenPart(array $parts, int $index): ?string
    {
        return isset($parts[$index]) && trim($parts[$index]) !== '' ? trim($parts[$index]) : null;
    }

    private function resolveRelationshipType(string $rawType): string
    {
        return str_contains($rawType, '(')
            ? (strstr($rawType, '(', true) ?: $rawType)
            : $rawType;
    }

    private function extractExplicitRelatedModel(string $rawType): ?string
    {
        if ($this->hasClosedParentheses($rawType)) {
            $model = trim(substr(strstr($rawType, '(') ?: '', 1, -1));

            return $model !== '' ? $model : null;
        }

        return null;
    }

    private function hasClosedParentheses(string $rawType): bool
    {
        return str_contains($rawType, '(') && str_ends_with($rawType, ')');
    }
}
