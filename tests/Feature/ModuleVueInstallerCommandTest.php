<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

const INERTIA_QUESTION = 'Inertia 3 and Vue are not installed in this project. Install them with the commands above?';
const VITE_QUESTION = 'Write the Vite and Inertia files listed above?';

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->skeleton = skeletonFrontendSnapshot();
    Process::fake();
});

afterEach(function () {
    expect(skeletonFrontendSnapshot())->toBe($this->skeleton);
    File::deleteDirectory($this->rootModulesPath);
    cleanInstallerRoots();
});

function vueArguments(): array
{
    return ['name' => 'PostCard', '--module' => 'Blog'];
}

function postCardPath(object $test): string
{
    return $test->module->path.'/resources/js/Components/PostCard.vue';
}

describe('Inertia and Vue', function () {
    it('installs after a yes, running exactly the printed commands', function () {
        bindFrontendRoot($root = readyFrontendRoot(['package.json']));

        $this->artisan('module:vue', vueArguments())
            ->expectsOutputToContain('npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components')
            ->expectsConfirmation(INERTIA_QUESTION, 'yes')
            ->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command === ['npm', 'install', 'vue@^3.5', '@inertiajs/vue3@^3.0', '@vitejs/plugin-vue', 'unplugin-vue-components'] && $process->path === $root);
        expect(postCardPath($this))->toBeFile();
    });

    it('installs the server package too when it is the missing one', function () {
        bindFrontendRoot(readyFrontendRoot(), null);

        $this->artisan('module:vue', vueArguments())
            ->expectsOutputToContain('composer require inertiajs/inertia-laravel:^3.0')
            ->expectsConfirmation(INERTIA_QUESTION, 'yes')
            ->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command === ['composer', 'require', 'inertiajs/inertia-laravel:^3.0']);
    });

    it('writes nothing after a no', function () {
        bindFrontendRoot($root = readyFrontendRoot(['package.json']));
        $before = frontendSnapshot($root);

        $this->artisan('module:vue', vueArguments())
            ->expectsConfirmation(INERTIA_QUESTION, 'no')
            ->expectsOutputToContain('npm install vue@^3.5 @inertiajs/vue3@^3.0')
            ->assertFailed();

        Process::assertNothingRan();
        expect(frontendSnapshot($root))->toBe($before)->and(postCardPath($this))->not->toBeFile();
    });

    it('prints the instructions and writes nothing when it cannot prompt', function () {
        bindFrontendRoot($root = readyFrontendRoot(['package.json']));
        $before = frontendSnapshot($root);

        $status = Artisan::call('module:vue', vueArguments());

        expect($status)->toBe(1)
            ->and(Artisan::output())->toContain('npm install vue@^3.5 @inertiajs/vue3@^3.0 @vitejs/plugin-vue unplugin-vue-components')
            ->and(frontendSnapshot($root))->toBe($before)
            ->and(postCardPath($this))->not->toBeFile();
        Process::assertNothingRan();
    });

    it('refuses a v2 server package, writes nothing and prints the manual command', function () {
        bindFrontendRoot($root = readyFrontendRoot(), '2.0.4');
        $before = frontendSnapshot($root);

        $this->artisan('module:vue', vueArguments())
            ->expectsOutputToContain('composer require inertiajs/inertia-laravel:^3.0')
            ->assertFailed();

        Process::assertNothingRan();
        expect(frontendSnapshot($root))->toBe($before)->and(postCardPath($this))->not->toBeFile();
    });

    it('refuses a v2 client package and says ^3 everywhere', function () {
        $root = installerRoot([
            'package.json' => json_encode(['dependencies' => ['vue' => '^3.5.0', '@inertiajs/vue3' => '^2.3.0']]),
            'vite.config.js' => 'modules/*/resources/js/Components',
            'resources/js/app.js' => 'modules/**/resources/js/Pages',
            'resources/views/app.blade.php' => '<html></html>',
        ]);
        bindFrontendRoot($root);
        $before = frontendSnapshot($root);

        $status = Artisan::call('module:vue', vueArguments());
        $output = Artisan::output();

        expect($status)->toBe(1)
            ->and($output)->toContain('npm install @inertiajs/vue3@^3.0')
            ->not->toContain('^2')
            ->and(frontendSnapshot($root))->toBe($before);
        Process::assertNothingRan();
    });
});

describe('Vite and Inertia root files', function () {
    it('writes the files after a yes', function () {
        bindFrontendRoot($root = readyFrontendRoot(['vite.config.js', 'resources/js/app.js', 'resources/views/app.blade.php']));

        $this->artisan('module:vue', vueArguments())
            ->expectsConfirmation(VITE_QUESTION, 'yes')
            ->assertSuccessful();

        expect($root.'/vite.config.js')->toBeFile()
            ->and(File::get($root.'/resources/js/app.js'))->toContain('modules/**/resources/js/Pages')
            ->and($root.'/resources/views/app.blade.php')->toBeFile()
            ->and(postCardPath($this))->toBeFile();
    });

    it('writes nothing after a no, prints the files and still generates the component', function () {
        bindFrontendRoot($root = readyFrontendRoot(['vite.config.js', 'resources/js/app.js', 'resources/views/app.blade.php']));
        $before = frontendSnapshot($root);

        $this->artisan('module:vue', vueArguments())
            ->expectsConfirmation(VITE_QUESTION, 'no')
            ->expectsOutputToContain('modules/*/resources/js/Components')
            ->assertSuccessful();

        expect(frontendSnapshot($root))->toBe($before)->and(postCardPath($this))->toBeFile();
    });

    it('writes nothing when it cannot prompt and prints the files', function () {
        bindFrontendRoot($root = readyFrontendRoot(['vite.config.js', 'resources/js/app.js', 'resources/views/app.blade.php']));
        $before = frontendSnapshot($root);

        $status = Artisan::call('module:vue', vueArguments());

        expect($status)->toBe(0)
            ->and(Artisan::output())->toContain('vite.config.js')->toContain('modules/**/resources/js/Pages')
            ->and(frontendSnapshot($root))->toBe($before)
            ->and(postCardPath($this))->toBeFile();
    });

    it('never writes a custom vite config or app.js, and prints how to edit them', function () {
        $root = installerRoot([
            'package.json' => json_encode(['dependencies' => ['vue' => '^3.5.0', '@inertiajs/vue3' => '^3.0.0']]),
            'vite.config.js' => "import vue from '@vitejs/plugin-vue';",
            'resources/js/app.js' => 'createInertiaApp({});',
            'resources/views/app.blade.php' => '<html></html>',
        ]);
        bindFrontendRoot($root);
        $before = frontendSnapshot($root);

        $status = Artisan::call('module:vue', vueArguments());

        expect($status)->toBe(0)
            ->and(Artisan::output())->toContain('Existing frontend configuration detected')->toContain('modules/**/resources/js/Pages')
            ->and(frontendSnapshot($root))->toBe($before);
    });

    it('asks nothing when everything is in place', function () {
        bindFrontendRoot($root = readyFrontendRoot());
        $before = frontendSnapshot($root);

        $this->artisan('module:vue', vueArguments())->assertSuccessful();

        expect(frontendSnapshot($root))->toBe($before);
        Process::assertNothingRan();
    });
});
