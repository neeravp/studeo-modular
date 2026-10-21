<?php

use Studeo\Modular\Tests\Support\ComponentCommandFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = ComponentCommandFixture::livewire(app(), 3);
    $this->root = $this->fixture->root;
    $this->driver = $this->fixture->driver;
    $this->outcome = $this->fixture->outcome(...);
    $this->plain = $this->fixture->plain;
    $this->picked = $this->fixture->picked;
});

afterEach(fn () => $this->fixture->tearDown());

it('moves an explicitly named component with --force', function () {
    expect($this->driver->run('module:livewire:move', ['name' => 'PostList', 'new-name' => 'AllPosts', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Livewire component [PostList] moved to [AllPosts] in module [Blog].\nMoved files:\n  - <modules>/Blog/Livewire/PostList.php => <modules>/Blog/Livewire/AllPosts.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php => <modules>/Blog/resources/views/livewire/all-posts.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php => <modules>/Blog/tests/Feature/Livewire/AllPostsTest.php\n", $this->plain));
});

it('finds an explicit name through its kebab alias', function () {
    expect($this->driver->run('module:livewire:move', ['name' => 'user-badge', 'new-name' => 'Badge', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Livewire component [UserBadge] moved to [Badge] in module [Blog].\nMoved files:\n  - <modules>/Blog/Livewire/UserBadge.php => <modules>/Blog/Livewire/Badge.php\n  - <modules>/Blog/resources/views/livewire/user-badge.blade.php => <modules>/Blog/resources/views/livewire/badge.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/UserBadgeTest.php => <modules>/Blog/tests/Feature/Livewire/BadgeTest.php\n", $this->plain));
});

it('fails naming the component and the module when the name is unknown', function () {
    expect($this->driver->run('module:livewire:move', ['name' => 'Ghost', 'new-name' => 'X', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Livewire component [Ghost] does not exist in module [Blog].\n", $this->plain));
});

it('takes an empty name as an explicit name that does not exist', function () {
    expect($this->driver->run('module:livewire:move', ['name' => '', 'new-name' => 'X', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Livewire component [] does not exist in module [Blog].\n", $this->plain));
});

it('offers every component with its type in scan order and moves the chosen one', function () {
    expect($this->driver->run('module:livewire:move', ['new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], ['UserBadge']))
        ->toBe(($this->outcome)(0, "\n  Which Livewire component would you like to move from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \nLivewire component [UserBadge] moved to [Renamed] in module [Blog].\nMoved files:\n  - <modules>/Blog/Livewire/UserBadge.php => <modules>/Blog/Livewire/Renamed.php\n  - <modules>/Blog/resources/views/livewire/user-badge.blade.php => <modules>/Blog/resources/views/livewire/renamed.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/UserBadgeTest.php => <modules>/Blog/tests/Feature/Livewire/RenamedTest.php\n", $this->plain, 10));
});

it('offers a single-file component under its derived name', function () {
    expect($this->driver->run('module:livewire:move', ['new-name' => 'Posts/Show', '--module' => 'Blog', '--force' => true], ['Posts/create']))
        ->toBe(($this->outcome)(0, "\n  Which Livewire component would you like to move from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \nLivewire component [Posts/create] moved to [Posts/show] in module [Blog].\nMoved files:\n  - <modules>/Blog/Livewire/Posts/⚡create.blade.php => <modules>/Blog/Livewire/Posts/⚡show.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/Posts/CreateTest.php => <modules>/Blog/tests/Feature/Livewire/Posts/ShowTest.php\n", $this->plain, 13));
});

it('asks for the new name after the component', function () {
    expect($this->driver->run('module:livewire:move', ['--module' => 'Blog', '--force' => true], ['PostList', 'AllPosts']))
        ->toBe(($this->outcome)(0, "\n  Which Livewire component would you like to move from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \n  What should the new component name be? [PostList]\n❯ \nLivewire component [PostList] moved to [AllPosts] in module [Blog].\nMoved files:\n  - <modules>/Blog/Livewire/PostList.php => <modules>/Blog/Livewire/AllPosts.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php => <modules>/Blog/resources/views/livewire/all-posts.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php => <modules>/Blog/tests/Feature/Livewire/AllPostsTest.php\n", $this->plain, 18));
});

it('warns on standard output and goes no further when the module has no components', function () {
    expect($this->driver->run('module:livewire:move', ['new-name' => 'X', '--module' => 'Empty']))
        ->toBe(($this->outcome)(0, "No Livewire components found in module [Empty].\n", $this->plain));
});

it('warns the same way when it cannot prompt', function () {
    expect($this->driver->run('module:livewire:move', ['new-name' => 'X', '--module' => 'Empty'], [], false))
        ->toBe(($this->outcome)(0, "No Livewire components found in module [Empty].\n", $this->plain));
});

it('names the missing component name when it cannot prompt and components exist', function () {
    expect($this->driver->run('module:livewire:move', ['new-name' => 'X', '--module' => 'Blog'], [], false))
        ->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:livewire:move Counter Renamed --module=Blog --force.\n", $this->plain));
});

it('asks for the module and then for the component', function () {
    expect($this->driver->run('module:livewire:move', ['new-name' => 'AllPosts', '--force' => true], ['Blog', 'PostList']))
        ->toBe(($this->outcome)(0, "\n  Which module should this belong to?\n  Blog ... 0  \n  Empty ... 1  \n  Shop ... 2  \n❯ \n  Which Livewire component would you like to move from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \nLivewire component [PostList] moved to [AllPosts] in module [Blog].\nMoved files:\n  - <modules>/Blog/Livewire/PostList.php => <modules>/Blog/Livewire/AllPosts.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php => <modules>/Blog/resources/views/livewire/all-posts.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php => <modules>/Blog/tests/Feature/Livewire/AllPostsTest.php\n", $this->picked, 14));
});

it('asks for the component, then for the target module, with --module given', function () {
    expect($this->driver->run('module:livewire:move', ['new-name' => 'Panel2', '--module' => 'Blog'], ['PostList', 'no', 'Shop', 'yes']))
        ->toBe(($this->outcome)(0, "\n  Which Livewire component would you like to move from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \n  Keep component in module [Blog]? (yes/no) [yes]\n❯ \n  Which module should the component be moved to?\n  Empty ... 0  \n  Shop ... 1  \n❯ \n  Are you sure you want to move [PostList] in [Blog] to [Panel2] in module [Shop]? (yes/no) [yes]\n❯ \nLivewire component [PostList] moved to [Panel2] in module [Shop].\nMoved files:\n  - <modules>/Blog/Livewire/PostList.php => <modules>/Shop/Livewire/Panel2.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php => <modules>/Shop/resources/views/livewire/panel2.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php => <modules>/Shop/tests/Feature/Livewire/Panel2Test.php\n", ['resolve:ResolveModuleAction', 'resolve:DiscoverLivewireComponentsAction', 'resolve:ResolveModuleAction', 'resolve:DiscoverLivewireComponentsAction', 'resolve:ResolveModuleAction', 'resolve:ResolveModuleAction', 'resolve:ResolveModuleAction', 'resolve:ResolveModuleAction', 'resolve:ResolveModuleAction', 'resolve:ResolveModuleAction', 'resolve:DiscoverModulesAction', 'resolve:ResolveModuleAction'], 21));
});
