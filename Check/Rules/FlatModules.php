<?php

namespace Studeo\Modular\Check\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use SplFileInfo;
use Studeo\Check\Engine\RuleOptions;
use Studeo\Check\Engine\Scope;
use Studeo\Check\Engine\Severity;
use Studeo\Check\Engine\SourceFile;
use Studeo\Check\Engine\Violation;
use Studeo\Modular\Check\ModuleLayout;
use Studeo\Modular\Check\ModuleSource;
use Symfony\Component\Finder\Finder;

/**
 * The module root holds a manifest, the module provider and the allowed directories only;
 * every class in a class directory has the namespace and name its path implies; no module
 * sits inside another.
 */
final class FlatModules extends BaseModuleRule
{
    public const NAME = 'flat-modules';

    private const SKIPPED = ['node_modules', 'vendor'];

    /**
     * @param  list<string>  $classDirectories
     * @param  list<string>  $dataDirectories
     * @param  list<string>  $scope
     */
    private function __construct(
        private readonly array $classDirectories,
        private readonly array $dataDirectories,
        private readonly array $scope,
    ) {}

    /**
     * @param  array<int, string>  $classDirectories  PascalCase root directories that hold classes
     * @param  array<int, string>  $dataDirectories  lowercase root directories that hold other files
     * @param  array<int, string>  $scope  module-relative directories to report on
     */
    public static function options(array $classDirectories = ModuleLayout::CLASS_DIRECTORIES, array $dataDirectories = ModuleLayout::DATA_DIRECTORIES, array $scope = Scope::ALL): self
    {
        return new self(
            RuleOptions::directories(self::NAME, 'classDirectories', $classDirectories),
            RuleOptions::directories(self::NAME, 'dataDirectories', $dataDirectories),
            RuleOptions::scope(self::NAME, $scope),
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function moduleViolations(ModuleSource $module): array
    {
        return [
            ...$this->missingManifest($module),
            ...$this->strayRootFiles($module),
            ...$this->unknownRootDirectories($module),
            ...$this->nestedModules($module),
            ...$this->misnamedClasses($module),
        ];
    }

    /**
     * @return list<Violation>
     */
    private function missingManifest(ModuleSource $module): array
    {
        $missing = array_filter(['module.json'], fn (string $file): bool => $this->isMissingInScope($module, $file));

        return array_map(fn (string $file) => $this->violation($module, $file, $file, 'the module has no module.json. The manifest names the module and its namespace; module:make writes it.'), $missing);
    }

    private function isMissingInScope(ModuleSource $module, string $file): bool
    {
        return ! is_file($module->module->path.'/'.$file) && $this->inScope($file);
    }

    /**
     * @return list<Violation>
     */
    private function strayRootFiles(ModuleSource $module): array
    {
        $provider = $module->module->name.'ServiceProvider.php';
        $stray = array_filter($this->entries($module, Finder::create()->files()->name('*.php')->depth(0)), fn (string $file): bool => $this->isStrayRootFile($file, $provider));

        return array_values(array_map(fn (string $file) => $this->violation($module, $file, $file, "{$file} is a PHP file in the module root. Only {$provider} belongs there; move the class into a class directory such as Services/."), $stray));
    }

    private function isStrayRootFile(string $file, string $provider): bool
    {
        return $file !== $provider && $this->inScope($file);
    }

    /**
     * @return list<Violation>
     */
    private function unknownRootDirectories(ModuleSource $module): array
    {
        $unknown = array_filter($this->entries($module, Finder::create()->directories()->depth(0)), fn (string $directory): bool => $this->isUnknownInScope($directory));

        return array_values(array_map(fn (string $directory) => $this->violation($module, $directory, $directory, $this->unknownMessage($directory)), $unknown));
    }

    private function isUnknownInScope(string $directory): bool
    {
        return $this->isUnknown($directory) && $this->inScope($directory);
    }

    /**
     * @return list<Violation>
     */
    private function nestedModules(ModuleSource $module): array
    {
        $found = [
            ...$this->entries($module, Finder::create()->files()->name('module.json')->depth('> 0')),
            ...$this->entries($module, Finder::create()->directories()->name('modules')),
        ];

        return array_values(array_map(fn (string $path) => $this->violation($module, $path, $path, "{$path} makes this a nested module. A module root holds no module.json or modules/ below it; move the inner module to its own directory under the modules path."), array_filter($found, $this->inScope(...))));
    }

    /**
     * @return list<Violation>
     */
    private function misnamedClasses(ModuleSource $module): array
    {
        $files = array_filter($module->files(new Scope($this->scope)), $this->isChecked(...));
        $perFile = array_map(fn (SourceFile $file): array => $this->mismatches($file, $module), $files);

        return array_merge([], ...$perFile);
    }

    /**
     * @return list<Violation>
     */
    private function mismatches(SourceFile $file, ModuleSource $module): array
    {
        /** @var list<ClassLike> $declared */
        $declared = (new NodeFinder)->find($file->ast(), $this->isNamedClassLike(...));
        $found = array_map(fn (ClassLike $class): ?Violation => $this->mismatch($class, $file, $module), $declared);

        return array_values(array_filter($found));
    }

    private function isNamedClassLike(Node $node): bool
    {
        return $node instanceof ClassLike && $node->name !== null;
    }

    private function mismatch(ClassLike $class, SourceFile $file, ModuleSource $module): ?Violation
    {
        $expected = implode('\\', [$module->module->namespace, ...explode('/', dirname($file->relativePath))]);
        $parts = $class->namespacedName->getParts();
        $namespace = implode('\\', array_slice($parts, 0, -1));
        $wanted = basename($file->relativePath, '.php');

        return match (true) {
            end($parts) !== $wanted => $this->classViolation($file, $class, end($parts), $wanted),
            $namespace !== $expected => $this->namespaceViolation($file, $class, $namespace, $expected),
            default => null,
        };
    }

    private function classViolation(SourceFile $file, ClassLike $class, string $name, string $wanted): Violation
    {
        $message = "class {$name} is in {$wanted}.php. The class name must equal the file name (PSR-4); rename the class or the file.";

        return new Violation(self::NAME, $file->displayPath, $class->getStartLine(), $class->namespacedName->toString(), Severity::Error, $message);
    }

    private function namespaceViolation(SourceFile $file, ClassLike $class, string $namespace, string $expected): Violation
    {
        $message = "namespace {$namespace} does not match the path {$file->relativePath}, which implies {$expected}. Change the namespace or move the file.";

        return new Violation(self::NAME, $file->displayPath, $class->getStartLine(), $class->namespacedName->toString(), Severity::Error, $message);
    }

    private function unknownMessage(string $directory): string
    {
        $classes = implode(' ', $this->classDirectories);
        $data = implode(' ', $this->dataDirectories);

        return "root directory {$directory}/ is not part of the module layout. Class directories: {$classes}. Other directories: {$data}. Classes sit in a class directory directly under the root (Http/Controllers becomes Controllers/).";
    }

    private function isUnknown(string $directory): bool
    {
        return ! in_array($directory, [...$this->classDirectories, ...$this->dataDirectories, 'modules'], true);
    }

    private function isChecked(SourceFile $file): bool
    {
        return in_array(explode('/', $file->relativePath)[0], $this->classDirectories, true) && ! str_contains($file->relativePath, '⚡');
    }

    private function inScope(string $relative): bool
    {
        return (new Scope($this->scope))->matches($relative.'/');
    }

    /**
     * Module-relative paths of what the finder matches below the module root.
     *
     * @return list<string>
     */
    private function entries(ModuleSource $module, Finder $finder): array
    {
        $found = $finder->in($module->module->path)->exclude(self::SKIPPED)->sortByName();

        return array_map(fn (SplFileInfo $entry): string => str_replace(DIRECTORY_SEPARATOR, '/', $entry->getRelativePathname()), iterator_to_array($found, false));
    }

    private function violation(ModuleSource $module, string $relative, string $subject, string $message): Violation
    {
        return new Violation(self::NAME, $module->displayPath($module->module->path.'/'.$relative), 1, $subject, Severity::Error, $message);
    }
}
