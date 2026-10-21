# Studeo Modular API Reference

The public classes of the package: the data object base classes your modules extend, the module registrar your providers call, the module lifecycle Actions, the manifest, and the architecture checker's extension points. For commands and options, see [README.md](README.md).

The generator Actions behind `module:*` (`Create*Action`, `Move*Action`, `Delete*Action`, the schema parsers) are internal. Their signatures change with the generators and are not a stable API; use the Artisan commands.

---

## Table of Contents

- [Data Objects](#data-objects)
  - [DataObject](#dataobject)
  - [InputData](#inputdata)
  - [OutputData](#outputdata)
  - [Attributes](#attributes)
- [Module Registration](#module-registration)
  - [ModuleRegistrar](#moduleregistrar)
  - [ModuleCache](#modulecache)
- [Module Manifest and Definitions](#module-manifest-and-definitions)
- [Module Lifecycle Actions](#module-lifecycle-actions)
- [Architecture Checks](#architecture-checks)
- [Exceptions](#exceptions)
- [Enums](#enums)
- [Internal Collaborators](#internal-collaborators)

---

## Data Objects

All three base classes are `abstract readonly` and live in `Studeo\Modular\Support`. Wire keys are snake_case; properties are camelCase.

### DataObject

The shared base of `InputData` and `OutputData`.

- `toArray(): array`: public properties as a snake_case array, nested data objects converted recursively.
- `jsonSerialize(): array`: the same array, so a data object can be returned from a controller or passed as an Inertia prop.
- `toJson($options = 0): string`

A data object is never Livewire state. Livewire components hold plain snake_case values.

### InputData

Data entering a boundary. Validation comes first, then hydration; there is no public `from()`. Subclasses declare a protected constructor so `fromValidated()` is the only way in.

```php
abstract public static function rules(): array;
public static function messages(): array;
public static function attributes(): array;

public static function fromValidated(array|Request $source, array $context = []): static;
public static function fromRequest(Request $request, array $context = []): static;
```

- `rules()`: Laravel validation rules, keyed by snake_case payload key.
- `messages()` and `attributes()`: custom messages and attribute names; both default to `[]`.
- `fromValidated()`: validates `$source` against `rules()`, reads only the keys the rules cover, merges `$context`, and hydrates. Throws `ValidationException` on invalid input and `InvalidArgumentException` for a context key that is not a `#[Context]` property.
- `fromRequest()`: `fromValidated()` for a request.
- `nestedRules(string $prefix, string $input): array` (protected): the rules of another Input under a prefix, for example `nestedRules('items.*', ItemInput::class)`.

### OutputData

Data leaving a boundary. Carries no rules.

```php
public static function from(mixed $source): static;
public static function collect(iterable $items): Collection;
```

`from()` accepts an array, an Eloquent model (attributes and loaded relations), an `Arrayable`, or an object; it returns an existing instance of the class unchanged. Anything else throws `InvalidArgumentException`. `collect()` maps `from()` over the items and returns a list.

### Attributes

Namespace `Studeo\Modular\Support\Attributes`.

| Attribute | Target | Meaning |
|-----------|--------|---------|
| `#[Context]` | Input property | The value never comes from the payload; it is supplied only through the `$context` argument of `fromValidated()` / `fromRequest()`. |
| `#[Collection(of: X::class)]` | array property | Every item of the array is a data object of class `X`. |
| `#[SystemAction]` | Action class | The Action does no authorization of its own. It may be called only from other Actions, Listeners and Jobs, never from controllers, middleware, API resources or Livewire components. |

---

## Module Registration

### ModuleRegistrar

`Studeo\Modular\Support\ModuleRegistrar` registers a module's own artifacts explicitly from the module's provider. Nothing relies on Laravel's conventional discovery paths. The generated provider is:

```php
public function register(): void
{
    ModuleRegistrar::for($this, __DIR__)->config();
}

public function boot(): void
{
    ModuleRegistrar::for($this, __DIR__)
        ->routes()->views()->migrations()->translations()
        ->commands()->listeners()->policies()->middleware()->livewire();
}
```

`ModuleRegistrar::for(ServiceProvider $provider, string $directory)` reads the module's `module.json` and throws `ModuleRegistrationException` when it cannot be used. Each step is one concern; a module drops a step by removing it from its provider. `config()` belongs in `register()`. Every other step belongs in `boot()`: a failing step is recorded and the remaining steps still run.

A provider that does not use `ModuleRegistrar` is refused at boot and by `module:install`, with the replacement provider printed.

Container bindings go in the provider's `$bindings` and `$singletons` properties, or in container calls after the registrar line in `register()`.

### ModuleCache

`Studeo\Modular\Support\ModuleCache` is the file written by `modular:cache` (`bootstrap/cache/modular.php`). It holds each module's discovered listeners, policies and middleware, keyed by module key, and is read once per process.

- `entryFor(string $key): ?array`: the cached entry, or `null` when the file or the entry is missing, corrupt, malformed or of an unknown version. The registrar then scans live.
- `write(array $modules): void`: writes a temporary file and renames it over the cache.
- `clear(): bool`: removes the file.

---

## Module Manifest and Definitions

Namespace `Studeo\Modular\DataObjects`. All are `final readonly`.

### ModuleManifest

The contents of `module.json`.

- `string $name`, `string $namespace`
- `const FILE = 'module.json'`
- `key(): string`: the kebab-case module key used for config, views, translations and component names. `ModuleManifest::keyFor(string $name)` is the static form.
- `provider(): string`: `{namespace}\{name}ServiceProvider`.
- `psr4(): array`: the three PSR-4 mappings of the module, namespace prefix => path relative to the module root: the module root (`''`), `Database\Factories\` (`database/factories/`) and `Database\Seeders\` (`database/seeders/`).
- `toJson(): string`: the manifest file contents.

### ModuleDefinition

A module resolved from a name: `string $name`, `string $namespace`, `string $path` (absolute directory).

### InstalledModule

A module registered in the root `composer.json`: `ModuleDefinition $module`, `ModuleManifest $manifest`, `string $providerPath`.

### InstallResult

`array $installed`, `array $alreadyInstalled`, `array $pending` (module names), and `hasInstalled(): bool`.

---

## Module Lifecycle Actions

Namespace `Studeo\Modular\Console\Actions`. These back `module:make`, `module:install`, `module:uninstall` and `module:remove`.

Modules are not Composer packages. Install writes three PSR-4 entries per module into the root `composer.json` and dumps the autoloader; it adds no repository entries.

### ResolveModuleAction

Turns a module name into a `ModuleDefinition`.

```php
public function execute(string $module): ModuleDefinition
public function forNewModule(string $module, ?string $rootNamespace = null): ModuleDefinition
public function forRepair(string $module): ModuleDefinition
```

- `execute()`: for an existing module. The namespace comes from its `module.json`. Throws `InvalidArgumentException` for an invalid name and `InvalidModuleManifestException` for a manifest that exists and cannot be used.
- `forNewModule()`: for a module that does not exist yet. The namespace is `$rootNamespace` if given, else `modular.root_namespace`, else none; an empty string means no prefix.
- `forRepair()`: for uninstall and remove. An unusable manifest does not stop it; the module then carries its name as its namespace.

### CreateModuleAction

```php
public function execute(ModuleDefinition $module): void
```

Writes the module directories, `module.json`, the service provider, the config file, `routes/web.php` and the empty `resources/css/module.css` and `resources/js/module.js`. Throws `RuntimeException` if the module directory already exists.

### InstallModuleAction

```php
public function execute(ModuleDefinition ...$modules): InstallResult
```

Verifies each module (directory, manifest, provider uses `ModuleRegistrar`), writes its three PSR-4 entries into the root `composer.json` and dumps the autoloader, rolling `composer.json` back on failure.

### UninstallModuleAction

```php
public function execute(ModuleDefinition $module): bool
```

Removes the module's PSR-4 entries and dumps the autoloader; files stay on disk. Returns `false` if the module was not registered.

### RemoveModuleAction

```php
public function execute(ModuleDefinition $module): void
```

Deletes the directory of a module that is not installed. Throws `RuntimeException` if the module is still registered or does not exist.

### DiscoverModulesAction

```php
public function execute(): array
public function skipped(): array
```

Scans the directories directly under `modular.root_path`, and is what the module picker uses.

- `execute()`: `array<string, ModuleDefinition>`, keyed by module name and sorted: the directories whose `module.json` is present and usable.
- `skipped()`: `array<string, string>`, directory name => reason, sorted: directories that are not discoverable modules. The reason is `no module.json.` for a directory without a manifest, a message that points at `module:install` for a directory with a legacy `composer.json` and no `module.json`, or `invalid module.json: …` / `invalid module name: …` for a manifest or name that cannot be used. Skipped directories are never thrown and never offered by the picker.

### DiscoverInstalledModulesAction

```php
public function execute(): array
public function discover(): DiscoveredInstalledModules
```

Reads the root `composer.json` PSR-4 entries and loads each installed module. `execute()` returns `array<string, InstalledModule>` and ignores failures. `discover()` returns a `DiscoveredInstalledModules` with `installed` and `failures` (`ModuleLoadException` instances); failures are returned, never swallowed.

---

## Architecture Checks

The static-analysis engine, the rule contract (`Rule`, `RuleSet`, `Violation`, `Severity`, `Scope`, `SourceSet`, `SourceFile`) and the generic and convention rules belong to the `studeo/check` package; its `API.md` is the reference for them. Modular keeps the module-aware layer in `Studeo\Modular\Check` and `Studeo\Modular\Check\Rules`. These are the extension points of `module:check`; see [`SPEC.md`](SPEC.md) for the command and its exit codes.

- `DefaultRules`: `rules(): RuleSet`, the rule set used unless `modular.check.rules` names another class. Composes Check's `Cap1Rules`, `ActionRules` and `LaravelRules` profiles with the module rules. Extend it to change a limit or add rules.
- `ModuleSource`: implements Check's `SourceSet`. Provides what a rule sees of a module: its PHP files on disk, class index, and module ownership.
- `BaseModuleRule` and `ModuleFileRule`: abstract base classes in `Studeo\Modular\Check\Rules` that concrete module rules extend to receive a `ModuleSource` (via `moduleViolations()` or `inspectInModule()`).
- Module rules in `Studeo\Modular\Check\Rules`: `FlatModules` (`flat-modules`), `ModuleIsolation` (`module-isolation`), and `ControllerShape` (`controller-shape`).

The checker parses files and never executes module code.

---

## Exceptions

Namespace `Studeo\Modular\Exceptions`.

| Exception | Raised when |
|-----------|-------------|
| `InvalidModuleManifestException` | `module.json` is missing, invalid, or replaced by a legacy `composer.json` (the message prints the exact `module.json` to create). |
| `LegacyModuleProviderException` | A provider does not use `ModuleRegistrar` (the message prints the replacement provider). |
| `ModuleLoadException` | An installed module cannot be loaded: bad manifest or name, missing directory or provider, provider class not autoloadable, legacy provider, registration failure. |
| `ModuleRegistrationException` | A `ModuleRegistrar` step fails: an unusable manifest, a Livewire component that cannot be registered, a middleware alias already taken. |
| `NoTerminalException` | A command needs an answer only a prompt can give and cannot prompt; the message names the missing argument or option and shows one full example. |
| `InvalidSchemaException` | A schema has issues; the message lists all of them. |
| `InvalidRuleConfigurationException` | Extends `Studeo\Check\Exceptions\InvalidRuleConfigurationException`; raised when `modular.check.rules` names a missing class, a class that does not return a `RuleSet`, or whose options are rejected. |

---

## Enums

Namespace `Studeo\Modular\Enums`.

- `ControllerType`: `Invokable`, `Resource`, `Api`, `Standard`
- `LivewireComponentType`: `Classic`, `Mfc`, `Sfc`
- `VueComponentType`: `Component`, `Page`, `Form`
- `MigrationType`: `Create`, `Update`, `Blank`, `Pivot`
- `FrontendFileState`: `Configured`, `Scaffoldable`, `Custom`

---

## Internal Collaborators

The generators share a few collaborators under `Studeo\Modular\Console\Generation`: `StubRenderer`, `ModuleGuard`, `ArtifactWriter`, `NameSanitizer`, `DirectoryPruner` and `LivewireMoveRewriter`. They are constructor-injected and not part of the public API. Commands share two thin traits in `Studeo\Modular\Console\Concerns`, the only traits in the package: `ChecksPrompting` (whether a command can ask a question) and `ReportsFailures` (the one way a command reports a failure: the message, the stack trace at `-v`, an exit code).
