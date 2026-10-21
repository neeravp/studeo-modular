<?php

use Studeo\Modular\Tests\Support\ComponentCommandFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = ComponentCommandFixture::vue(app());
    $this->root = $this->fixture->root;
    $this->driver = $this->fixture->driver;
    $this->outcome = $this->fixture->outcome(...);
    $this->plain = $this->fixture->plain;
    $this->picked = $this->fixture->picked;
});

afterEach(fn () => $this->fixture->tearDown());

it('deletes an explicitly named component with --force', function () {
    expect($this->driver->run('module:vue:delete', ['name' => 'PostCard', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Vue Component [PostCard] deleted successfully from module [Blog].\nDeleted file: <modules>/Blog/resources/js/Components/PostCard.vue\n", $this->plain));
});

it('takes the page first when a component and a page share an explicit name', function () {
    expect($this->driver->run('module:vue:delete', ['name' => 'Index', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Vue Page [Index] deleted successfully from module [Blog].\nDeleted file: <modules>/Blog/resources/js/Pages/Index.vue\n", $this->plain));
});

it('takes the page with --page when a component and a page share a name', function () {
    expect($this->driver->run('module:vue:delete', ['name' => 'Index', '--module' => 'Blog', '--page' => true, '--force' => true]))
        ->toBe(($this->outcome)(0, "Vue Page [Index] deleted successfully from module [Blog].\nDeleted file: <modules>/Blog/resources/js/Pages/Index.vue\n", $this->plain));
});

it('fails with the component label when the name is unknown', function () {
    expect($this->driver->run('module:vue:delete', ['name' => 'Ghost', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue component [Ghost] does not exist in module [Blog].\n", $this->plain));
});

it('fails with the page label when the name is unknown under --page', function () {
    expect($this->driver->run('module:vue:delete', ['name' => 'Ghost', '--module' => 'Blog', '--page' => true, '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue Page [Ghost] does not exist in module [Blog].\n", $this->plain));
});

it('fails under --page when only a component has the name', function () {
    expect($this->driver->run('module:vue:delete', ['name' => 'PostCard', '--module' => 'Blog', '--page' => true, '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue Page [PostCard] does not exist in module [Blog].\n", $this->plain));
});

it('takes an empty name as an explicit name that does not exist', function () {
    expect($this->driver->run('module:vue:delete', ['name' => '', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue component [] does not exist in module [Blog].\n", $this->plain));
});

it('takes 0 as an explicit name that does not exist', function () {
    expect($this->driver->run('module:vue:delete', ['name' => '0', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue component [0] does not exist in module [Blog].\n", $this->plain));
});

it('offers pages before components with their type and deletes the chosen one', function () {
    expect($this->driver->run('module:vue:delete', ['--module' => 'Blog', '--force' => true], ['Pages/Posts/Index']))
        ->toBe(($this->outcome)(0, "\n  Which Vue component would you like to delete from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n  Index (Vue Component) ... Components/Index  \n  PostCard (Vue Component) ... Components/PostCard  \n❯ \nVue Page [Posts/Index] deleted successfully from module [Blog].\nDeleted file: <modules>/Blog/resources/js/Pages/Posts/Index.vue\n", $this->plain, 18));
});

it('offers only the pages under --page', function () {
    expect($this->driver->run('module:vue:delete', ['--module' => 'Blog', '--page' => true, '--force' => true], ['Pages/Posts/Index']))
        ->toBe(($this->outcome)(0, "\n  Which Vue component would you like to delete from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n❯ \nVue Page [Posts/Index] deleted successfully from module [Blog].\nDeleted file: <modules>/Blog/resources/js/Pages/Posts/Index.vue\n", $this->plain, 18));
});

it('deletes the component when the component of the same base name is chosen', function () {
    expect($this->driver->run('module:vue:delete', ['--module' => 'Blog', '--force' => true], ['Components/Index']))
        ->toBe(($this->outcome)(0, "\n  Which Vue component would you like to delete from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n  Index (Vue Component) ... Components/Index  \n  PostCard (Vue Component) ... Components/PostCard  \n❯ \nVue Component [Index] deleted successfully from module [Blog].\nDeleted file: <modules>/Blog/resources/js/Components/Index.vue\n", $this->plain, 17));
});

it('warns about pages when --page finds none', function () {
    expect($this->driver->run('module:vue:delete', ['--module' => 'Shop', '--page' => true]))
        ->toBe(($this->outcome)(0, "No Vue Pages found in module [Shop].\n", $this->plain));
});

it('warns about components when the module has none', function () {
    expect($this->driver->run('module:vue:delete', ['--module' => 'Empty']))
        ->toBe(($this->outcome)(0, "No Vue components found in module [Empty].\n", $this->plain));
});

it('warns the same way when it cannot prompt', function () {
    expect($this->driver->run('module:vue:delete', ['--module' => 'Empty', '--page' => true], [], false))
        ->toBe(($this->outcome)(0, "No Vue Pages found in module [Empty].\n", $this->plain));
});

it('names the missing component name when it cannot prompt and components exist', function () {
    expect($this->driver->run('module:vue:delete', ['--module' => 'Blog'], [], false))
        ->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:vue:delete Card --module=Blog --force.\n", $this->plain));
});

it('asks for the module and then for the component', function () {
    expect($this->driver->run('module:vue:delete', [], ['Blog', 'Pages/Index', 'no']))
        ->toBe(($this->outcome)(0, "\n  Which module should this belong to?\n  Blog ... 0  \n  Empty ... 1  \n  Shop ... 2  \n❯ \n  Which Vue component would you like to delete from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n  Index (Vue Component) ... Components/Index  \n  PostCard (Vue Component) ... Components/PostCard  \n❯ \n\e[33m This will permanently delete Vue Page [Index].\e[39m\n\nFile to be deleted: <modules>/Blog/resources/js/Pages/Index.vue\n\n  Are you sure you want to delete component [Index]? (yes/no) [no]\n❯ \nComponent [Index] was not deleted.\n", $this->picked, 20));
});

it('lists the file of a component and deletes on yes', function () {
    expect($this->driver->run('module:vue:delete', ['name' => 'PostCard', '--module' => 'Blog'], ['yes']))
        ->toBe(($this->outcome)(0, "\n\e[33m This will permanently delete Vue Component [PostCard].\e[39m\n\nFile to be deleted: <modules>/Blog/resources/js/Components/PostCard.vue\n\n  Are you sure you want to delete component [PostCard]? (yes/no) [no]\n❯ \nVue Component [PostCard] deleted successfully from module [Blog].\nDeleted file: <modules>/Blog/resources/js/Components/PostCard.vue\n", $this->plain, 4));
});
