<?php

namespace Studeo\Modular\Support\Schema;

use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\FieldDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Support\PhpImports;

/**
 * The factory value of a foreign key. It never names a factory class: it calls the target model's
 * factory(), which the model resolves through its own newFactory(). A key whose target has no
 * factory to call is left out, with the reason.
 */
final class ForeignKeyFaker
{
    private const string AUTH_USER = "fn () => config('auth.providers.users.model')::factory()";

    /** @var array<string, string> */
    private readonly array $tables;

    public function __construct(
        private readonly ModuleDefinition $module,
        private readonly ?string $userModel = null,
        private readonly ?PhpImports $imports = null,
    ) {
        $this->tables = ModuleModels::tables($module);
    }

    /**
     * @return array{0: ?string, 1: ?string} the expression, or the reason the key is left out
     */
    public function resolve(FieldDefinition $field): array
    {
        $table = ForeignKeyTarget::table($field);
        $model = $this->tables[$table] ?? null;

        return match (true) {
            $field->isNullable() => ['null', null],
            $this->hasFactory($model) => [$this->modelName((string) $model).'::factory()', null],
            $model !== null => [null, "Column [{$field->name}] is left out of the factory: model [{$model}] has no factory at ".ModuleModels::factoryFile($model).'; create it with module:factory, or pass the column when creating the model.'],
            $this->isAuthUserTable($table) => [self::AUTH_USER, null],
            default => [null, "Column [{$field->name}] is left out of the factory: table [{$table}] belongs to no model of module [{$this->module->name}] and is not the auth user's table; pass the column when creating the model."],
        };
    }

    /**
     * The module model's class, named through the imports when given, fully qualified otherwise.
     */
    private function modelName(string $model): string
    {
        $class = "{$this->module->namespace}\\Models\\{$model}";

        return $this->imports?->name($class) ?? "\\{$class}";
    }

    /**
     * The table Eloquent would use for the configured user class, derived from its name alone.
     */
    private function userTable(): string
    {
        return Str::snake(Str::pluralStudly(class_basename((string) $this->userModel)));
    }

    private function hasFactory(?string $model): bool
    {
        return $model !== null && ModuleModels::hasFactory($this->module, $model);
    }

    private function isAuthUserTable(string $table): bool
    {
        return $this->userModel !== null && $table === $this->userTable();
    }
}
