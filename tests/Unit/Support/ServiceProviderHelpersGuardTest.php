<?php

use Illuminate\Auth\Access\Gate;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * ModuleRegistrar calls Laravel's protected ServiceProvider helpers through
 * Closure::call so it keeps Laravel's own semantics. These tests fail with a
 * clear message if Laravel changes or removes any of them.
 */
it('guards the ServiceProvider helpers the registrar calls', function (string $method, int $requiredParameters, bool $public) {
    expect(method_exists(ServiceProvider::class, $method))
        ->toBeTrue("ServiceProvider::{$method}() no longer exists; update ModuleRegistrar.");

    $reflection = new ReflectionMethod(ServiceProvider::class, $method);

    expect($reflection->isStatic())->toBeFalse("ServiceProvider::{$method}() became static; update ModuleRegistrar.")
        ->and($reflection->getNumberOfRequiredParameters())->toBe($requiredParameters, "ServiceProvider::{$method}() changed its required parameters; update ModuleRegistrar.")
        ->and($public ? $reflection->isPublic() : $reflection->isProtected())->toBeTrue("ServiceProvider::{$method}() changed visibility; update ModuleRegistrar.");
})->with([
    'mergeConfigFrom' => ['mergeConfigFrom', 2, false],
    'loadRoutesFrom' => ['loadRoutesFrom', 1, false],
    'loadViewsFrom' => ['loadViewsFrom', 2, false],
    'loadMigrationsFrom' => ['loadMigrationsFrom', 1, false],
    'loadTranslationsFrom' => ['loadTranslationsFrom', 1, false],
    'loadJsonTranslationsFrom' => ['loadJsonTranslationsFrom', 1, false],
    'commands' => ['commands', 1, true],
]);

it('guards the protected app property the registrar reads from the provider scope', function () {
    $property = new ReflectionProperty(ServiceProvider::class, 'app');

    expect($property->isProtected())->toBeTrue('ServiceProvider::$app changed visibility; update ModuleRegistrar::app().');
});

it('guards the application and routing API the registrar relies on', function () {
    expect(method_exists(Application::class, 'booted'))->toBeTrue('Application::booted() is gone; routes can no longer be deferred.')
        ->and((new ReflectionMethod(Router::class, 'aliasMiddleware'))->isPublic())->toBeTrue()
        ->and((new ReflectionMethod(Router::class, 'getMiddleware'))->isPublic())->toBeTrue()
        ->and((new ReflectionMethod(Gate::class, 'policy'))->isPublic())->toBeTrue();
});
