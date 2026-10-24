# Changelog

Breaking and notable changes per release, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). While the version is `0.x`, a minor release may break.

## [Unreleased]

## [0.2.0]

### Changed

- Breaking: `module:check` takes the module as an argument, a name or the module's folder (`module:check Billing`, `module:check modules/Billing`). The `--module` option is removed. An argument that is not a module exits 2 and names the modules that exist.
- Breaking: `module:check` with no argument and no terminal checks every module instead of stopping. In a terminal it asks, and the list ends with `All modules`. `--all` stays.

## [0.1.0]

First release: modules with a manifest, the generators, the schema commands, the Livewire and Vue integrations, the module cache, and `module:check` on top of `studeo/check`.
