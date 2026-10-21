<?php

namespace Studeo\Modular\Check\Rules;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use Studeo\Check\Engine\RuleOptions;
use Studeo\Check\Engine\SourceFile;
use Studeo\Check\Engine\Violation;
use Studeo\Check\Rules\Helpers\ClassMembers;
use Studeo\Check\Rules\Helpers\TypedMember;
use Studeo\Modular\Check\ModuleSource;

/**
 * Controllers extend nothing and receive Actions as parameters of the method that uses them,
 * never through the constructor.
 */
final class ControllerShape extends ModuleFileRule
{
    public const NAME = 'controller-shape';

    /**
     * @param  array<int, string>  $scope  module-relative directories to check
     */
    public static function options(array $scope = ['Controllers']): self
    {
        return new self(RuleOptions::scope(self::NAME, $scope));
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function inspectInModule(SourceFile $file, ModuleSource $module): array
    {
        $perClass = array_map(fn (Class_ $class): array => [...$this->parentFindings($file, $class), ...$this->injectionFindings($file, $class, $module)], $file->concreteClasses());

        return array_merge([], ...$perClass);
    }

    /**
     * @return list<Violation>
     */
    private function parentFindings(SourceFile $file, Class_ $class): array
    {
        $parent = $class->extends?->toString();
        $message = "extends {$parent}; a controller extends nothing. Remove the base class: the Action authorizes, and the actor is \$request->user() ?? abort(401).";

        /** @var Identifier $name */
        $name = $class->name;

        return $parent === null ? [] : [$this->violation($file, $class->getStartLine(), $name->toString(), $message)];
    }

    /**
     * @return list<Violation>
     */
    private function injectionFindings(SourceFile $file, Class_ $class, ModuleSource $module): array
    {
        $injected = array_filter(ClassMembers::constructorParameters($class), fn (TypedMember $member): bool => $this->actionsIn($member, $module) !== []);

        return array_values(array_map(fn (TypedMember $member): Violation => $this->violation($file, $member->line, $member->subject, $this->injectionMessage($member, $module)), $injected));
    }

    private function injectionMessage(TypedMember $member, ModuleSource $module): string
    {
        $actions = implode(', ', $this->actionsIn($member, $module));

        return "constructor parameter \${$member->variable} is typed {$actions}. Controllers receive Actions as parameters of the method that uses them, for example __invoke(Request \$request, {$this->shortName($actions)} \$action).";
    }

    /**
     * @return list<string> the module's own Action classes the member's type mentions
     */
    private function actionsIn(TypedMember $member, ModuleSource $module): array
    {
        return array_values(array_filter($member->types, fn (string $name): bool => $this->isOwnAction($name, $module)));
    }

    private function isOwnAction(string $name, ModuleSource $module): bool
    {
        return $module->moduleOf($name) === $module->module->name && $module->directoryOf($name) === 'Actions';
    }

    private function shortName(string $names): string
    {
        $first = explode(', ', $names)[0];

        return substr($first, (int) strrpos($first, '\\') + 1);
    }
}
