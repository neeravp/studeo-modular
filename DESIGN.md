# Design

Modular is deliberately opinionated. This document states the principles behind it and the reason for each. The behavioural contract is in [`SPEC.md`](SPEC.md), the public classes in [`API.md`](API.md), and the architecture checker that enforces much of this is the separate [`studeo/check`](https://packagist.org/packages/studeo/check) package.

## What Modular is for

Modular slices a Laravel application into modules and generates code that follows one architecture, so that a module written today and one written in two years look alike and can be understood without a guide.

## Principles

### 1. A module is a directory, not a package

A module lives under `modules/`. Installing it writes its PSR-4 entries into the application's root `composer.json`; classes load at normal Composer speed.
*Why:* a package per module adds versioning, release and dependency work that a monolith does not need.

### 2. One small manifest

`module.json` holds a name and a namespace and nothing else. The provider, the PSR-4 entries and the module key are derived by convention. A module made by an older layout is a loud error that prints the exact file to create.
*Why:* a single source of identity cannot disagree with itself, and a silent fallback hides a broken module.

### 3. Flat, mirrored layout

Class directories sit directly in the module root and mirror Laravel's own folders; the namespace follows the path; there is no `src/`, `Http/` or `Console/`.
*Why:* a predictable place for every class means nobody searches and tools can check the layout.

### 4. Modules do not reach into each other

A module uses another only through its `Contracts`, `Data` and `Events`. Foreign keys across modules are soft.
*Why:* isolation is what lets a module change, or be removed, without a hunt through the rest of the application.

### 5. Modules touch the root application in two places only

The PSR-4 entries and the package's service provider, which registers each module's own provider. Registration is explicit and module-owned; nothing relies on Laravel's conventional discovery paths. Anything else in the root application, such as the Vite configuration, changes only after an explicit confirmation, and is printed as instructions when there is no terminal.
*Why:* a generator that edits files it does not own is a generator nobody trusts.

### 6. Data crossing a boundary is immutable and validated first

An `Input` validates, then hydrates; a `Data` object carries data out. The suffix of the name decides which. Wire keys are snake_case. Data objects are never Livewire state.
*Why:* invalid data never becomes an object, and what crosses a boundary cannot change behind your back.

### 7. Business logic lives in single-purpose Actions

An Action is a `final` class with one `__invoke`, authorizes before it acts, and does its work in a transaction. An Action that must run without an actor is marked `#[SystemAction]` and can be called only from other Actions, Listeners and Jobs. Authorization fails closed: an ability nobody defined denies.
*Why:* one place per operation keeps controllers and components thin and makes authorization impossible to forget.

### 8. Generated code obeys the same rules as written code

Whatever a generator emits passes every architecture rule, is Pint-clean on first run, loads in a fresh process, and is written only inside the module.
*Why:* a generator that produces code you must immediately fix teaches you to ignore its output.

### 9. Code reads as a short narrative

Methods are a list of same-level steps with named helpers; compound conditions get a name. `module:check` enforces this through Check's structural rules.
*Why:* you must be able to read a method in two years without opening its helpers.

### 10. Fail loudly and name the fix

A command that needs an answer it cannot ask for stops with a message that names the missing argument and shows one full example; it writes nothing. A module that fails to load is reported with the cause and never breaks the console. A given value is never "missing": `0` is a value. A command whose natural default is every module (`module:install`, `module:check`) acts on all of them when there is no terminal to ask in, because that is documented, not guessed.
*Why:* silence and guessing waste more time than an error message.

### 11. One definition, closed vocabulary

A schema describes a model once and drives the model, migration, factory, data objects, forms and Action preconditions. Its field types and modifiers are a closed list; an unknown one is an error, and all problems are reported together.
*Why:* an open vocabulary means every layer must guess what an unknown word means.

### 12. Composition over traits

Collaborators are injected through the constructor. The only traits are two thin command helpers that Laravel's command class makes awkward to avoid.
*Why:* a trait hides its dependencies in the class that uses it; a collaborator names them.

### 13. Linux is the target

Nothing may depend on a case-insensitive disk.
*Why:* production runs on Linux, and a macOS-only pass is a deployment bug.

## Non-goals

- Modules as separately versioned Composer packages, or dependencies between modules.
- Support for Livewire, Inertia or Vue majors older than the ones the package documents.
- Editing routes or the root application's files without confirmation.
- Form Request classes: validation lives in `Input` data objects.
- macOS and Windows as test targets.
