# Modular Specification

The behavioural contract of `studeo/modular`: what a module is, what the commands promise, and what they write. The principles behind it are in [`DESIGN.md`](DESIGN.md); command options and guides are in [`README.md`](README.md); public classes are in [`API.md`](API.md). The architecture checker behind `module:check` is specified in the `studeo/check` package: its rule contract, rule options, violation record, JSON output and exit codes are defined in that package's `SPEC.md`.

While the version line is `0.x`, a change to anything in this document, such as a command name, an exit code, the manifest or the layout, may break and is recorded in `CHANGELOG.md`.

## 1. The module manifest

Each module has one manifest, `module.json`, at the module root. It holds exactly two keys:

```json
{
    "name": "Billing",
    "namespace": "Billing"
}
```

- `name` equals the module's directory name.
- `namespace` is the module's root PHP namespace and is authoritative. It is written when the module is made and is never re-derived from configuration.
- The manifest is written through `ModuleManifest::toJson()`, never by hand.

Everything else is derived by convention:

| Derived value | Rule |
| --- | --- |
| Service provider | `{namespace}\{name}ServiceProvider`, in the module root |
| Module key | the kebab-case name (`BlogPosts` becomes `blog-posts`); used for config, view and translation namespaces, Livewire aliases, Vue component keys, layout references and middleware aliases |
| PSR-4 entries | three per module: the namespace at `modules/{name}/`, `{namespace}\Database\Factories\` at `database/factories/`, and `{namespace}\Database\Seeders\` at `database/seeders/` |

A module with a `composer.json` and no `module.json` is a hard error that prints the exact `module.json` to create. A provider that does not use `ModuleRegistrar` is refused, with the replacement provider printed. A manifest failure is never swallowed.

<!-- generated:reserved-segments -->
The first namespace segment may not be `App`, `Database`, `Tests`, `Illuminate`, `Livewire`, `Inertia`, `Laravel`, `Symfony`, `Composer`, `PHPUnit` or `Pest`.
<!-- /generated:reserved-segments -->

## 2. Module layout

```text
modules/{Name}/
├── module.json
├── {Name}ServiceProvider.php
├── <class directories>
└── <data directories>
```

- Root files: `module.json`, `{Name}ServiceProvider.php`; no other PHP file.
<!-- generated:layout-directories -->
- Class directories (PascalCase, hold PHP classes): `Actions`, `ApiResources`, `Commands`, `Concerns`, `Contracts`, `Controllers`, `Data`, `Enums`, `Events`, `Exceptions`, `Jobs`, `Listeners`, `Livewire`, `Mail`, `Middleware`, `Models`, `Notifications`, `Policies`, `Rules`, `Services`
- Data directories (lowercase, hold other files): `config`, `database`, `lang`, `resources`, `routes`, `schema`, `tests`
<!-- /generated:layout-directories -->

- A class directory sits directly in the module root. Subfolders inside it are allowed for grouping, and the namespace follows the path.
- Every named class has the namespace its path implies and a name equal to its file name.
- Any other root directory (`Http`, `Console`, `src`, `Requests`, `Providers`) is an error. A `module.json` or `modules/` below the root is a nested module and an error.
- `vendor/` and `node_modules/` are skipped everywhere.
- The `flat-modules` rule of `module:check` enforces this.

## 3. The two touchpoints with the root application

1. The module's PSR-4 entries in the root `composer.json`, written by `module:install` and removed by `module:uninstall`. Install runs `composer dump-autoload` and restores `composer.json` if that fails.
2. The package's service provider, which registers each installed module's own provider at boot.

No generator writes outside the module directory, and `bootstrap/providers.php`, `config/*` and the root `routes/*` are never edited. The package writes `bootstrap/cache/modular.php` only through `modular:cache`. `module:vue` and the installers change root files (`vite.config.js`, `resources/js/app.js`, the root view, Composer or npm packages) only after an explicit confirmation; without a terminal they print the contents and instructions and still generate the module component.

A module whose provider fails to load never breaks `artisan`: the console warns on every command and HTTP reports the failure, throwing only when `app.debug` is on. "Installed" means a root `composer.json` PSR-4 entry.

## 4. Commands

| Group | Commands |
| --- | --- |
| Modules | `module:make`, `module:install`, `module:uninstall`, `module:remove`, `module:check` |
| Generators | `module:model`, `module:action`, `module:dto`, `module:enum`, `module:controller`, `module:migration`, `module:factory`, `module:seeder`, `module:policy`, `module:pivot`, `module:resource`, `module:rule`, `module:job`, `module:event`, `module:listener`, `module:mail`, `module:notification`, `module:middleware`, `module:command` |
| Schema | `module:schema`, `module:schema:check` |
| Frontend | `module:livewire`, `module:livewire:move`, `module:livewire:delete`, `module:vue`, `module:vue:move`, `module:vue:delete` |
| Cache | `modular:cache`, `modular:clear`, and the hidden `modular:optimize` that the `optimize` command runs |

<!-- generated:shortcuts -->
- Options are long-form. The only shortcuts are `-f` (`module:vue --form`), `-p` (`module:vue --page`, and on `module:vue:delete` and `module:vue:move`) and `-r` (`module:controller --resource`).
<!-- /generated:shortcuts -->
- A command that takes `--module` and is given none uses the module picker: no modules is an error with a hint to run `module:make`; exactly one module is used and a line says so; several are offered through a selection.
- Directories without a usable manifest are never offered.

### Exit codes

| Command | 0 | 1 | 2 |
| --- | --- | --- | --- |
| Every command except the two below | success | any failure (message on stderr, stack trace at `-v`; nothing printed under `--quiet`) | not used |
| `module:check` | no errors; warnings never fail | at least one error | a module could not be read, the argument is not a module, a directory was skipped while every module was checked, the rule configuration is invalid, a module was combined with `--all`, `--format` is unknown, or the check failed |
| `module:schema:check` | no drift | drift, or a section it cannot verify | the schema is invalid or missing |

### Without a terminal

A command that needs an answer only a prompt can give (a missing name or argument, a choice, a confirmation guarding something destructive) never prompts when stdin is not a terminal or `--no-interaction` is set. It writes nothing, exits 1, and names the missing argument or option with one complete example.

- `module:remove` needs `--force`; `module:livewire:move` and `module:vue:move` need `[new-name]` and `--force`.
- A given value is never prompted for, `0` included.

## 5. Generated code

Whatever a generator writes:

- passes every rule of the default rule set, with no manual fixing;
- is formatted as Pint's default Laravel preset formats it, under any module namespace (a prefixed one or none);
- declares classes that load in a fresh PHP process;
- imports every class it names, contains no `//` comment, `TODO`, placeholder or Laravel sample text, and uses `{}` for empty bodies and `[]` for empty arrays;
- is written only inside the module directory, with the exceptions in section 3;
- refuses, before writing anything, a class name whose base name starts with a digit (`Invalid {type} name [x].`). View, markdown, Vue, Livewire single-file and multi-file, and migration names accept digit-leading names.

## 6. Data objects, Actions and authorization

- `*Input` extends `InputData` (validated entry points, protected constructor, no public `from()`); any other `*Data` extends `OutputData`. The suffix decides; `module:dto` refuses a bare name.
- Wire keys are snake_case; properties are camelCase. Data objects are never Livewire state.
- An Action is a `final` class named `*Action` with one public method, `__invoke`. An authorizing Action takes an `Authenticatable $actor` and calls `Gate::forUser($actor)->authorize(...)`. A `Create{X}` Action whose model exists authorizes `create` against the model class; any other Action authorizes its kebab-case ability, which denies until someone defines it, and generating one prints a notice.
- `#[SystemAction]` marks an Action that does no authorization, takes no actor, and is referenced only from Actions, Listeners and Jobs.
- Uniqueness and existence are Action preconditions, never validation rules. A `Create*` Action generated from a schema gets one guard per `unique` field and per foreign key; above the default step limit the guards are grouped by kind, and more than the limit of one kind is refused before anything is written.

## 7. Schema

A schema is a CLI string, inline YAML or JSON, a file path, or `@name` (`modules/{Module}/schema/{name}.yaml`). Text starting with `{` or `[` is JSON, text containing a newline is YAML, anything else is the CLI form.

- **Types** (a closed list): `string`, `char`, `text`, `mediumText`, `longText`, the signed and unsigned integer family, `boolean`, `decimal`, `float`, `double`, `date`, `dateTime`, `timestamp`, `json`, `jsonb`, `uuid`, `ulid`, `foreignId`, `foreignUuid`, `foreignUlid`, `enum`.
- **Modifiers** (a closed list): `nullable`, `unique`, `index`, `primary`, `unsigned`, `constrained`, `cascadeOnDelete`, `nullOnDelete`, `default(x)`, `cast(X)`, `hashed`, `guarded`, `fillable`, `context`.
- **Relationships**: `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`; the target is a model of the same module or `User`.
- **Enums** are referenced by short class name and are string-backed with values of 30 characters or fewer; the column is `string(x, 30)->index()`, never `->enum()`.
- Reserved field names: `id`, `created_at`, `updated_at`, `deleted_at` and Eloquent's own property names.
- An unknown type, modifier or relationship is an error, all problems are reported together, and nothing is written.
- `module:schema` writes the file once and never overwrites it. `module:schema:check` compares the model, the create migration, the factory and the `Input` and `Data` classes with the schema.

## 8. `module:check`

- Rules: those of Check's `Cap1Rules`, `ActionRules` and `LaravelRules` profiles, plus `module-isolation`, `flat-modules` and `controller-shape`, in `Studeo\Modular\Check\DefaultRules`.
- Which modules: the one given as the argument; otherwise every module when `--all` is passed or there is no terminal to ask in; otherwise the one chosen from a list that ends with `All modules`. A single module is used without asking. A module together with `--all` is refused.
- The argument is a module name, or the folder of a module directly under the modules root, relative to the application or in full. It must be the exact name of a directory under the root. Anything else exits 2 with a message that names the modules that exist and writes nothing to standard output.
- Directories under the modules root that are not usable modules are reported on standard error, and the run exits 2, only when every module is checked, however that was decided. A run for one module ignores them. Every module's namespace is known to the rules whichever modules are checked.
- `config('modular.check.rules')` names a class whose `rules()` returns a `RuleSet`. It is built only inside `module:check`. There is one rule set for the whole application and no baseline for application modules.
- Output is one line per finding, `path:line [rule] message`, or with `--format=json` a findings array on standard output; every other message goes to standard error. The format is Check's.
- The command parses files and never loads module code.

## 9. Module cache

`modular:cache` writes `bootstrap/cache/modular.php`: a versioned file of plain arrays keyed by module key, holding each module's listeners, policies and middleware. Commands and Livewire components are never cached.

- A missing, corrupt, unknown-version or malformed file reads as absent; the registrar then scans live.
- Entries are never checked against the module's files: run `modular:cache` or `modular:clear` after changing a listener, policy or middleware.
- `modular:cache` refuses to write, prints each cause and exits 1 when an installed module fails to load or two modules claim one middleware alias. Writing and clearing report failures with exit 1.
- `optimize` and `optimize:clear` run the cache commands; a failure marks the `optimize` step as failed.
- The cache is read once per process: restart long-lived workers after changing it.

## 10. Configuration

`config/modular.php` has three keys.

| Key | Meaning |
| --- | --- |
| `root_path` | the directory that holds the modules |
| `root_namespace` | the namespace prefix of every module made from now on; `null` means no prefix; read only by `module:make` |
| `check.rules` | the class `module:check` asks for its rules |
