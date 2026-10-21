<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->blogModule);
    File::put($this->blogModule->path.'/Models/Post.php', '<?php');
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function inertiaController(array $arguments): string
{
    expect(Artisan::call('module:controller', ['name' => 'PostController', '--module' => 'Blog', '--inertia' => true, ...$arguments]))->toBe(0);

    return File::get(test()->blogModule->path.'/Controllers/PostController.php');
}

it('renders the page named after the controller from the default invokable controller', function () {
    expect(inertiaController([]))->toContain("return Inertia::render('Blog::Post');")->toContain('public function __invoke(Request $request)');
});

it('renders {Module}::{Resource}/{Method} from a resource controller and leaves the other methods empty', function () {
    $content = inertiaController(['--resource' => true, '--model' => 'Post']);

    expect($content)
        ->toContain("return Inertia::render('Blog::Post/Index');")
        ->toContain("return Inertia::render('Blog::Post/Create');")
        ->toContain("return Inertia::render('Blog::Post/Show');")
        ->toContain("return Inertia::render('Blog::Post/Edit');")
        ->and(substr_count($content, 'Inertia::render'))->toBe(4)
        ->and($content)->toContain('public function store(Request $request) {}')->toContain('public function destroy(Post $post) {}')
        ->not->toContain('redirect')->not->toContain('route(')->not->toContain('Response');
});
