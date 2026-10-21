<?php

use Studeo\Modular\Tests\Support\ComponentCommandFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = ComponentCommandFixture::livewire(app());
    $this->root = $this->fixture->root;
    $this->driver = $this->fixture->driver;
    $this->outcome = $this->fixture->outcome(...);
    $this->plain = $this->fixture->plain;
    $this->picked = $this->fixture->picked;
});

afterEach(fn () => $this->fixture->tearDown());

it('deletes an explicitly named component with --force', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => 'PostList', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Livewire component [PostList] deleted successfully from module [Blog].\nDeleted files:\n  - <modules>/Blog/Livewire/PostList.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php\n", $this->plain));
});

it('finds an explicit name through its kebab alias', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => 'user-badge', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Livewire component [UserBadge] deleted successfully from module [Blog].\nDeleted files:\n  - <modules>/Blog/Livewire/UserBadge.php\n  - <modules>/Blog/resources/views/livewire/user-badge.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/UserBadgeTest.php\n", $this->plain));
});

it('fails naming the component and the module when the name is unknown', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => 'Ghost', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Livewire component [Ghost] does not exist in module [Blog].\n", $this->plain));
});

it('takes an empty name as an explicit name that does not exist', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => '', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Livewire component [] does not exist in module [Blog].\n", $this->plain));
});

it('takes 0 as an explicit name that does not exist', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => '0', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Livewire component [0] does not exist in module [Blog].\n", $this->plain));
});

it('offers every component with its type in scan order and deletes the chosen one', function () {
    expect($this->driver->run('module:livewire:delete', ['--module' => 'Blog', '--force' => true], ['UserBadge']))
        ->toBe(($this->outcome)(0, "\n  Which Livewire component would you like to delete from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \nLivewire component [UserBadge] deleted successfully from module [Blog].\nDeleted files:\n  - <modules>/Blog/Livewire/UserBadge.php\n  - <modules>/Blog/resources/views/livewire/user-badge.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/UserBadgeTest.php\n", $this->plain, 10));
});

it('offers a single-file component under its derived name', function () {
    expect($this->driver->run('module:livewire:delete', ['--module' => 'Blog', '--force' => true], ['Posts/create']))
        ->toBe(($this->outcome)(0, "\n  Which Livewire component would you like to delete from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \nLivewire component [Posts/create] deleted successfully from module [Blog].\nDeleted files:\n  - <modules>/Blog/Livewire/Posts/⚡create.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/Posts/CreateTest.php\n", $this->plain, 13));
});

it('warns on standard output and asks for nothing when the module has no components', function () {
    expect($this->driver->run('module:livewire:delete', ['--module' => 'Empty']))
        ->toBe(($this->outcome)(0, "No Livewire components found in module [Empty].\n", $this->plain));
});

it('warns the same way when it cannot prompt', function () {
    expect($this->driver->run('module:livewire:delete', ['--module' => 'Empty'], [], false))
        ->toBe(($this->outcome)(0, "No Livewire components found in module [Empty].\n", $this->plain));
});

it('names the missing component name when it cannot prompt and components exist', function () {
    expect($this->driver->run('module:livewire:delete', ['--module' => 'Blog'], [], false))
        ->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:livewire:delete Counter --module=Blog --force.\n", $this->plain));
});

it('asks for the module and then for the component', function () {
    expect($this->driver->run('module:livewire:delete', [], ['Blog', 'UserBadge', 'no']))
        ->toBe(($this->outcome)(0, "\n  Which module should this belong to?\n  Blog ... 0  \n  Empty ... 1  \n  Shop ... 2  \n❯ \n  Which Livewire component would you like to delete from [Blog]?\n  PostList (classic) ... PostList  \n  Posts/create (sfc) ... Posts/create  \n  UserBadge (classic) ... UserBadge  \n  article (sfc) ... article  \n  panel (mfc) ... panel  \n❯ \n\e[33m This will permanently delete Livewire component [UserBadge] and all associated files.\e[39m\n\nFiles to be deleted:\n  - <modules>/Blog/Livewire/UserBadge.php\n  - <modules>/Blog/resources/views/livewire/user-badge.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/UserBadgeTest.php\n\n  Are you sure you want to delete component [UserBadge]? (yes/no) [no]\n❯ \nComponent [UserBadge] was not deleted.\n", $this->picked, 18));
});

it('lists the files of a component and cancels on no', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => 'PostList', '--module' => 'Blog'], ['no']))
        ->toBe(($this->outcome)(0, "\n\e[33m This will permanently delete Livewire component [PostList] and all associated files.\e[39m\n\nFiles to be deleted:\n  - <modules>/Blog/Livewire/PostList.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php\n\n  Are you sure you want to delete component [PostList]? (yes/no) [no]\n❯ \nComponent [PostList] was not deleted.\n", $this->plain, 3));
});

it('lists the files of a component and deletes on yes', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => 'PostList', '--module' => 'Blog'], ['yes']))
        ->toBe(($this->outcome)(0, "\n\e[33m This will permanently delete Livewire component [PostList] and all associated files.\e[39m\n\nFiles to be deleted:\n  - <modules>/Blog/Livewire/PostList.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php\n\n  Are you sure you want to delete component [PostList]? (yes/no) [no]\n❯ \nLivewire component [PostList] deleted successfully from module [Blog].\nDeleted files:\n  - <modules>/Blog/Livewire/PostList.php\n  - <modules>/Blog/resources/views/livewire/post-list.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php\n", $this->plain, 4));
});

it('lists the Form among the files it will delete', function () {
    expect($this->driver->run('module:livewire:delete', ['name' => 'article', '--module' => 'Blog'], ['no']))
        ->toBe(($this->outcome)(0, "\n\e[33m This will permanently delete Livewire component [article] and all associated files.\e[39m\n\nFiles to be deleted:\n  - <modules>/Blog/Livewire/⚡article.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/ArticleTest.php\n  - <modules>/Blog/Livewire/Forms/ArticleForm.php\n\n  Are you sure you want to delete component [article]? (yes/no) [no]\n❯ \nComponent [article] was not deleted.\n", $this->plain, 3));
});

it('marks a Form that other files use as kept', function () {
    File::put("{$this->root}/Blog/Livewire/Other.php", "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nuse Studeo\\Blog\\Livewire\\Forms\\ArticleForm;\n\nclass Other {}\n");

    expect($this->driver->run('module:livewire:delete', ['name' => 'article', '--module' => 'Blog'], ['no']))
        ->toBe(($this->outcome)(0, "\n\e[33m This will permanently delete Livewire component [article] and all associated files.\e[39m\n\nFiles to be deleted:\n  - <modules>/Blog/Livewire/⚡article.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/ArticleTest.php\n  - <modules>/Blog/Livewire/Forms/ArticleForm.php (kept: other files use it)\n\n  Are you sure you want to delete component [article]? (yes/no) [no]\n❯ \nComponent [article] was not deleted.\n", $this->plain, 3));
});

it('names the kept Form and the file using it on standard output when forced', function () {
    File::put("{$this->root}/Blog/Livewire/Other.php", "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nuse Studeo\\Blog\\Livewire\\Forms\\ArticleForm;\n\nclass Other {}\n");

    expect($this->driver->run('module:livewire:delete', ['name' => 'article', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Livewire component [article] deleted successfully from module [Blog].\nDeleted files:\n  - <modules>/Blog/Livewire/⚡article.blade.php\n  - <modules>/Blog/tests/Feature/Livewire/ArticleTest.php\nKept Form [<modules>/Blog/Livewire/Forms/ArticleForm.php] because other files use it: <modules>/Blog/Livewire/Other.php.\n", $this->plain));
});
