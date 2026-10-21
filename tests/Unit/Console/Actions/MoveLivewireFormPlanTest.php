<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateLivewireComponentAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\DiscoverLivewireComponentsAction;
use Studeo\Modular\Console\Actions\MoveLivewireComponentAction;
use Studeo\Modular\Console\Actions\MoveLivewireFormAction;
use Studeo\Modular\Console\Actions\ResolveLivewireFormAction;
use Studeo\Modular\DataObjects\LivewireComponentOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Enums\LivewireComponentType;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = config('modular.root_path');
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root);

    $this->blog = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->root.'/Blog');
    $this->shop = new ModuleDefinition('Shop', 'Studeo\\Shop', $this->root.'/Shop');
    app(CreateModuleAction::class)->execute($this->blog);
    app(CreateModuleAction::class)->execute($this->shop);

    $this->form = $this->blog->path.'/Livewire/Forms/CreateArticleForm.php';
    $this->formSource = fn (string $name): string => "<?php\n\nnamespace Studeo\\Blog\\Livewire\\Forms;\n\nuse Livewire\\Form;\n\nclass {$name} extends Form\n{\n}\n";

    app(CreateLivewireComponentAction::class)->execute($this->blog, 'CreateArticle', new LivewireComponentOptions(type: LivewireComponentType::Sfc));
    File::ensureDirectoryExists(dirname($this->form));
    File::put($this->form, ($this->formSource)('CreateArticleForm'));
    File::append($this->blog->path.'/Livewire/⚡create-article.blade.php', "\n<?php\nuse Studeo\\Blog\\Livewire\\Forms\\CreateArticleForm;\n\nnew class extends \\Livewire\\Component {\n    public CreateArticleForm \$form;\n};\n");

    $this->tree = function (): array {
        $tree = [];

        foreach (File::allFiles($this->root, true) as $file) {
            $tree[$file->getPathname()] = md5_file($file->getPathname());
        }

        return $tree;
    };

    $this->failure = function (callable $call): string {
        $before = ($this->tree)();

        try {
            $call();
            $message = 'no exception';
        } catch (Throwable $exception) {
            $message = $exception::class.': '.$exception->getMessage();
        }

        expect(($this->tree)())->toBe($before);

        return $message;
    };

    $this->mover = fn (): MoveLivewireComponentAction => app(MoveLivewireComponentAction::class);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('a move whose target Form exists names the Form and moves nothing', function () {
    File::put($this->blog->path.'/Livewire/Forms/PublishArticleForm.php', ($this->formSource)('PublishArticleForm'));

    expect(($this->failure)(fn () => ($this->mover)()->execute($this->blog, 'CreateArticle', $this->blog, 'PublishArticle')))
        ->toBe('RuntimeException: Form [Livewire/Forms/PublishArticleForm.php] already exists in module [Blog]; nothing was moved.');
});

test('the Form is named in the target module', function () {
    File::ensureDirectoryExists($this->shop->path.'/Livewire/Forms');
    File::put($this->shop->path.'/Livewire/Forms/CreateArticleForm.php', ($this->formSource)('CreateArticleForm'));

    expect(($this->failure)(fn () => ($this->mover)()->execute($this->blog, 'CreateArticle', $this->shop, 'CreateArticle')))
        ->toBe('RuntimeException: Form [Livewire/Forms/CreateArticleForm.php] already exists in module [Shop]; nothing was moved.');
});

test('an existing target component is reported before an existing target Form', function () {
    File::put($this->blog->path.'/Livewire/Forms/PublishArticleForm.php', ($this->formSource)('PublishArticleForm'));
    app(CreateLivewireComponentAction::class)->execute($this->blog, 'PublishArticle');

    expect(($this->failure)(fn () => ($this->mover)()->execute($this->blog, 'CreateArticle', $this->blog, 'PublishArticle')))
        ->toBe('RuntimeException: Livewire component [PublishArticle] already exists in module [Blog].');
});

test('a Form that other files use is never planned, so an existing target Form does not stop the move', function () {
    File::put($this->blog->path.'/Livewire/Other.php', "<?php\n\nnamespace Studeo\\Blog\\Livewire;\n\nclass Other\n{\n    public string \$form = CreateArticleForm::class;\n}\n");
    File::put($this->blog->path.'/Livewire/Forms/PublishArticleForm.php', ($this->formSource)('PublishArticleForm'));

    $result = ($this->mover)()->execute($this->blog, 'CreateArticle', $this->blog, 'PublishArticle');

    expect($result->movedFiles)->toBe([
        $this->blog->path.'/Livewire/⚡create-article.blade.php' => $this->blog->path.'/Livewire/⚡publish-article.blade.php',
        $this->blog->path.'/tests/Feature/Livewire/CreateArticleTest.php' => $this->blog->path.'/tests/Feature/Livewire/PublishArticleTest.php',
    ])->and($result->notices)->toHaveCount(1)
        ->and($result->notices[0])->toStartWith('Left Form ['.$this->form.'] in place because other files use it: ')
        ->and($this->form)->toBeFile();
});

test('the result lists the component files, then its feature test, then the Form', function () {
    $result = ($this->mover)()->execute($this->blog, 'CreateArticle', $this->shop, 'CreateArticle');

    expect(array_values($result->movedFiles))->toBe([
        $this->shop->path.'/Livewire/⚡create-article.blade.php',
        $this->shop->path.'/tests/Feature/Livewire/CreateArticleTest.php',
        $this->shop->path.'/Livewire/Forms/CreateArticleForm.php',
    ])->and($result->notices)->toBe([])
        ->and(array_map(fn (string $path): string => str_replace($this->root, '', realpath($path) ?: $path), array_keys($result->movedFiles)))
        ->toBe(['/Blog/Livewire/⚡create-article.blade.php', '/Blog/tests/Feature/Livewire/CreateArticleTest.php', '/Blog/Livewire/Forms/CreateArticleForm.php']);
});

test('plan returns nothing when the target Form is the Form itself', function () {
    $form = app(ResolveLivewireFormAction::class)->execute($this->blog, app(DiscoverLivewireComponentsAction::class)->find($this->blog, 'CreateArticle'));

    expect(app(MoveLivewireFormAction::class)->plan($this->blog, $form, $this->blog, 'CreateArticle'))->toBeNull();
});

test('plan treats a directory at the target path as an existing Form', function () {
    File::ensureDirectoryExists($this->blog->path.'/Livewire/Forms/PublishArticleForm.php');
    $form = app(ResolveLivewireFormAction::class)->execute($this->blog, app(DiscoverLivewireComponentsAction::class)->find($this->blog, 'CreateArticle'));

    expect(fn () => app(MoveLivewireFormAction::class)->plan($this->blog, $form, $this->blog, 'PublishArticle'))
        ->toThrow(RuntimeException::class, 'Form [Livewire/Forms/PublishArticleForm.php] already exists in module [Blog]; nothing was moved.');
});

test('plan describes the move of a free target without touching the disk', function () {
    $form = app(ResolveLivewireFormAction::class)->execute($this->blog, app(DiscoverLivewireComponentsAction::class)->find($this->blog, 'CreateArticle'));
    $before = ($this->tree)();

    $move = app(MoveLivewireFormAction::class)->plan($this->blog, $form, $this->shop, 'Posts/Publish');

    expect($move->from)->toBe($this->form)
        ->and($move->to)->toBe($this->shop->path.'/Livewire/Forms/Posts/PublishForm.php')
        ->and($move->fromClass)->toBe('Studeo\Blog\Livewire\Forms\CreateArticleForm')
        ->and($move->toClass)->toBe('Studeo\Shop\Livewire\Forms\Posts\PublishForm')
        ->and($move->formsRoot)->toBe($this->blog->path.'/Livewire/Forms')
        ->and($move->content)->toContain('namespace Studeo\Shop\Livewire\Forms\Posts;')->toContain('class PublishForm extends Form')
        ->and(($this->tree)())->toBe($before);
});
