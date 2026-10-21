<?php

namespace Studeo\Modular\Check\Rules;

use Studeo\Check\Engine\RuleOptions;
use Studeo\Check\Engine\Scope;
use Studeo\Check\Engine\SourceFile;
use Studeo\Check\Engine\Violation;
use Studeo\Modular\Check\ModuleSource;

/**
 * Another module may be used only through its published namespaces.
 */
final class ModuleIsolation extends ModuleFileRule
{
    public const NAME = 'module-isolation';

    /**
     * @param  list<string>  $scope
     * @param  list<string>  $allowed
     */
    private function __construct(array $scope, private readonly array $allowed)
    {
        parent::__construct($scope);
    }

    /**
     * @param  array<int, string>  $allowed  first namespace segments other modules may use
     * @param  array<int, string>  $scope  module-relative directories to check
     */
    public static function options(array $allowed = ['Contracts', 'Data', 'Events'], array $scope = Scope::ALL): self
    {
        return new self(RuleOptions::scope(self::NAME, $scope), RuleOptions::names(self::NAME, 'allowed', $allowed));
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function inspectInModule(SourceFile $file, ModuleSource $module): array
    {
        $references = $file->references();
        $perReference = array_map(fn (int $line, string $name): array => $this->violationsOfReference($file, $module, $name, $line), $references, array_keys($references));

        return array_merge([], ...$perReference);
    }

    /**
     * @return list<Violation> one violation, or none when the name is not an unpublished foreign class
     */
    private function violationsOfReference(SourceFile $file, ModuleSource $module, string $name, int $line): array
    {
        $owner = $module->ownerOf($name);

        if (! $this->isUnpublishedForeignOwner($owner, $module)) {
            return [];
        }

        return [$this->violation($file, $line, $name, $this->message($name, $owner['module']))];
    }

    /**
     * @param  array{module: string, remainder: string}|null  $owner
     *
     * @phpstan-assert-if-true array{module: string, remainder: string} $owner
     */
    private function isUnpublishedForeignOwner(?array $owner, ModuleSource $module): bool
    {
        return $owner !== null && $this->isUnpublishedForeign($owner, $module);
    }

    /**
     * @param  array{module: string, remainder: string}  $owner
     */
    private function isUnpublishedForeign(array $owner, ModuleSource $module): bool
    {
        return $owner['module'] !== $module->module->name && ! in_array(explode('\\', $owner['remainder'])[0], $this->allowed, true);
    }

    private function message(string $name, string $module): string
    {
        return "references {$name} from module {$module}. Cross-module use is limited to: ".implode(', ', $this->allowed).'. Use a Contract + DTO or a domain event.';
    }
}
