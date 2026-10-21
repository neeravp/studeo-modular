<?php

use Studeo\Modular\Tests\Support\InstallerCommandDriver;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->installer = new InstallerCommandDriver(app());
    fakeLivewireInstalled();
    fakeInertiaVueInstalled();
    $this->drive = $this->installer->run(...);
});

afterEach(fn () => $this->installer->tearDown());

it('uses the given layout without asking', function () {
    $result = ($this->drive)('module:livewire', ['name' => 'PostPage', '--module' => 'Blog', '--page' => true, '--layout' => 'admin'], ['ignored'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Livewire component [Pages/PostPage] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.pages.post-page, blog::pages.post-page',
            'Created files:',
            '  - <module>/resources/views/layouts/admin.blade.php',
            '  - <module>/Livewire/Pages/PostPage.php',
            '  - <module>/resources/views/livewire/pages/post-page.blade.php',
            '  - <module>/tests/Feature/Livewire/Pages/PostPageTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]);
});

it('falls back to the default layout without a terminal', function () {
    $result = ($this->drive)('module:livewire', ['name' => 'PostPage', '--module' => 'Blog', '--page' => true], [], false);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Livewire component [Pages/PostPage] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.pages.post-page, blog::pages.post-page',
            'Created files:',
            '  - <module>/resources/views/layouts/app.blade.php',
            '  - <module>/Livewire/Pages/PostPage.php',
            '  - <module>/resources/views/livewire/pages/post-page.blade.php',
            '  - <module>/tests/Feature/Livewire/Pages/PostPageTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]);
});

it('asks for the layout of a page and uses the answer', function () {
    $result = ($this->drive)('module:livewire', ['name' => 'PostPage', '--module' => 'Blog', '--page' => true], ['admin'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  Which layout should this page component use? [app]',
            '❯ ',
            'Livewire component [Pages/PostPage] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.pages.post-page, blog::pages.post-page',
            'Created files:',
            '  - <module>/resources/views/layouts/admin.blade.php',
            '  - <module>/Livewire/Pages/PostPage.php',
            '  - <module>/resources/views/livewire/pages/post-page.blade.php',
            '  - <module>/tests/Feature/Livewire/Pages/PostPageTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 6,
        'resolved' => [],
        'processes' => [],
    ]);
});

it('uses the default layout when the answer is empty', function () {
    $result = ($this->drive)('module:livewire', ['name' => 'PostPage', '--module' => 'Blog', '--page' => true], [''], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            '',
            '  Which layout should this page component use? [app]',
            '❯ ',
            'Livewire component [Pages/PostPage] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.pages.post-page, blog::pages.post-page',
            'Created files:',
            '  - <module>/resources/views/layouts/app.blade.php',
            '  - <module>/Livewire/Pages/PostPage.php',
            '  - <module>/resources/views/livewire/pages/post-page.blade.php',
            '  - <module>/tests/Feature/Livewire/Pages/PostPageTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 1,
        'resolved' => [],
        'processes' => [],
    ]);
});

it('asks nothing for a component that is not a page', function () {
    $result = ($this->drive)('module:livewire', ['name' => 'PostPage', '--module' => 'Blog'], ['admin'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Livewire component [PostPage] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-page, blog::post-page',
            'Created files:',
            '  - <module>/Livewire/PostPage.php',
            '  - <module>/resources/views/livewire/post-page.blade.php',
            '  - <module>/tests/Feature/Livewire/PostPageTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]);
});

it('ignores a layout given without --page', function () {
    $result = ($this->drive)('module:livewire', ['name' => 'PostPage', '--module' => 'Blog', '--layout' => 'admin'], ['ignored'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Livewire component [PostPage] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-page, blog::post-page',
            'Created files:',
            '  - <module>/Livewire/PostPage.php',
            '  - <module>/resources/views/livewire/post-page.blade.php',
            '  - <module>/tests/Feature/Livewire/PostPageTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => [],
        'processes' => [],
    ]);
});
