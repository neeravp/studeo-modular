<?php

namespace Studeo\Modular\Console\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Studeo\Modular\Console\Generation\NameSanitizer;
use Studeo\Modular\DataObjects\ModuleDefinition;

final class ResolvePivotEntitiesAction
{
    public function __construct(
        private readonly DiscoverModelsAction $modelsDiscoverer,
        private readonly NameSanitizer $sanitizer,
    ) {}

    /**
     * @return array{0: string, 1: string}
     */
    public function execute(
        ModuleDefinition $module,
        string $model1,
        ?string $model2 = null,
    ): array {
        $entities = $this->hasSecondModel($model2)
            ? $this->resolveFromPair($model1, (string) $model2)
            : $this->resolveFromCompound($module, $model1);

        return $this->sortEntities($entities[0], $entities[1]);
    }

    private function hasSecondModel(?string $model): bool
    {
        return $model !== null && trim($model) !== '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveFromPair(string $model1, string $model2): array
    {
        $entityA = $this->sanitizeEntityName($model1);
        $entityB = $this->sanitizeEntityName($model2);

        if ($this->hasBlankEntity($entityA, $entityB)) {
            throw new RuntimeException("Invalid pivot model names [{$model1}], [{$model2}].");
        }

        $this->sanitizer->ensureStartsWithLetter($entityA, $model1, 'pivot');
        $this->sanitizer->ensureStartsWithLetter($entityB, $model2, 'pivot');

        return [$entityA, $entityB];
    }

    private function hasBlankEntity(string $entityA, string $entityB): bool
    {
        return $entityA === '' || $entityB === '';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveFromCompound(ModuleDefinition $module, string $name): array
    {
        $clean = $this->stripSeparators($name);

        if ($clean === '') {
            throw new RuntimeException("Invalid pivot name [{$name}].");
        }

        $entities = $this->splitSnakeCase($clean)
            ?? $this->matchDiscoveredModels($module, $clean)
            ?? $this->splitPascalCase($clean)
            ?? throw new RuntimeException("Cannot parse two entities from pivot name [{$name}]. Please provide both models (e.g. 'Flight Passenger' or 'FlightPassenger').");

        $this->sanitizer->ensureStartsWithLetter($entities[0], $name, 'pivot');
        $this->sanitizer->ensureStartsWithLetter($entities[1], $name, 'pivot');

        return $entities;
    }

    /**
     * @return ?array{0: string, 1: string}
     */
    private function splitSnakeCase(string $name): ?array
    {
        if (! str_contains($name, '_')) {
            return null;
        }

        $parts = array_values(array_filter(explode('_', $name), fn (string $part): bool => $part !== ''));

        if (count($parts) < 2) {
            return null;
        }

        return [
            Str::studly(array_shift($parts)),
            Str::studly(implode('_', $parts)),
        ];
    }

    /**
     * @return ?array{0: string, 1: string}
     */
    private function splitPascalCase(string $name): ?array
    {
        /** @var list<string> $words */
        $words = preg_split('/(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY);

        if (! $this->containsTwoWords($words)) {
            return null;
        }

        $half = (int) ceil(count($words) / 2);

        return [
            implode('', array_slice($words, 0, $half)),
            implode('', array_slice($words, $half)),
        ];
    }

    /**
     * @param  array<int, string>|false  $words
     */
    private function containsTwoWords(array|false $words): bool
    {
        return $words !== false && count($words) >= 2;
    }

    /**
     * @return ?array{0: string, 1: string}
     */
    private function matchDiscoveredModels(ModuleDefinition $module, string $compound): ?array
    {
        $models = array_keys($this->modelsDiscoverer->forModule($module));
        $leadingModel = $this->findLeadingModel($models, $compound);

        if ($leadingModel === null) {
            return null;
        }

        return [$leadingModel, Str::studly(substr($compound, strlen($leadingModel)))];
    }

    /**
     * @param  array<int, int|string>  $models
     */
    private function findLeadingModel(array $models, string $compound): ?string
    {
        $leading = array_values(array_filter(
            $models,
            fn (int|string $model): bool => $this->isProperPrefix((string) $model, $compound),
        ));

        return isset($leading[0]) ? (string) $leading[0] : null;
    }

    private function isProperPrefix(string $prefix, string $compound): bool
    {
        return Str::startsWith($compound, $prefix) && strlen($compound) > strlen($prefix);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sortEntities(string $entityA, string $entityB): array
    {
        $entities = [$entityA, $entityB];
        sort($entities);

        return [$entities[0], $entities[1]];
    }

    private function sanitizeEntityName(string $name): string
    {
        return Str::studly($this->stripSeparators($name));
    }

    private function stripSeparators(string $name): string
    {
        return trim(str_replace(['/', '\\'], '', $name));
    }
}
