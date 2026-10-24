# Studeo Modular for Laravel

<p align="center">
  <strong>A modular monolith for Laravel: modules under <code>modules/</code>, plain Composer PSR-4 autoloading, and generators that follow one architecture.</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/studeo/modular"><img src="https://img.shields.io/packagist/v/studeo/modular.svg?style=flat-square" alt="Latest version"></a>
  <a href="https://packagist.org/packages/studeo/modular"><img src="https://img.shields.io/packagist/php-v/studeo/modular.svg?style=flat-square" alt="PHP version"></a>
  <a href="#license"><img src="https://img.shields.io/packagist/l/studeo/modular.svg?style=flat-square" alt="License"></a>
</p>

## Status

Published for the maintainer's own use and offered as-is under the MIT license. The version line is `0.x`: a minor release may break, so pin `^0.1`. Issues and pull requests may go unanswered; forks are welcome. Security reports go through the private reporting route in [`SECURITY.md`](SECURITY.md). The principles are in [`DESIGN.md`](DESIGN.md) and the behavioural contract in [`SPEC.md`](SPEC.md).

---

## Table of Contents

<!-- generated:contents -->
- [Status](#status)
- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quickstart](#quickstart)
- [Module Layout](#module-layout)
- [The Module Manifest](#the-module-manifest)
- [Architecture in Brief](#architecture-in-brief)
- [Command Reference](#command-reference)
- [Running Without a Terminal](#running-without-a-terminal)
- [Schemas](#schemas)
- [Controllers and Inertia Pages](#controllers-and-inertia-pages)
- [Livewire 4](#livewire-4)
- [Inertia 3 and Vue 3](#inertia-3-and-vue-3)
- [Architecture Checks: module:check](#architecture-checks-modulecheck)
- [The Module Cache](#the-module-cache)
- [Configuration](#configuration)
- [More](#more)
- [License](#license)
<!-- /generated:contents -->

---

## Overview

Studeo Modular slices a Laravel application into modules that live in `modules/`. A module is a directory with a `module.json` manifest and a service provider. It is **not** a Composer package: installing a module writes PSR-4 entries into your application's root `composer.json`, and classes load at normal Composer speed.

- **Generators** for models, migrations, factories, seeders, policies, Actions, data objects, enums, controllers, events, listeners, jobs, mail, notifications, middleware, commands, Livewire components and Vue pages, all written inside a module.
- **Lifecycle commands** to move or delete Livewire and Vue components across modules, with files, namespaces and empty directories handled.
- **Schema files** that drive the model, migration, factory, data objects, forms and Action preconditions from one definition.
- **`module:check`**, an architecture checker that parses your module code and reports rule violations.

---

## Requirements

PHP and Laravel versions are declared in `composer.json`. Livewire and Inertia with Vue are optional integrations; the package supports one major of each and the installers refuse an older one, writing nothing and printing the upgrade commands.

| Integration | Needed for |
|-------------|------------|
| Livewire 4 | `module:livewire` and generated forms |
| Inertia 3 and Vue 3 | `module:vue`, and controllers with `--inertia` |

The package is developed and tested on Linux.

---

## Installation

```bash
composer require studeo/modular
```

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=modular-config
```

---

## Quickstart

```bash
# 1. Create a module in modules/Billing
php artisan module:make Billing

# 2. Register its PSR-4 entries in the root composer.json and dump the autoloader
php artisan module:install Billing

# 3. Generate inside it
php artisan module:model Invoice --module=Billing
php artisan module:action CreateInvoice --module=Billing
php artisan module:controller CreateInvoice --module=Billing --action=CreateInvoice --url=/invoices
php artisan module:vue Invoices/Show --module=Billing --page
php artisan module:livewire InvoiceHistory --module=Billing
```

`module:install --all` installs every module that is not registered yet. Run `php artisan module:check Billing` at any point to check the module against the architecture rules.

---

## Module Layout

```text
modules/Billing/
├── module.json                  # manifest: name and namespace
├── BillingServiceProvider.php   # registers the module through ModuleRegistrar
├── Actions/                     # one invokable Action per operation
├── ApiResources/                # API resources
├── Commands/                    # Artisan commands
├── Concerns/  Contracts/  Enums/  Events/  Exceptions/  Jobs/  Listeners/
├── Controllers/
├── Data/                        # Input and Data objects
├── Livewire/
├── Mail/  Middleware/  Models/  Notifications/  Policies/  Rules/  Services/
├── config/billing.php
├── database/{factories,migrations,seeders}/
├── lang/
├── resources/{css,js,views}/
├── routes/web.php
├── schema/                      # schema files, one per model
└── tests/
```

The layout is flat: PascalCase directories hold PHP classes and sit directly in the module root, lowercase directories hold other files, and the namespace matches the path. There is no `src/`, no `Http/` and no `Console/`. `module:make` creates the directories it needs; generators create the others on demand.

---

## The Module Manifest

`module.json` holds exactly two keys:

```json
{
    "name": "Billing",
    "namespace": "Billing"
}
```

- `name` must match the module's directory name.
- `namespace` is the module's root PHP namespace and is authoritative. With a root namespace prefix it reads `Studeo\\Billing`, for example.
- Everything else is derived by convention: the provider is `{namespace}\{Name}ServiceProvider`, and the module key used for config, views, translations and Livewire and Vue names is the kebab-case name (`BlogPosts` becomes `blog-posts`).

Three things read the manifest:

1. **`module:install`** (and `module:uninstall`, `module:remove`) to decide which PSR-4 entries to write or remove.
2. **Boot registration**: the package's service provider registers every installed module's provider at boot.
3. **The module picker**: commands that take `--module` and are run without it list the modules found by discovery. With no modules they fail with a hint to run `module:make`; with exactly one they use it and say so; with several they ask. Directories with no usable manifest are never offered.

A module made by an older version has a `composer.json` instead of a `module.json`. That is a hard error, never a silent skip: the command prints the exact `module.json` to create, for example:

```text
This module still uses a composer.json manifest, which is no longer read.
Create modules/Billing/module.json with:

{
    "name": "Billing",
    "namespace": "Billing"
}
```

A service provider that does not use `ModuleRegistrar` is also refused, with the replacement provider printed.

### PSR-4 entries

`module:install` writes three entries per module into the root `composer.json` and runs `composer dump-autoload`:

```json
{
    "autoload": {
        "psr-4": {
            "Billing\\": "modules/Billing/",
            "Billing\\Database\\Factories\\": "modules/Billing/database/factories/",
            "Billing\\Database\\Seeders\\": "modules/Billing/database/seeders/"
        }
    }
}
```

No shared prefix entry is ever written. `module:uninstall` removes the entries without touching files; `module:remove` deletes the directory of a module that is not installed.

---

## Architecture in Brief

**Actions** are `final` classes with a single `__invoke`. A generated `Create{X}` Action takes its Input and the acting user, authorizes `create` against the model, and does its work in `DB::transaction`:

```php
final class CreateInvoiceAction
{
    public function __invoke(CreateInvoiceInput $input, Authenticatable $actor): void
    {
        // authorization, then DB::transaction(...)
    }
}
```

`module:action --system` generates an Action marked `#[SystemAction]` that takes only its Input and does no authorization. It may be called only from other Actions, Listeners and Jobs; forms and controllers refuse it.

**Data objects** are `final readonly` classes in `Data/`, and the name suffix decides the kind (`module:dto` refuses a bare name):

- `…Input` extends `InputData`: data entering the module. It declares `rules()` and is built with `fromRequest($request, $context)` or `fromValidated($payload, $context)`, which validate first. There is no public `from()`. Properties marked `#[Context]` never come from the payload; they come only from the context array.
- `…Data` extends `OutputData`: data leaving the module, built with `from($source)` or `collect($items)`.

Wire keys are snake_case; `toArray()` and JSON serialization use them. Data objects are never Livewire state.

---

## Command Reference

The only single-letter shortcuts are `-r` (`module:controller --resource`), `-p` and `-f` (`module:vue --page` and `--form`), and `-p` on `module:vue:delete` and `module:vue:move`. Every other option is long-form.

### Modules

| Command | Arguments / Options | Description |
|---------|---------------------|-------------|
| `module:make` | `{module?} {--root-namespace=}` | Creates a module with its manifest and provider. `--root-namespace` overrides the configured prefix for this module; an empty value means no prefix. |
| `module:install` | `{module?} {--all}` | Writes the module's PSR-4 entries to the root `composer.json` and dumps the autoloader. |
| `module:uninstall` | `{module?}` | Removes the PSR-4 entries; files stay on disk. |
| `module:remove` | `{module} {--force}` | Deletes the directory of an uninstalled module. |
| `module:check` | `{module?} {--all} {--format=text}` | Checks one module, or every module, against the architecture rules. |
| `modular:cache`, `modular:clear` | | Build and remove the module cache. |

### Generators

Every generator takes an optional `{name}` and `--module=`.

| Command | Options | Output |
|---------|---------|--------|
| `module:model` | `--no-migration --no-factory --no-seeder --schema=` | `Models/`, plus a migration, factory and seeder by default |
| `module:action` | `--system --schema=` | `Actions/` and its paired Input in `Data/` |
| `module:dto` | `--schema=` | `Data/`; the name must end in `Input` or `Data` |
| `module:enum` | `--cases=` | `Enums/`, string-backed |
| `module:schema` | `--fields=` | `schema/{table}.yaml` |
| `module:schema:check` | `{name?} --except=` | Compares generated files with their schema |
| `module:controller` | see [Controllers](#controllers-and-inertia-pages) | `Controllers/` |
| `module:migration` | `--create= --table= --blank --pivot --morph` | `database/migrations/` |
| `module:factory`, `module:seeder` | `--model=` | `database/factories/`, `database/seeders/` |
| `module:policy` | `--model=` | `Policies/` |
| `module:pivot` | `{model1?} {model2?} --table= --no-migration --morph` | `Models/` and a migration |
| `module:resource` | `--collection` | `ApiResources/` |
| `module:rule` | `--implicit` | `Rules/` |
| `module:job` | `--sync --batched` | `Jobs/` |
| `module:event` | | `Events/` |
| `module:listener` | `--event= --queued` | `Listeners/` |
| `module:mail` | `--markdown= --view= --queued` | `Mail/` |
| `module:notification` | `--markdown= --queued` | `Notifications/` |
| `module:middleware` | | `Middleware/` |
| `module:command` | `--command=` | `Commands/` |

### Livewire and Vue

| Command | Options |
|---------|---------|
| `module:livewire` | `--classic --mfc --sfc --inline --page --layout= --title= --assets --no-test --schema= --action= --context=*` |
| `module:livewire:move` *(alias `:mv`)* | `{name?} {new-name?} --module= --to-module= --force` |
| `module:livewire:delete` *(alias `:rm`)* | `{name?} --module= --force` |
| `module:vue` *(alias `module:page`)* | `-p\|--page -f\|--form --schema= --action= --url=` |
| `module:vue:move` *(aliases `module:page:move`, `module:vue:mv`)* | `{name?} {new-name?} --module= --to-module= -p\|--page --force` |
| `module:vue:delete` *(aliases `module:page:delete`, `module:vue:rm`)* | `{name?} --module= -p\|--page --force` |

---

## Running Without a Terminal

A command that needs an answer only a prompt can give (a missing name or argument, a choice among several modules, a confirmation before something destructive) never prompts when there is no terminal on stdin or `--no-interaction` is set. It writes nothing, exits 1, and names the missing argument or option with one complete example:

```text
Name must be given when the command is not interactive, e.g. php artisan module:action CreatePost --module=Blog.
```

- `module:remove` needs `--force` without a terminal.
- `module:livewire:move` and `module:vue:move` need `[new-name]` and `--force`.
- `module:vue` and the installer never write root-app files (`vite.config.js`, `resources/js/app.js`, the root Blade view, Composer or npm changes) without an explicit confirmation. Without a terminal they print the contents and instructions instead, and still generate the module component.
- A value you give is never prompted for, `0` included.

---

## Schemas

A schema describes a model once. Commands that take `--schema` accept a CLI string, inline YAML or JSON, a file path, or `@name`, which reads `modules/{Module}/schema/{name}.yaml`. Text starting with `{` or `[` is JSON, text with a newline is YAML, anything else is the CLI form:

```bash
php artisan module:schema Post --module=Blog --fields="title:string,slug:string:unique,author:belongsTo(User)"
php artisan module:model Post --module=Blog --schema=@posts
php artisan module:schema:check Post --module=Blog
```

The types and modifiers are a closed list; anything else is an error and nothing is written. All problems in a schema are reported together.

- **Types**: `string`, `char`, `text`, `mediumText`, `longText`, the signed and unsigned integer family, `boolean`, `decimal`, `float`, `double`, `date`, `dateTime`, `timestamp`, `json`, `jsonb`, `uuid`, `ulid`, `foreignId`, `foreignUuid`, `foreignUlid`, `enum`.
- **Modifiers**: `nullable`, `unique`, `index`, `primary`, `unsigned`, `constrained`, `cascadeOnDelete`, `nullOnDelete`, `default(x)`, `cast(X)`, `hashed`, `guarded`, `fillable`, `context`.
- **Relationships**: `belongsTo`, `hasOne`, `hasMany`, `belongsToMany`. A target is a model of the same module or `User`.
- **Enums** are referenced by class name; create them with `module:enum`.

`module:schema` scaffolds the file; after that the file is yours. `module:schema:check` compares the model, the create migration, the factory and the Input and Data classes with the schema and exits 0 when nothing differs, 1 on drift or a section it cannot verify, 2 when the schema is invalid or missing.

With `--schema`, a `Create*` Action also gets a private guard per `unique` field and per foreign key, which throw a `ValidationException` keyed by the column. Uniqueness and existence are Action preconditions, not validation rules.

---

## Controllers and Inertia Pages

`module:controller` generates an **invokable** controller by default: one controller per Action.

| Option | Effect |
|--------|--------|
| *(none)* | Invokable controller. |
| `-r`, `--resource` | Resource controller. |
| `--api` | API resource controller. |
| `--standard` | Plain controller with no methods. |
| `--inertia` | Render Inertia pages instead of returning other responses. Valid on the default and on `--resource`; an error with `--api` or `--standard`. |
| `--model=` | With `--resource` or `--api`, a model in the module's `Models/` that `show`, `edit`, `update` and `destroy` bind. |
| `--action=` | The Action an invokable controller calls, for example `CreatePost`. It must exist unless `--schema` is given. |
| `--schema=` | With `--action`, generates the Action and its Input when missing. |
| `--context=field=actor` | With `--action`, where a `#[Context]` field of the Input comes from. |
| `--url=` | With `--action`, the URL of the `Route::post` line the command prints. |

`--resource`, `--api` and `--standard` exclude one another. Controllers extend nothing, receive their Action through method injection, take the user from `$request->user()` (aborting with 401 when absent) and build the Input with `fromRequest`.

**Route parameters.** A bound model parameter is resolved by implicit binding, so the route parameter must match the controller parameter name: for a `Post` model, `Route::get('/posts/{post}', ...)`. Implicit binding needs the `SubstituteBindings` middleware, which the `web` and `api` groups include.

**Inertia page names.** With `--inertia`, a controller renders `Inertia::render('{Module}::{Resource}/{Method}')`, where `{Resource}` is the controller name without `Controller` and `{Method}` is `Index`, `Create`, `Show` or `Edit`. A controller `PostsController` in module `Blog` renders `Blog::Posts/Index`. An invokable controller renders `{Module}::{Resource}`. `module:vue` prints the matching render call for the page it creates, `Inertia::render('{Module}::{name}')`; name the Vue page to match the controller. Page names use the module name (`BlogPosts::Posts/Index`), while Vue component and Livewire keys use the kebab module key.

---

## Livewire 4

Components are registered at boot under the module key, in the dot form generated code uses:

```blade
<livewire:shop.order-summary />
```

`module:livewire` generates one of three shapes:

```bash
# Classic (default): Livewire/OrderSummary.php and resources/views/livewire/order-summary.blade.php
php artisan module:livewire OrderSummary --module=Shop

# Multi-file: Livewire/⚡checkout-flow/ holding checkout-flow.php, checkout-flow.blade.php and checkout-flow.test.php
php artisan module:livewire CheckoutFlow --module=Shop --mfc

# Single-file: Livewire/⚡quick-search.blade.php
php artisan module:livewire QuickSearch --module=Shop --sfc
```

A test is generated for every component type unless `--no-test` is given (a multi-file component keeps it in its own directory); `--assets` adds empty `.css` and `.js` files to a multi-file component.

With `--schema` the component is a single-file form that delegates its rules to the Action's paired Input and saves through an Action (`--action=`, generated when missing, with `--context=field=actor` for `#[Context]` fields). Livewire state is plain snake_case values, never data objects:

```bash
php artisan module:livewire Posts/Create --module=Blog --schema=@posts --action=CreatePost
```

---

## Inertia 3 and Vue 3

`module:vue` writes Vue 3 `<script setup>` files in the module's `resources/js/`:

```bash
php artisan module:vue PostCard --module=Blog                      # resources/js/Components/PostCard.vue
php artisan module:vue Posts/Index --module=Blog --page            # resources/js/Pages/Posts/Index.vue
php artisan module:vue Posts/Create --module=Blog --schema=@posts --action=CreatePost --url=/posts
```

`--schema` implies a form page and requires `--action` and `--url`; `--action` and `--url` without a form are an error. The form uses `useForm()`, validates on the server only, and shows json fields as JSON text.

**Page path.** Inertia 3's default page path is `resource_path('js/pages')` (lowercase). Modules keep their pages in `resources/js/Pages` (capital P). The generated `resources/js/app.js` resolver loads module pages explicitly, so this only matters if you enable Inertia's page existence check or write your own resolver: point it at `modules/*/resources/js/Pages`.

The installer needs Inertia 3 on both sides. Before it changes anything in your application (`composer require`, `npm install`, `vite.config.js`, `resources/js/app.js`, the root view) it asks for confirmation; without a terminal it prints instructions and leaves your files alone. A custom `app.js` or Vite configuration is never overwritten. The resolver it generates handles module pages by the `Module::Page` form:

```javascript
resolve: (name) => {
    if (name.includes('::')) {
        const [module, page] = name.split('::');
        return resolvePageComponent(
            `../../modules/${module}/resources/js/Pages/${page}.vue`,
            import.meta.glob('../../modules/**/resources/js/Pages/**/*.vue')
        );
    }

    return resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));
},
```

Vue enum fields are text inputs for now.

---

## Architecture Checks: module:check

`module:check` parses the PHP files of a module and reports violations of the architecture rules. It never loads or runs module code. The rule engine and the generic rules come from the `studeo/check` package that Modular depends on.

```bash
php artisan module:check
php artisan module:check Billing
php artisan module:check modules/Billing
php artisan module:check --all
php artisan module:check --all --format=json
```

Which modules are checked:

| You run | In a terminal | With no terminal (a script, CI) |
|---------|---------------|---------------------------------|
| `module:check` | asks which module, with `All modules` as the last choice (a single module is used without asking) | checks every module |
| `module:check --all` | checks every module | checks every module |
| `module:check Billing` or `module:check modules/Billing` | checks that module | checks that module |

The argument is a module name, or the folder of a module directly under the modules root, written relative to the application or in full. Anything else (a file, a folder inside a module, a folder elsewhere, an unknown name, a name in the wrong case) stops with exit code 2 and names the modules that exist, for example `[Ghost] is not a module. Pass a module name or its folder under modules. Modules: Account, Blog.` A module and `--all` together are refused. Directories under the modules root that are not modules are reported, and make the run exit 2, only when every module is checked.

Text output is one line per finding, `path:line [rule] message`, each message with a fix hint. `--format=json` prints only the findings array on standard output, as objects with `rule`, `file`, `line`, `subject`, `severity` and `message`.

| Exit code | Meaning |
|-----------|---------|
| 0 | No errors. Warnings never fail the run. |
| 1 | At least one error. |
| 2 | A module could not be read, the argument is not a module, a directory was skipped while every module was checked, the rule configuration is invalid, a module was combined with `--all`, `--format` is unknown, or the check itself failed. |

<!-- generated:default-rules -->
The default rules are `action-authorization`, `action-shape`, `condition-extraction`, `controller-shape`, `dto-shape`, `file-length`, `flat-modules`, `method-complexity`, `method-naming`, `migration`, `module-isolation`, `nesting-depth`, `no-dto-in-livewire-state`, `step-count` and `system-action-usage`.
<!-- /generated:default-rules -->

To change a limit or add rules, extend `Studeo\Modular\Check\DefaultRules`, return your own `Studeo\Check\Engine\RuleSet` from `rules()`, and name your class in `config/modular.php`:

```php
'check' => [
    'rules' => App\Architecture\Rules::class,
],
```

The class is built only by `module:check`. There is one rule set for the whole application. There is no baseline for application modules.

---

## The Module Cache

At boot each module's registrar scans the module for listeners, policies and middleware. `modular:cache` writes what it finds to `bootstrap/cache/modular.php`, so production boots skip the scan:

```bash
php artisan modular:cache    # write the cache
php artisan modular:clear    # remove it
```

Both are hooked into `php artisan optimize` and `optimize:clear`, so a deploy that runs `optimize` builds the cache. If the cache cannot be written or a module fails to load, `optimize` shows the failure, marks the step as failed and exits 1. `modular:cache` writes nothing, prints each failure and exits 1 when an installed module fails to load or two modules claim the same middleware alias. Entries are never checked against the module's files: after adding or removing a listener, policy or middleware, run `modular:cache` again (or `modular:clear`). A missing, corrupt or unrecognised cache file is ignored and the registrar scans live.

Long-lived workers (Octane, queue workers) read the cache once per process. Restart them after `modular:cache` or `modular:clear`.

---

## Configuration

`config/modular.php`:

```php
return [
    // Where modules live.
    'root_path' => base_path('modules'),

    // Namespace prefix of every module made from now on. null means no prefix
    // (module Account is "Account"); 'Studeo' gives "Studeo\Account".
    'root_namespace' => null,

    // The class module:check asks for its rules.
    'check' => [
        'rules' => DefaultRules::class,
    ],
];
```

`module:make --root-namespace=Acme` overrides the prefix for one module, and `--root-namespace=` (empty) means no prefix. The namespace is written to the module's `module.json` when the module is made and that file is what every other command reads, so changing the setting later does not move existing modules. The first segment may not be `App`, `Database`, `Tests`, `Illuminate`, `Livewire`, `Inertia`, `Laravel`, `Symfony`, `Composer`, `PHPUnit` or `Pest`.

---

## More

- [`DESIGN.md`](DESIGN.md): principles and non-goals.
- [`SPEC.md`](SPEC.md): the manifest, layout, command and exit-code contract.
- [`API.md`](API.md): public classes and extension points.
- [`CONTRIBUTING.md`](CONTRIBUTING.md): the contribution stance.

## License

[MIT](LICENSE).
