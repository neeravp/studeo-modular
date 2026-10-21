<?php

use Studeo\Modular\Exceptions\ModuleLoadException;

it('suggests the autoloader when the declared namespace is unknown', function (): void {
    $exception = ModuleLoadException::providerClassMissing('Blog', 'Studeo\Blog\BlogServiceProvider', 'Studeo\Blog', null);

    expect($exception->getMessage())->toBe(
        'Module [Blog] failed to load: provider class [Studeo\Blog\BlogServiceProvider] not found. '
        ."The class may be missing from the autoloader: run [composer dump-autoload]. If that does not help, the provider's namespace must equal the manifest's namespace [Studeo\Blog]."
    );
});

it('suggests the autoloader when the declared namespace equals the manifest namespace', function (): void {
    $exception = ModuleLoadException::providerClassMissing('Blog', 'Studeo\Blog\BlogServiceProvider', 'Studeo\Blog', 'Studeo\Blog');

    expect($exception->getMessage())->toBe(
        'Module [Blog] failed to load: provider class [Studeo\Blog\BlogServiceProvider] not found. '
        ."The class may be missing from the autoloader: run [composer dump-autoload]. If that does not help, the provider's namespace must equal the manifest's namespace [Studeo\Blog]."
    );
});

it('names both namespaces when the declared namespace differs from the manifest namespace', function (): void {
    $exception = ModuleLoadException::providerClassMissing('Blog', 'Studeo\Blog\BlogServiceProvider', 'Studeo\Blog', 'Acme\Blog');

    expect($exception->getMessage())->toBe(
        'Module [Blog] failed to load: provider class [Studeo\Blog\BlogServiceProvider] not found. '
        ."The provider file declares namespace [Acme\Blog], but its namespace must equal the manifest's namespace [Studeo\Blog] in module.json. Change one to match the other, then run [composer dump-autoload]."
    );
});

it('treats an empty declared namespace as different', function (): void {
    $exception = ModuleLoadException::providerClassMissing('Blog', 'Studeo\Blog\BlogServiceProvider', 'Studeo\Blog', '');

    expect($exception->getMessage())->toBe(
        'Module [Blog] failed to load: provider class [Studeo\Blog\BlogServiceProvider] not found. '
        ."The provider file declares namespace [], but its namespace must equal the manifest's namespace [Studeo\Blog] in module.json. Change one to match the other, then run [composer dump-autoload]."
    );
});

it('compares the namespaces strictly and case-sensitively', function (): void {
    $exception = ModuleLoadException::providerClassMissing('Blog', 'Studeo\Blog\BlogServiceProvider', 'Studeo\Blog', 'studeo\blog');

    expect($exception->getMessage())->toBe(
        'Module [Blog] failed to load: provider class [Studeo\Blog\BlogServiceProvider] not found. '
        ."The provider file declares namespace [studeo\blog], but its namespace must equal the manifest's namespace [Studeo\Blog] in module.json. Change one to match the other, then run [composer dump-autoload]."
    );
});

it('treats a manifest namespace with no prefix like any other', function (): void {
    $equal = ModuleLoadException::providerClassMissing('Blog', 'Blog\BlogServiceProvider', 'Blog', 'Blog');
    $different = ModuleLoadException::providerClassMissing('Blog', 'Blog\BlogServiceProvider', 'Blog', 'Acme\Blog');

    expect($equal->getMessage())->toBe(
        'Module [Blog] failed to load: provider class [Blog\BlogServiceProvider] not found. '
        ."The class may be missing from the autoloader: run [composer dump-autoload]. If that does not help, the provider's namespace must equal the manifest's namespace [Blog]."
    )->and($different->getMessage())->toBe(
        'Module [Blog] failed to load: provider class [Blog\BlogServiceProvider] not found. '
        ."The provider file declares namespace [Acme\Blog], but its namespace must equal the manifest's namespace [Blog] in module.json. Change one to match the other, then run [composer dump-autoload]."
    );
});

it('keeps the module, no previous exception and code zero', function (?string $declared): void {
    $exception = ModuleLoadException::providerClassMissing('BlogPosts', 'Studeo\BlogPosts\BlogPostsServiceProvider', 'Studeo\BlogPosts', $declared);

    expect($exception->module)->toBe('BlogPosts')
        ->and($exception->getPrevious())->toBeNull()
        ->and($exception->getCode())->toBe(0);
})->with([
    'unknown' => [null],
    'equal' => ['Studeo\BlogPosts'],
    'different' => ['Acme\BlogPosts'],
]);

it('puts the module name in the message prefix', function (): void {
    $exception = ModuleLoadException::providerClassMissing('Account', 'Acme\Account\AccountServiceProvider', 'Acme\Account', 'Acme\Account');

    expect($exception->getMessage())->toStartWith('Module [Account] failed to load: provider class [Acme\Account\AccountServiceProvider] not found. ')
        ->and($exception->module)->toBe('Account');
});
