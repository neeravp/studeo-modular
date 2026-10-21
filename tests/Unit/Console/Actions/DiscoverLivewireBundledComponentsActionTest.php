<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture;
    $this->composerPath = $this->fixture->composerPath;
    $this->rootModulesPath = $this->fixture->rootPath;
    $this->blogModule = $this->fixture->definition('Blog');
    $this->fixture->writeRoot();
    $this->fixture->modules('Blog');
});

afterEach(fn () => $this->fixture->tearDown());

test('forModule discovers Livewire 4 MFC components by their zap directory and bundled files', function () {
    $mfcDir = $this->blogModule->path.'/Livewire/⚡comment-section';
    File::ensureDirectoryExists($mfcDir);

    File::put($mfcDir.'/comment-section.php', "<?php\n\nuse Livewire\\Component;\n\nnew class extends Component\n{\n};\n");
    File::put($mfcDir.'/comment-section.blade.php', '<div>Comments</div>');
    File::put($mfcDir.'/comment-section.test.php', '<?php // pest test');
    File::put($mfcDir.'/comment-section.css', '/* css */');

    $components = app(DiscoverLivewireComponentsAction::class)->forModule($this->blogModule);

    expect(array_keys($components))->toBe(['comment-section']);

    $component = $components['comment-section'];
    expect($component->name)->toBe('comment-section')
        ->and($component->alias)->toBe('comment-section')
        ->and($component->target)->toBe(realpath($mfcDir))
        ->and($component->path)->toBe(realpath($mfcDir))
        ->and($component->type)->toBe('mfc')
        ->and($component->isDirectoryBased())->toBeTrue()
        ->and($component->files)->toHaveCount(4);
});

test('forModule strips the zap from nested aliases and never reads an MFC member file as a component', function () {
    $livewire = $this->blogModule->path.'/Livewire';
    File::ensureDirectoryExists($livewire.'/Posts/⚡create');
    File::ensureDirectoryExists($livewire.'/Posts');
    File::put($livewire.'/Posts/⚡create/create.php', "<?php\n\nuse Livewire\\Component;\n\nnew class extends Component\n{\n};\n");
    File::put($livewire.'/Posts/⚡create/create.blade.php', '<div></div>');
    File::put($livewire.'/Posts/⚡edit.blade.php', '<div></div>');
    File::put($livewire.'/Counter.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nuse Livewire\\Component;\n\nclass Counter extends Component {}\n");

    $components = app(DiscoverLivewireComponentsAction::class)->forModule($this->blogModule);

    expect(array_keys($components))->toEqualCanonicalizing(['counter', 'posts.create', 'posts.edit'])
        ->and(array_map(fn ($component) => $component->type, $components))->toMatchArray(['counter' => 'classic', 'posts.create' => 'mfc', 'posts.edit' => 'sfc'])
        ->and(implode(' ', array_keys($components)))->not->toContain('⚡')->not->toContain('index');
});

test('forModule discovers SFC single file blade components', function () {
    $livewireDir = $this->blogModule->path.'/Livewire';
    File::ensureDirectoryExists($livewireDir);

    $sfcFile = $livewireDir.'/⚡status-badge.blade.php';
    File::put($sfcFile, '<div>Badge</div>');

    $action = app(DiscoverLivewireComponentsAction::class);
    $components = $action->forModule($this->blogModule);

    expect($components)->toHaveKey('status-badge');

    $component = $components['status-badge'];
    expect($component->name)->toBe('status-badge')
        ->and($component->alias)->toBe('status-badge')
        ->and($component->target)->toBe(realpath($sfcFile))
        ->and($component->type)->toBe('sfc')
        ->and($component->files)->toBe([realpath($sfcFile)]);
});
