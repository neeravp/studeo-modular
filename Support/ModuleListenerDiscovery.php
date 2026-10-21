<?php

namespace Studeo\Modular\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Support\Str;
use SplFileInfo;

/**
 * Laravel's listener discovery, with class names taken from the module's own
 * namespace instead of the app's. Passing the module directory as $basePath
 * keeps this free of the global guessClassNamesUsing() callback.
 *
 * Builds on DiscoverEvents::within() and getListenerEvents(); a test guards the
 * parts of Laravel it relies on.
 */
final class ModuleListenerDiscovery extends DiscoverEvents
{
    /**
     * @param  string  $basePath  the module directory
     * @return class-string
     */
    protected static function classFromFile(SplFileInfo $file, $basePath)
    {
        $namespace = (new ResolveModuleManifestAction(new Filesystem))->forDirectory($basePath)->namespace;
        $relative = Str::after($file->getPathname(), rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

        /** @var class-string $class */
        $class = $namespace.'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', Str::replaceLast('.php', '', $relative));

        return $class;
    }
}
