<?php

namespace Studeo\Modular\DataObjects;

use Studeo\Modular\Support\PhpImports;

final readonly class RelationshipDefinition
{
    private const array RELATIONSHIP_CLASS_MAP = [
        'belongsto' => '\Illuminate\Database\Eloquent\Relations\BelongsTo',
        'hasmany' => '\Illuminate\Database\Eloquent\Relations\HasMany',
        'hasone' => '\Illuminate\Database\Eloquent\Relations\HasOne',
        'belongstomany' => '\Illuminate\Database\Eloquent\Relations\BelongsToMany',
        'hasthrough' => '\Illuminate\Database\Eloquent\Relations\HasManyThrough',
        'hasmanythrough' => '\Illuminate\Database\Eloquent\Relations\HasManyThrough',
        'hasonethrough' => '\Illuminate\Database\Eloquent\Relations\HasOneThrough',
        'morphto' => '\Illuminate\Database\Eloquent\Relations\MorphTo',
        'morphone' => '\Illuminate\Database\Eloquent\Relations\MorphOne',
        'morphmany' => '\Illuminate\Database\Eloquent\Relations\MorphMany',
        'morphtomany' => '\Illuminate\Database\Eloquent\Relations\MorphToMany',
    ];

    public function __construct(
        public string $name,
        public string $type,
        public string $relatedModel,
        public ?string $foreignKey = null,
        public ?string $ownerKey = null,
        public bool $authUser = false,
    ) {}

    /**
     * The same relationship, pointing at the application's user model instead of a model of the module.
     */
    public function toAuthUser(): self
    {
        return new self($this->name, $this->type, $this->relatedModel, $this->foreignKey, $this->ownerKey, true);
    }

    public function relationshipClass(): string
    {
        return self::RELATIONSHIP_CLASS_MAP[strtolower($this->type)]
            ?? '\Illuminate\Database\Eloquent\Relations\Relation';
    }

    public function relationshipMethodName(): string
    {
        return lcfirst(class_basename($this->relationshipClass()));
    }

    public function isSingular(): bool
    {
        return match (strtolower($this->type)) {
            'belongsto', 'hasone', 'hasonethrough', 'morphone', 'morphto' => true,
            default => false,
        };
    }

    /**
     * The docblock line; classes are named through the imports when given, fully qualified otherwise.
     */
    public function phpDocProperty(string $modelNamespace, ?PhpImports $imports = null): string
    {
        $target = $this->className($this->targetClassNamespace($modelNamespace), $imports);

        return $this->isSingular()
            ? $this->buildSingularDocProperty($target)
            : $this->buildCollectionDocProperty($target, $this->className('Illuminate\\Database\\Eloquent\\Collection', $imports));
    }

    public function methodDefinition(?PhpImports $imports = null): string
    {
        $relationClass = $this->className($this->relationshipClass(), $imports);
        $relationMethod = $this->relationshipMethodName();
        $arguments = $this->buildMethodArguments();

        return "    public function {$this->name}(): {$relationClass}\n"
            ."    {\n"
            ."        return \$this->{$relationMethod}({$arguments});\n"
            .'    }';
    }

    /**
     * The user model is only a contract in the docblock: the configured class is the application's
     * to name, and a module never imports it.
     */
    private function targetClassNamespace(string $modelNamespace): string
    {
        return $this->authUser ? 'Illuminate\\Contracts\\Auth\\Authenticatable' : "{$modelNamespace}\\{$this->relatedModel}";
    }

    private function targetClass(): string
    {
        return $this->authUser ? "config('auth.providers.users.model')" : "{$this->relatedModel}::class";
    }

    private function buildMethodArguments(): string
    {
        $arguments = array_merge([$this->targetClass()], $this->relationshipKeys());

        return implode(', ', $arguments);
    }

    /**
     * @return list<string>
     */
    private function relationshipKeys(): array
    {
        if ($this->foreignKey === null) {
            return [];
        }

        return $this->ownerKey !== null
            ? ["'{$this->foreignKey}'", "'{$this->ownerKey}'"]
            : ["'{$this->foreignKey}'"];
    }

    private function className(string $class, ?PhpImports $imports): string
    {
        return $imports?->name($class) ?? '\\'.ltrim($class, '\\');
    }

    private function buildSingularDocProperty(string $target): string
    {
        return "@property-read ?{$target} \${$this->name}";
    }

    private function buildCollectionDocProperty(string $target, string $collection): string
    {
        return "@property-read {$collection}<int, {$target}> \${$this->name}";
    }
}
