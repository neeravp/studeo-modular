<?php

use Studeo\Modular\Check\DefaultRules;

return [

    /*
    |--------------------------------------------------------------------------
    | Module Root Path
    |--------------------------------------------------------------------------
    |
    | The directory where all application modules are stored.
    |
    */

    'root_path' => base_path('modules'),

    /*
    |--------------------------------------------------------------------------
    | Root Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace prefix of every module made from now on. Null (the default)
    | means no prefix: module Account has the namespace Account. "Studeo" gives
    | Studeo\Account, and several segments such as "Acme\Platform" are allowed.
    |
    | `php artisan module:make Account --root-namespace=Acme` overrides it for
    | one module, and `--root-namespace=` (empty) means no prefix for that module.
    |
    | A module's namespace is written to its module.json when it is made, and
    | that file is what every other command reads. Changing this setting later
    | does not move modules that exist.
    |
    | The first segment of a module's namespace may not be App, Database, Tests,
    | Illuminate, Livewire, Inertia, Laravel, Symfony, Composer, PHPUnit or Pest.
    |
    */

    'root_namespace' => null,

    /*
    |--------------------------------------------------------------------------
    | Architecture Rules
    |--------------------------------------------------------------------------
    |
    | The class `php artisan module:check` asks for its rules. It needs a
    | rules() method that returns a RuleSet. To change a limit or add your own
    | rules, extend DefaultRules in your app and name your class here. The class
    | is only built by module:check.
    |
    */

    'check' => [
        'rules' => DefaultRules::class,
    ],

];
