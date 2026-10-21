<?php

use Studeo\Modular\Console\Actions\CheckLivewireAction;
use Studeo\Modular\Console\Actions\InstallLivewireAction;
use Studeo\Modular\Tests\Support\InstallerCommandDriver;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->installer = new InstallerCommandDriver(app(), [CheckLivewireAction::class, InstallLivewireAction::class]);
    $this->bindLivewire = fn (bool $installed) => fakeLivewireInstalled($installed);
    $this->drive = $this->installer->run(...);
});

afterEach(fn () => $this->installer->tearDown(cleanInstallerRoots: false));

it('asks nothing and creates the component when Livewire is installed', function () {
    ($this->bindLivewire)(true);
    $result = ($this->drive)('module:livewire', ['name' => 'PostList', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Livewire component [PostList] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-list, blog::post-list',
            'Created files:',
            '  - <module>/Livewire/PostList.php',
            '  - <module>/resources/views/livewire/post-list.blade.php',
            '  - <module>/tests/Feature/Livewire/PostListTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => ['resolve:CheckLivewireAction'],
        'processes' => [],
    ]);
});

it('prints the install command and fails without a terminal', function () {
    ($this->bindLivewire)(false);
    $result = ($this->drive)('module:livewire', ['name' => 'PostList', '--module' => 'Blog'], [], false);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Livewire 4 is required to generate Livewire components. To install it:',
            '  composer require livewire/livewire:^4.0',
            'Livewire is not installed. Nothing was changed. Install it with:',
            '  composer require livewire/livewire:^4.0',
        ])."\n",
        'stderr' => '',
        'answered' => 0,
        'resolved' => ['resolve:CheckLivewireAction'],
        'processes' => [],
    ]);
});

it('prints the install command and fails after a no', function () {
    ($this->bindLivewire)(false);
    $result = ($this->drive)('module:livewire', ['name' => 'PostList', '--module' => 'Blog'], ['no'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Livewire 4 is required to generate Livewire components. To install it:',
            '  composer require livewire/livewire:^4.0',
            '',
            '  Livewire 4 is not installed in this project. Install it with composer require livewire/livewire:^4.0? (yes/no) [yes]',
            '❯ ',
            'Livewire is not installed. Nothing was changed. Install it with:',
            '  composer require livewire/livewire:^4.0',
        ])."\n",
        'stderr' => '',
        'answered' => 3,
        'resolved' => ['resolve:CheckLivewireAction'],
        'processes' => [],
    ]);
});

it('installs Livewire after a yes, then creates the component', function () {
    ($this->bindLivewire)(false);
    $result = ($this->drive)('module:livewire', ['name' => 'PostList', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 0,
        'stdout' => implode("\n", [
            'Livewire 4 is required to generate Livewire components. To install it:',
            '  composer require livewire/livewire:^4.0',
            '',
            '  Livewire 4 is not installed in this project. Install it with composer require livewire/livewire:^4.0? (yes/no) [yes]',
            '❯ ',
            '   INFO  Installing Livewire 4 with Composer...  ',
            '',
            'Livewire component [PostList] created successfully in module [Blog].',
            'Type: classic',
            'Aliases: blog.post-list, blog::post-list',
            'Created files:',
            '  - <module>/Livewire/PostList.php',
            '  - <module>/resources/views/livewire/post-list.blade.php',
            '  - <module>/tests/Feature/Livewire/PostListTest.php',
        ])."\n",
        'stderr' => '',
        'answered' => 4,
        'resolved' => ['resolve:CheckLivewireAction', 'resolve:InstallLivewireAction'],
        'processes' => [[['composer', 'require', 'livewire/livewire:^4.0'], '<base>']],
    ]);
});

it('reports a failing install and creates nothing', function () {
    ($this->bindLivewire)(false);
    $this->installer->fails = true;
    $result = ($this->drive)('module:livewire', ['name' => 'PostList', '--module' => 'Blog'], ['yes'], true);

    expect($result)->toBe([
        'status' => 1,
        'stdout' => implode("\n", [
            'Livewire 4 is required to generate Livewire components. To install it:',
            '  composer require livewire/livewire:^4.0',
            '',
            '  Livewire 4 is not installed in this project. Install it with composer require livewire/livewire:^4.0? (yes/no) [yes]',
            '❯ ',
            '   INFO  Installing Livewire 4 with Composer...  ',
            '',
            'Failed to install Livewire: failed',
        ])."\n",
        'stderr' => '',
        'answered' => 4,
        'resolved' => ['resolve:CheckLivewireAction', 'resolve:InstallLivewireAction'],
        'processes' => [[['composer', 'require', 'livewire/livewire:^4.0'], '<base>']],
    ]);
});
