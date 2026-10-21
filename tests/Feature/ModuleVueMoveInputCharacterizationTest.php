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

it('moves an explicitly named component with --force', function () {
    expect($this->driver->run('module:vue:move', ['name' => 'PostCard', 'new-name' => 'ItemCard', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Vue Component [PostCard] moved to [ItemCard] in module [Blog].\nMoved file: <modules>/Blog/resources/js/Components/PostCard.vue => <modules>/Blog/resources/js/Components/ItemCard.vue\n", $this->plain));
});

it('takes the page first when a component and a page share an explicit name', function () {
    expect($this->driver->run('module:vue:move', ['name' => 'Index', 'new-name' => 'Home', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(0, "Vue Page [Index] moved to [Home] in module [Blog].\nMoved file: <modules>/Blog/resources/js/Pages/Index.vue => <modules>/Blog/resources/js/Pages/Home.vue\n", $this->plain));
});

it('fails with the component label when the name is unknown', function () {
    expect($this->driver->run('module:vue:move', ['name' => 'Ghost', 'new-name' => 'X', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue component [Ghost] does not exist in module [Blog].\n", $this->plain));
});

it('fails with the page label when the name is unknown under --page', function () {
    expect($this->driver->run('module:vue:move', ['name' => 'Ghost', 'new-name' => 'X', '--module' => 'Blog', '--page' => true, '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue Page [Ghost] does not exist in module [Blog].\n", $this->plain));
});

it('takes an empty name as an explicit name that does not exist', function () {
    expect($this->driver->run('module:vue:move', ['name' => '', 'new-name' => 'X', '--module' => 'Blog', '--force' => true]))
        ->toBe(($this->outcome)(1, "Vue component [] does not exist in module [Blog].\n", $this->plain));
});

it('offers pages before components with their type and moves the chosen one', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], ['Pages/Posts/Index']))
        ->toBe(($this->outcome)(0, "\n  Which Vue component would you like to move from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n  Index (Vue Component) ... Components/Index  \n  PostCard (Vue Component) ... Components/PostCard  \n❯ \nVue Page [Posts/Index] moved to [Renamed] in module [Blog].\nMoved file: <modules>/Blog/resources/js/Pages/Posts/Index.vue => <modules>/Blog/resources/js/Pages/Renamed.vue\n", $this->plain, 18));
});

it('offers only the pages under --page', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'Renamed', '--module' => 'Blog', '--page' => true, '--force' => true], ['Pages/Index']))
        ->toBe(($this->outcome)(0, "\n  Which Vue component would you like to move from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n❯ \nVue Page [Index] moved to [Renamed] in module [Blog].\nMoved file: <modules>/Blog/resources/js/Pages/Index.vue => <modules>/Blog/resources/js/Pages/Renamed.vue\n", $this->plain, 12));
});

it('moves the component when the component of the same base name is chosen', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], ['Components/Index']))
        ->toBe(($this->outcome)(0, "\n  Which Vue component would you like to move from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n  Index (Vue Component) ... Components/Index  \n  PostCard (Vue Component) ... Components/PostCard  \n❯ \nVue Component [Index] moved to [Renamed] in module [Blog].\nMoved file: <modules>/Blog/resources/js/Components/Index.vue => <modules>/Blog/resources/js/Components/Renamed.vue\n", $this->plain, 17));
});

it('asks for the new name after the component', function () {
    expect($this->driver->run('module:vue:move', ['--module' => 'Blog', '--force' => true], ['Components/PostCard', 'ItemCard']))
        ->toBe(($this->outcome)(0, "\n  Which Vue component would you like to move from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n  Index (Vue Component) ... Components/Index  \n  PostCard (Vue Component) ... Components/PostCard  \n❯ \n  What should the new component name be? [PostCard]\n❯ \nVue Component [PostCard] moved to [ItemCard] in module [Blog].\nMoved file: <modules>/Blog/resources/js/Components/PostCard.vue => <modules>/Blog/resources/js/Components/ItemCard.vue\n", $this->plain, 29));
});

it('warns about pages and goes no further when --page finds none', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'X', '--module' => 'Shop', '--page' => true]))
        ->toBe(($this->outcome)(0, "No Vue Pages found in module [Shop].\n", $this->plain));
});

it('warns about components when the module has none', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'X', '--module' => 'Empty']))
        ->toBe(($this->outcome)(0, "No Vue components found in module [Empty].\n", $this->plain));
});

it('warns the same way when it cannot prompt', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'X', '--module' => 'Empty'], [], false))
        ->toBe(($this->outcome)(0, "No Vue components found in module [Empty].\n", $this->plain));
});

it('names the missing component name when it cannot prompt and components exist', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'X', '--module' => 'Blog'], [], false))
        ->toBe(($this->outcome)(1, "The [name] argument must be given when the command is not interactive, e.g. module:vue:move Card Renamed --module=Blog --force.\n", $this->plain));
});

it('asks for the module and then for the component', function () {
    expect($this->driver->run('module:vue:move', ['new-name' => 'Renamed', '--force' => true], ['Blog', 'Components/PostCard']))
        ->toBe(($this->outcome)(0, "\n  Which module should this belong to?\n  Blog ... 0  \n  Empty ... 1  \n  Shop ... 2  \n❯ \n  Which Vue component would you like to move from [Blog]?\n  Index (Vue Page) ... Pages/Index  \n  Posts/Index (Vue Page) ... Pages/Posts/Index  \n  Index (Vue Component) ... Components/Index  \n  PostCard (Vue Component) ... Components/PostCard  \n❯ \nVue Component [PostCard] moved to [Renamed] in module [Blog].\nMoved file: <modules>/Blog/resources/js/Components/PostCard.vue => <modules>/Blog/resources/js/Components/Renamed.vue\n", $this->picked, 25));
});
