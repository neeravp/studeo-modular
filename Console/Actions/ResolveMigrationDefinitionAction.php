<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\DataObjects\MigrationDefinition;
use Studeo\Modular\DataObjects\MigrationGenerationOptions;
use Studeo\Modular\Enums\MigrationType;

/**
 * Works out what a migration is from its name and options: the clean name, the type (create, update,
 * pivot or blank), the table, and for a pivot the columns it joins.
 */
final class ResolveMigrationDefinitionAction
{
    private const CREATE_PATTERNS = [
        '/^create_(\w+)_table$/',
        '/^create_(\w+)$/',
    ];

    private const UPDATE_PATTERNS = [
        '/.+_(?:to|from|in)_(\w+)_table$/',
        '/.+_(?:to|from|in)_(\w+)$/',
    ];

    private const TABLE_PATTERNS = [
        '/^create_(\w+)_table$/',
        '/^create_(\w+)$/',
        '/.+_(?:to|from|in)_(\w+)_table$/',
        '/.+_(?:to|from|in)_(\w+)$/',
    ];

    public function execute(
        string $name,
        MigrationGenerationOptions $options,
    ): MigrationDefinition {
        $cleanName = $this->sanitizeMigrationName($name);
        $type = $this->determineMigrationType($cleanName, $options);
        $tableName = $this->resolveTableName($cleanName, $type, $options);

        [$column1, $column2, $morphName] = $this->resolvePivotColumns($cleanName, $tableName, $type, $options);

        return new MigrationDefinition(
            name: $cleanName,
            type: $type,
            tableName: $tableName,
            column1: $column1,
            column2: $column2,
            morphName: $morphName,
        );
    }

    private function sanitizeMigrationName(string $name): string
    {
        $clean = trim(str_replace(['/', '\\'], '', $name));
        $clean = (string) preg_replace('/\.php$/i', '', $clean);
        $snake = Str::snake($clean);

        if ($snake === '') {
            throw new RuntimeException("Invalid migration name [{$name}].");
        }

        return $snake;
    }

    private function determineMigrationType(
        string $name,
        MigrationGenerationOptions $options,
    ): MigrationType {
        return match (true) {
            $options->pivot => MigrationType::Pivot,
            $options->blank => MigrationType::Blank,
            $options->create !== null => MigrationType::Create,
            $options->table !== null => MigrationType::Update,
            default => $this->guessTypeFromName($name),
        };
    }

    private function guessTypeFromName(string $name): MigrationType
    {
        return match (true) {
            $this->matchesPattern($name, self::CREATE_PATTERNS) => MigrationType::Create,
            $this->matchesPattern($name, self::UPDATE_PATTERNS) => MigrationType::Update,
            default => MigrationType::Blank,
        };
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesPattern(string $name, array $patterns): bool
    {
        return array_filter($patterns, fn (string $pattern): bool => preg_match($pattern, $name) === 1) !== [];
    }

    private function resolveTableName(
        string $name,
        MigrationType $type,
        MigrationGenerationOptions $options,
    ): ?string {
        $explicitTable = $options->create ?? $options->table;

        if ($explicitTable !== null) {
            return Str::snake($explicitTable);
        }

        return match ($type) {
            MigrationType::Blank => null,
            MigrationType::Pivot => $this->guessTableFromName($name) ?? $name,
            MigrationType::Create, MigrationType::Update => $this->guessTableFromName($name),
        };
    }

    private function guessTableFromName(string $name): ?string
    {
        $tables = array_map(fn (string $pattern): ?string => preg_match($pattern, $name, $matches) === 1 ? $matches[1] : null, self::TABLE_PATTERNS);
        $found = array_filter($tables, fn (?string $table): bool => $table !== null);

        return array_shift($found);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function resolvePivotColumns(
        string $name,
        ?string $tableName,
        MigrationType $type,
        MigrationGenerationOptions $options,
    ): array {
        if ($type !== MigrationType::Pivot) {
            return [null, null, null];
        }

        $table = $tableName ?: throw new RuntimeException("Cannot determine pivot table name for migration [{$name}].");

        return $options->morph
            ? $this->resolveMorphPivotColumns($table, $options)
            : $this->resolveStandardPivotColumns($table, $options);
    }

    /**
     * @return array{0: string, 1: null, 2: string}
     */
    private function resolveMorphPivotColumns(string $table, MigrationGenerationOptions $options): array
    {
        $parts = $this->splitTableParts($table);
        $first = $parts[0];
        $second = $parts[1] ?? Str::singular($table);

        return [
            $options->foreignKey1 ?? Str::snake($first).'_id',
            null,
            Str::snake($second),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: null}
     */
    private function resolveStandardPivotColumns(string $table, MigrationGenerationOptions $options): array
    {
        $parts = $this->splitTableParts($table);

        if (count($parts) < 2) {
            throw new RuntimeException("Pivot table name [{$table}] must contain at least two parts (e.g. flight_passenger).");
        }

        return [
            $options->foreignKey1 ?? Str::snake($parts[0]).'_id',
            $options->foreignKey2 ?? Str::snake($parts[1]).'_id',
            null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitTableParts(string $table): array
    {
        $words = array_values(array_filter(explode('_', $table), fn (string $word): bool => $word !== ''));

        if (count($words) < 2) {
            return $words;
        }

        $half = (int) ceil(count($words) / 2);

        return [
            implode('_', array_slice($words, 0, $half)),
            implode('_', array_slice($words, $half)),
        ];
    }
}
