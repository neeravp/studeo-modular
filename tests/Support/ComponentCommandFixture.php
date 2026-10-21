<?php

namespace Studeo\Modular\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\Console\Actions\DiscoverVueComponentsAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Enums\VueComponentType;

/**
 * The Blog, Shop and Empty modules with a known set of Livewire or Vue components, the command
 * driver that logs the discovery action, and the expected resolution logs of the delete and move
 * input characterizations.
 */
final class ComponentCommandFixture
{
    public readonly string $root;

    public readonly CommandDriver $driver;

    /** @var array<int, string> */
    public readonly array $plain;

    /** @var array<int, string> */
    public readonly array $picked;

    /**
     * @param  class-string  $discovery
     */
    private function __construct(Application $app, string $discovery, int $extraModuleResolutions)
    {
        $this->root = config('modular.root_path');
        $this->driver = new CommandDriver($app, $this->root, [$discovery]);
        $discover = 'resolve:'.class_basename($discovery);
        $this->plain = [
            'resolve:ResolveModuleAction', $discover, 'resolve:ResolveModuleAction', $discover, 'resolve:ResolveModuleAction',
            ...array_fill(0, $extraModuleResolutions, 'resolve:ResolveModuleAction'),
        ];
        $this->picked = [...$this->plain, 'resolve:DiscoverModulesAction'];
    }

    public static function livewire(Application $app, int $extraModuleResolutions = 0): self
    {
        $fixture = new self($app, DiscoverLivewireComponentsAction::class, $extraModuleResolutions);
        $fixture->createModules();
        $fixture->createLivewireComponents();

        return $fixture;
    }

    public static function vue(Application $app): self
    {
        $fixture = new self($app, DiscoverVueComponentsAction::class, 0);
        $fixture->createModules();
        $fixture->createVueComponents();

        return $fixture;
    }

    /**
     * @param  array<int, string>  $resolved
     * @return array{status: int, stdout: string, stderr: string, answered: int, resolved: array<int, string>}
     */
    public function outcome(int $status, string $stdout, array $resolved, int $answered = 0): array
    {
        return [
            'status' => $status,
            'stdout' => $stdout,
            'stderr' => '',
            'answered' => $answered,
            'resolved' => $resolved,
        ];
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->root);
        $this->driver->resetPrompts();
    }

    private function createModules(): void
    {
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);

        foreach (['Blog', 'Shop', 'Empty'] as $name) {
            app(CreateModuleAction::class)->execute($this->module($name));
        }
    }

    private function createLivewireComponents(): void
    {
        $blog = $this->module('Blog');
        $creator = app(CreateLivewireComponentAction::class);
        $creator->execute($blog, 'PostList');
        $creator->execute($blog, 'UserBadge');
        $creator->execute($blog, 'Posts/Create', new LivewireComponentOptions(type: LivewireComponentType::Sfc));
        $creator->execute($blog, 'Panel', new LivewireComponentOptions(type: LivewireComponentType::Mfc));
        $creator->execute($blog, 'Article', new LivewireComponentOptions(type: LivewireComponentType::Sfc));
        File::append("{$this->root}/Blog/Livewire/⚡article.blade.php", "\nuse Studeo\\Blog\\Livewire\\Forms\\ArticleForm;\n");
        File::ensureDirectoryExists("{$this->root}/Blog/Livewire/Forms");
        File::put("{$this->root}/Blog/Livewire/Forms/ArticleForm.php", "<?php\n\nnamespace Studeo\\Blog\\Livewire\\Forms;\n\nclass ArticleForm {}\n");
    }

    private function createVueComponents(): void
    {
        $creator = app(CreateVueComponentAction::class);
        $page = new VueComponentOptions(type: VueComponentType::Page);
        $creator->execute($this->module('Blog'), 'PostCard');
        $creator->execute($this->module('Blog'), 'Index');
        $creator->execute($this->module('Blog'), 'Index', $page);
        $creator->execute($this->module('Blog'), 'Posts/Index', $page);
        $creator->execute($this->module('Shop'), 'Card');
    }

    private function module(string $name): ModuleDefinition
    {
        return new ModuleDefinition($name, "Studeo\\{$name}", "{$this->root}/{$name}");
    }
}
