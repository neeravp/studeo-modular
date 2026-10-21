<?php

namespace Studeo\Modular\Check;

/**
 * The directories a module root may hold. FlatModules reads these as its
 * defaults and the layout conformance test reads them to check what module:make scaffolds.
 */
final class ModuleLayout
{
    /** PascalCase directories that hold PHP classes, directly under the module root. */
    public const CLASS_DIRECTORIES = ['Actions', 'ApiResources', 'Commands', 'Concerns', 'Contracts', 'Controllers', 'Data', 'Enums', 'Events', 'Exceptions', 'Jobs', 'Listeners', 'Livewire', 'Mail', 'Middleware', 'Models', 'Notifications', 'Policies', 'Rules', 'Services'];

    /** Lowercase directories that hold files that are not namespaced classes. */
    public const DATA_DIRECTORIES = ['config', 'database', 'lang', 'resources', 'routes', 'schema', 'tests'];
}
