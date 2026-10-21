<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->driver = $this->fixture->driver();
    $this->list = "\n  Which module should this belong to?\n  Blog ... 0  \n  Shop ... 1  \n❯ \n";
    $this->models = "\n  Which model should this policy bind to? [Post]\n  None ... 0  \n  Post ... 1  \n❯ \n";
    $this->several = "There is more than one module (Blog, Shop). Pass --module=<name> to choose one.\n";

    $this->tree = function (string ...$names) {
        foreach ($names as $name) {
            app(CreateModuleAction::class)->execute(new ModuleDefinition($name, "Studeo\\{$name}", "{$this->rootPath}/{$name}"));
        }
        $blog = new ModuleDefinition('Blog', 'Studeo\\Blog', "{$this->rootPath}/Blog");
        File::ensureDirectoryExists("{$this->rootPath}/Blog/Models");
        File::put("{$this->rootPath}/Blog/Models/Post.php", "<?php\n\nnamespace Studeo\\Blog\\Models;\n\nclass Post {}\n");
        app(CreateLivewireComponentAction::class)->execute($blog, 'PostList');
        app(CreateVueComponentAction::class)->execute($blog, 'PostCard');
    };
    $this->outcome = fn (int $status, string $stdout, array $resolved, int $answered = 0): array => [
        'status' => $status,
        'stdout' => $stdout,
        'stderr' => '',
        'answered' => $answered,
        'resolved' => $resolved,
    ];
    $this->policy = ['resolve:ResolveModuleAction', 'resolve:ResolveModuleAction', 'resolve:DiscoverModulesAction'];
    $this->livewire = [...array_fill(0, 6, 'resolve:ResolveModuleAction'), 'resolve:DiscoverModulesAction'];
    $this->vue = [...array_fill(0, 3, 'resolve:ResolveModuleAction'), 'resolve:DiscoverModulesAction'];
    $this->livewireMoved = "Livewire component [PostList] moved to [AllPosts] in module [Blog].\nMoved files:\n"
        ."  - <modules>/Blog/Livewire/PostList.php => <modules>/Blog/Livewire/AllPosts.php\n"
        ."  - <modules>/Blog/resources/views/livewire/post-list.blade.php => <modules>/Blog/resources/views/livewire/all-posts.blade.php\n"
        ."  - <modules>/Blog/tests/Feature/Livewire/PostListTest.php => <modules>/Blog/tests/Feature/Livewire/AllPostsTest.php\n";
    $this->vueMoved = "Vue Component [PostCard] moved to [ItemCard] in module [Blog].\n"
        ."Moved file: <modules>/Blog/resources/js/Components/PostCard.vue => <modules>/Blog/resources/js/Components/ItemCard.vue\n";
    $this->livewireInput = ['name' => 'PostList', 'new-name' => 'AllPosts', '--force' => true];
    $this->vueInput = ['name' => 'PostCard', 'new-name' => 'ItemCard', '--force' => true];
});

afterEach(fn () => $this->fixture->tearDown());

it('asks for the module and then for the model, both through a select', function () {
    ($this->tree)('Blog', 'Shop');
    $ok = $this->list.ltrim($this->models, "\n")."Policy [PostPolicy] created successfully in module [Blog].\n";

    expect($this->driver->run('module:policy', ['name' => 'PostPolicy'], ['Blog', 'Post']))->toBe(($this->outcome)(0, $ok, $this->policy, 10));
});

it('announces the only module and then asks for the model', function () {
    ($this->tree)('Blog');
    $ok = "Using module [Blog].\n".$this->models."Policy [PostPolicy] created successfully in module [Blog].\n";

    expect($this->driver->run('module:policy', ['name' => 'PostPolicy'], ['Post']))->toBe(($this->outcome)(0, $ok, $this->policy, 5));
});

it('does not ask for a model when it cannot prompt, and --module skips the picker', function () {
    ($this->tree)('Blog', 'Shop');

    expect($this->driver->run('module:policy', ['name' => 'PostPolicy', '--module' => 'Blog'], [], false))
        ->toBe(($this->outcome)(0, "Policy [PostPolicy] created successfully in module [Blog].\n", ['resolve:ResolveModuleAction', 'resolve:ResolveModuleAction']));
});

it('names --module for module:policy when there are several modules and it cannot prompt', function () {
    ($this->tree)('Blog', 'Shop');

    expect($this->driver->run('module:policy', ['name' => 'PostPolicy'], [], false))->toBe(($this->outcome)(1, $this->several, $this->policy));
});

it('asks for the source module of a Livewire move', function () {
    ($this->tree)('Blog', 'Shop');

    expect($this->driver->run('module:livewire:move', $this->livewireInput, ['Blog']))->toBe(($this->outcome)(0, $this->list.$this->livewireMoved, $this->livewire, 5));
});

it('announces the only module of a Livewire move', function () {
    ($this->tree)('Blog');

    expect($this->driver->run('module:livewire:move', $this->livewireInput))->toBe(($this->outcome)(0, "Using module [Blog].\n".$this->livewireMoved, $this->livewire));
});

it('names --module for a Livewire move when there are several modules and it cannot prompt', function () {
    ($this->tree)('Blog', 'Shop');

    expect($this->driver->run('module:livewire:move', $this->livewireInput, [], false))->toBe(($this->outcome)(1, $this->several, $this->livewire));
});

it('asks for the source module of a Vue move', function () {
    ($this->tree)('Blog', 'Shop');

    expect($this->driver->run('module:vue:move', $this->vueInput, ['Blog']))->toBe(($this->outcome)(0, $this->list.$this->vueMoved, $this->vue, 5));
});

it('announces the only module of a Vue move', function () {
    ($this->tree)('Blog');

    expect($this->driver->run('module:vue:move', $this->vueInput))->toBe(($this->outcome)(0, "Using module [Blog].\n".$this->vueMoved, $this->vue));
});

it('names --module for a Vue move when there are several modules and it cannot prompt', function () {
    ($this->tree)('Blog', 'Shop');

    expect($this->driver->run('module:vue:move', $this->vueInput, [], false))->toBe(($this->outcome)(1, $this->several, $this->vue));
});
