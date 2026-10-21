<?php

namespace Studeo\Modular\Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * The syntax trees of the package's own source files, for tests that check how the code is built.
 */
final class PackageSyntaxTrees
{
    public const DIRECTORIES = ['Check', 'Console', 'DataObjects', 'Exceptions', 'Providers', 'Support'];

    public const IO_FUNCTIONS = [
        'file_get_contents', 'file_put_contents', 'file', 'fopen', 'fwrite', 'fread', 'readfile', 'is_file', 'is_dir', 'file_exists',
        'glob', 'scandir', 'mkdir', 'unlink', 'rename', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'getenv',
        'time', 'microtime', 'date', 'random_int', 'random_bytes', 'rand', 'mt_rand', 'uniqid',
    ];

    /**
     * @return array<string, list<Node>> package-relative path => syntax tree
     */
    public static function all(): array
    {
        $root = dirname(__DIR__, 2);
        $parser = (new ParserFactory)->createForHostVersion();
        $trees = [];

        foreach ((new Finder)->files()->in(array_map(fn (string $directory): string => "{$root}/{$directory}", self::DIRECTORIES))->name('*.php') as $file) {
            $trees[substr($file->getPathname(), strlen("{$root}/"))] = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
        }

        return $trees;
    }

    /**
     * @param  list<Node>  $tree
     * @return list<string>
     */
    public static function imports(array $tree): array
    {
        $uses = (new NodeFinder)->findInstanceOf($tree, Use_::class);

        return array_merge([], ...array_map(fn (Use_ $use): array => array_map(fn ($item): string => $item->name->toString(), $use->uses), $uses));
    }

    public static function isNamedConstructor(ClassMethod $method): bool
    {
        $returned = $method->returnType instanceof Node\NullableType ? $method->returnType->type : $method->returnType;
        $type = $returned instanceof Node\Identifier || $returned instanceof Node\Name ? (string) $returned : '';
        $returnsItself = in_array($type, ['self', 'static'], true) || str_contains((string) $method->getDocComment(), '@return list<self>');

        return $method->isStatic() && $method->isPublic() && $returnsItself;
    }

    /**
     * @param  list<Node>  $tree
     * @return list<ClassMethod> every static method that is not a named constructor, whatever its visibility
     */
    public static function staticHelperMethods(array $tree): array
    {
        $methods = (new NodeFinder)->findInstanceOf($tree, ClassMethod::class);

        return array_values(array_filter($methods, fn (ClassMethod $method): bool => $method->isStatic() && ! self::isNamedConstructor($method)));
    }

    /**
     * A class may offer static helpers when it is abstract (a base class), or final and either
     * stateless, immutable (readonly) or a node visitor with a static entry point.
     */
    public static function mayOfferStaticHelpers(Class_ $class): bool
    {
        $isVisitor = (string) $class->extends === 'NodeVisitorAbstract';
        $instanceMethods = array_filter($class->getMethods(), fn (ClassMethod $method): bool => ! $method->isStatic());
        $isStateless = $class->getProperties() === [] && $instanceMethods === [];

        return $class->isAbstract() || ($class->isFinal() && ($isVisitor || $isStateless || $class->isReadonly()));
    }
}
