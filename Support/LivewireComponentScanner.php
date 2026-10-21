<?php

namespace Studeo\Modular\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Studeo\Modular\DataObjects\LivewireComponentDefinition;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Reads one module's Livewire directory and resolves each classic, MFC and SFC
 * component file into a definition.
 */
final class LivewireComponentScanner
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly LivewirePaths $livewirePaths,
    ) {}

    /**
     * @return array<string, LivewireComponentDefinition>
     */
    public function scan(ModuleDefinition $module): array
    {
        $livewirePath = $module->path.DIRECTORY_SEPARATOR.'Livewire';

        if (! $this->files->isDirectory($livewirePath)) {
            return [];
        }

        return collect($this->files->allFiles($livewirePath))
            ->reject(fn (SplFileInfo $file): bool => $this->isExcluded($file))
            ->map(fn (SplFileInfo $file): ?LivewireComponentDefinition => $this->resolveComponent($module, $file))
            ->filter()
            ->unique(fn (LivewireComponentDefinition $comp): string => $comp->alias)
            ->keyBy(fn (LivewireComponentDefinition $comp): string => $comp->alias)
            ->all();
    }

    private function isExcluded(SplFileInfo $file): bool
    {
        return $this->isExcludedPath(str_replace('\\', '/', $file->getRelativePathname()));
    }

    private function isExcludedPath(string $relative): bool
    {
        return $this->isReservedSubdirectory($relative) || $this->isTestFile($relative) || $this->isNonComponentFile($relative);
    }

    private function isReservedSubdirectory(string $relative): bool
    {
        return Str::startsWith($relative, ['Forms/', 'Attributes/']);
    }

    private function isTestFile(string $relative): bool
    {
        return Str::endsWith($relative, ['.test.php', 'Test.php']);
    }

    private function isNonComponentFile(string $relative): bool
    {
        return ! Str::endsWith($relative, ['.php', '.blade.php']);
    }

    private function resolveComponent(ModuleDefinition $module, SplFileInfo $file): ?LivewireComponentDefinition
    {
        $segments = explode('/', str_replace('\\', '/', $file->getRelativePathname()));
        $zapDirectory = $this->zapDirectoryIndex($segments);

        return match (true) {
            $zapDirectory !== null => $this->resolveMfc($module, $file, $segments, $zapDirectory),
            str_ends_with($file->getFilename(), '.blade.php') => $this->resolveSfc($module, $file, $segments),
            default => $this->resolveClassic($module, $file),
        };
    }

    /**
     * The position of the first ⚡ directory in the path, which makes the file a member of a
     * multi-file component.
     *
     * @param  array<int, string>  $segments
     */
    private function zapDirectoryIndex(array $segments): ?int
    {
        $directories = array_slice($segments, 0, -1, true);

        return array_key_first(array_filter($directories, $this->isZapDirectory(...)));
    }

    private function isZapDirectory(string $segment): bool
    {
        return str_starts_with($segment, '⚡');
    }

    /**
     * A multi-file component is the directory ⚡{name} with {name}.php beside {name}.blade.php.
     * Only the {name}.php file stands for it; every other member file is not a component.
     *
     * @param  array<int, string>  $segments
     */
    private function resolveMfc(ModuleDefinition $module, SplFileInfo $file, array $segments, int $zapDirectory): ?LivewireComponentDefinition
    {
        if (! $this->isMfcMainFile($file, $segments, $zapDirectory)) {
            return null;
        }

        $name = implode('/', array_map($this->stripZap(...), array_slice($segments, 0, $zapDirectory + 1)));
        $dir = dirname($file->getRealPath());

        return new LivewireComponentDefinition(
            module: $module,
            name: $name,
            alias: $this->livewirePaths->formatKebabPath($name),
            target: $dir,
            path: $dir,
            type: 'mfc',
            files: $this->resolveMfcFiles($dir),
        );
    }

    /** @param  array<int, string>  $segments */
    private function isMfcMainFile(SplFileInfo $file, array $segments, int $zapDirectory): bool
    {
        return $zapDirectory === count($segments) - 2
            && $file->getFilename() === $this->stripZap($segments[$zapDirectory]).'.php';
    }

    /**
     * @return array<string>
     */
    private function resolveMfcFiles(string $dir): array
    {
        return collect($this->files->files($dir))
            ->map(fn (\SplFileInfo $file): string => $file->getRealPath())
            ->all();
    }

    /**
     * @param  array<int, string>  $segments
     */
    private function resolveSfc(ModuleDefinition $module, SplFileInfo $file, array $segments): LivewireComponentDefinition
    {
        $clean = substr(implode('/', array_map($this->stripZap(...), $segments)), 0, -10);

        return new LivewireComponentDefinition(
            module: $module,
            name: $clean,
            alias: $this->livewirePaths->formatKebabPath($clean),
            target: $file->getRealPath(),
            path: $file->getRealPath(),
            type: 'sfc',
            files: $this->resolveSfcFiles($module, $file, $clean),
        );
    }

    private function stripZap(string $segment): string
    {
        return str_replace(["⚡\u{FE0E}", "⚡\u{FE0F}", '⚡'], '', $segment);
    }

    /**
     * @return array<string>
     */
    private function resolveSfcFiles(ModuleDefinition $module, SplFileInfo $file, string $name): array
    {
        return array_values(array_filter([$file->getRealPath(), $this->livewirePaths->resolveExistingFeatureTestPath($module, $name)]));
    }

    private function resolveClassic(ModuleDefinition $module, SplFileInfo $file): ?LivewireComponentDefinition
    {
        $name = $this->resolveClassicComponentName($file);

        $target = $this->resolveClassFqcn($file);
        if ($target === null) {
            return null;
        }

        return new LivewireComponentDefinition(
            module: $module,
            name: $name,
            alias: $this->livewirePaths->formatKebabPath($name),
            target: $target,
            path: $file->getRealPath(),
            type: 'classic',
            files: $this->resolveClassicFiles($module, $file, $name),
        );
    }

    private function resolveClassicComponentName(SplFileInfo $file): string
    {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        return substr($relative, 0, -4);
    }

    /** @return list<string> */
    private function resolveClassicFiles(ModuleDefinition $module, SplFileInfo $file, string $name): array
    {
        $files = [$file->getRealPath()];
        $viewPath = $this->livewirePaths->resolveClassicViewPath($module, $name);

        if ($this->files->isFile($viewPath)) {
            $files[] = $viewPath;
        }

        $testPath = $this->livewirePaths->resolveFeatureTestPath($module, $name);
        if ($this->files->isFile($testPath)) {
            $files[] = $testPath;
        }

        return $files;
    }

    private function resolveClassFqcn(SplFileInfo $file): ?string
    {
        $content = $file->getContents();

        if (! preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }

        if (! preg_match('/class\s+([A-Za-z0-9_]+)/', $content, $classMatch)) {
            return null;
        }

        return trim($namespaceMatch[1]).'\\'.trim($classMatch[1]);
    }
}
