<?php

use Studeo\Check\Exceptions\InvalidRuleConfigurationException;
use Studeo\Modular\Check\Rules\ControllerShape;

function controllerFile(string $body, string $uses = ''): array
{
    return ['Controllers/PostController.php' => phpFile($body, 'Studeo\\Blog\\Controllers', "use Studeo\\Blog\\Actions\\CreatePostAction;\n{$uses}")];
}

it('flags a controller that extends a class', function (string $parent, string $uses) {
    $found = runRule(ControllerShape::options(), controllerFile("final class PostController extends {$parent}\n{\n}", $uses));

    expect($found)->toHaveCount(1)
        ->and($found[0]->rule)->toBe('controller-shape')
        ->and($found[0]->subject)->toBe('PostController')
        ->and($found[0]->message)->toContain('extends nothing');
})->with([
    'App base' => ['Controller', "use Studeo\\Blog\\Controllers\\Controller;\n"],
    'full name' => ['\\Illuminate\\Routing\\Controller', ''],
]);

it('flags an Action injected through the constructor', function (string $type) {
    $found = runRule(ControllerShape::options(), controllerFile("final class PostController\n{\n    public function __construct(private {$type} \$create) {}\n}"));

    expect($found)->toHaveCount(1)
        ->and($found[0]->subject)->toBe('PostController::$create')
        ->and($found[0]->message)->toContain('CreatePostAction')->toContain('method');
})->with([
    'imported' => ['CreatePostAction'],
    'nullable' => ['?CreatePostAction'],
    'union' => ['CreatePostAction|\\Studeo\\Blog\\Actions\\OtherAction'],
    'full name' => ['\\Studeo\\Blog\\Actions\\CreatePostAction'],
    'nested folder' => ['\\Studeo\\Blog\\Actions\\Posts\\CreatePostAction'],
]);

it('flags the extends and each Action parameter separately', function () {
    $body = "final class PostController extends \\Base\\Controller\n{\n    public function __construct(CreatePostAction \$a, CreatePostAction \$b) {}\n}";

    expect(array_column(runRule(ControllerShape::options(), controllerFile($body)), 'subject'))->toBe(['PostController', 'PostController::$a', 'PostController::$b']);
});

it('passes method injection and a constructor taking a non-Action dependency', function (string $body) {
    expect(runRule(ControllerShape::options(), controllerFile($body, "use Illuminate\\Http\\Request;\nuse Studeo\\Blog\\Services\\Clock;\nuse Other\\Actions\\CreatePostAction as Foreign;\n")))->toBe([]);
})->with([
    'method injection' => ["final class PostController\n{\n    public function __invoke(Request \$request, CreatePostAction \$action) {}\n}"],
    'service in the constructor' => ["final class PostController\n{\n    public function __construct(private Clock \$clock) {}\n}"],
    'another namespace named Actions' => ["final class PostController\n{\n    public function __construct(private Foreign \$action) {}\n}"],
    'no constructor' => ['final class PostController {}'],
]);

it('only looks at Controllers by default and takes a scope', function () {
    $body = 'final class PostController extends \\Base\\Controller {}';
    $elsewhere = ['Services/PostController.php' => phpFile($body, 'Studeo\\Blog\\Services')];

    expect(runRule(ControllerShape::options(), $elsewhere))->toBe([])
        ->and(runRule(ControllerShape::options(scope: ['Services']), $elsewhere))->toHaveCount(1);
});

it('rejects an empty scope naming the rule and the option', function () {
    expect(fn () => ControllerShape::options(scope: []))->toThrow(InvalidRuleConfigurationException::class, '[controller-shape] option [scope]');
});
