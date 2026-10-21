<?php

namespace Studeo\Modular\Check;

use PhpParser\Parser;
use PhpParser\ParserFactory;
use SplFileInfo;
use Studeo\Check\Engine\ClassIndex;
use Studeo\Check\Engine\Scope;
use Studeo\Check\Engine\SourceFile;
use Studeo\Check\Engine\SourceSet;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Symfony\Component\Finder\Finder;

/**
 * What a rule sees of one module: its PHP files (never `.blade.php`), found on disk and parsed
 * without loading anything, and which module owns a class name.
 */
final class ModuleSource implements SourceSet
{
    /** @var list<SourceFile>|null */
    private ?array $files = null;

    private ?ClassIndex $classIndex = null;

    private readonly Parser $parser;

    /**
     * @param  array<string, string>  $prefixes  namespace prefix with a trailing backslash => module name, for every module
     */
    public function __construct(
        public readonly ModuleDefinition $module,
        private readonly array $prefixes,
        private readonly string $basePath,
    ) {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return [$this->module->path];
    }

    /**
     * @return list<SourceFile>
     */
    public function files(?Scope $scope = null): array
    {
        $all = $this->files ??= $this->collect();

        if ($scope === null) {
            return $all;
        }

        return array_values(array_filter($all, fn (SourceFile $file): bool => $scope->matches($file->relativePath)));
    }

    /**
     * The module's named classes by full name, built once.
     */
    public function classIndex(): ClassIndex
    {
        return $this->classIndex ??= ClassIndex::of($this->files());
    }

    /**
     * The module a class name belongs to and the rest of the name after the module's namespace,
     * by the longest namespace prefix; null for a name no module owns.
     *
     * @return array{module: string, remainder: string}|null
     */
    public function ownerOf(string $name): ?array
    {
        $matching = array_filter(array_keys($this->prefixes), fn (string $prefix): bool => str_starts_with($name, $prefix));
        usort($matching, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $matching === [] ? null : ['module' => $this->prefixes[$matching[0]], 'remainder' => substr($name, strlen($matching[0]))];
    }

    /**
     * The name of the module that owns a class name, or null when no module does.
     */
    public function moduleOf(string $name): ?string
    {
        return $this->ownerOf($name)['module'] ?? null;
    }

    /**
     * The first namespace segment of a class name below its module's namespace (`Actions`,
     * `Data`), or null when no module owns the name.
     */
    public function directoryOf(string $name): ?string
    {
        $remainder = $this->ownerOf($name)['remainder'] ?? null;

        return $remainder === null ? null : explode('\\', $remainder)[0];
    }

    /**
     * The path findings show: relative to the base path when the file is below it.
     */
    public function displayPath(string $absolute): string
    {
        $base = rtrim($this->basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $base) ? str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($base))) : $absolute;
    }

    /**
     * @return list<SourceFile>
     */
    private function collect(): array
    {
        $finder = Finder::create()->files()->in($this->module->path)->name('*.php')->notName('*.blade.php')->exclude(['node_modules', 'vendor'])->sortByName();

        return array_map($this->sourceFile(...), iterator_to_array($finder, false));
    }

    private function sourceFile(SplFileInfo $entry): SourceFile
    {
        /** @var \Symfony\Component\Finder\SplFileInfo $entry */
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $entry->getRelativePathname());

        return new SourceFile($relative, $this->displayPath($entry->getPathname()), $entry->getPathname(), $this->parser);
    }
}
