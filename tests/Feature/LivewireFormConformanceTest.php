<?php

use Illuminate\Support\Composer;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\Support\LivewireFormProcess;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootComposerPath = base_path('composer.json');
    $this->originalRootComposer = File::exists($this->rootComposerPath) ? File::get($this->rootComposerPath) : null;
    File::put($this->rootComposerPath, json_encode(['name' => 'laravel/laravel', 'autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

    $this->modulesPath = config('modular.root_path');
    File::deleteDirectory($this->modulesPath);
    File::ensureDirectoryExists($this->modulesPath);

    $composer = Mockery::mock(Composer::class);
    $composer->shouldReceive('dumpAutoloads')->andReturn(0);
    $this->app->instance(Composer::class, $composer);

    $this->artisan('module:make', ['module' => 'Blog'])->assertSuccessful();
    $this->artisan('module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => 'draft,published'])->assertSuccessful();
    $this->artisan('module:schema', ['name' => 'Article', '--module' => 'Blog', '--fields' => 'slug:string:unique,title:string,status:enum(PostStatus):default(draft),note:string:nullable,views:integer:nullable,price:decimal(8,2):default(12.50),author_id:foreignId:constrained(users):context'])->assertSuccessful();
    $this->artisan('module:model', ['name' => 'Article', '--module' => 'Blog', '--no-factory' => true, '--no-seeder' => true, '--schema' => '@articles'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'CreateArticle', '--module' => 'Blog', '--schema' => '@articles', '--action' => 'CreateArticle', '--context' => ['author_id=actor']])->assertSuccessful();
    $this->artisan('module:install', ['module' => 'Blog'])->assertSuccessful();
    $this->module = $this->modulesPath.'/Blog';
    $this->rootAppBefore = $this->rootAppSnapshot();
});

afterEach(function () {
    File::deleteDirectory($this->modulesPath);

    $this->originalRootComposer !== null
        ? File::put($this->rootComposerPath, $this->originalRootComposer)
        : File::delete($this->rootComposerPath);
});

it('has a Form whose rules are the Input rules, whose state is plain snake_case and carries no context field or DTO', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $properties = [];
        foreach ((new ReflectionClass($form))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === get_class($form)) {
                $properties[$property->getName()] = (string) $property->getType();
            }
        }
        echo json_encode([
            'sameRules' => $form->rules() == Studeo\Blog\Data\CreateArticleInput::rules(),
            'properties' => $properties,
            'nullable' => $form::NULLABLE,
            'parent' => get_parent_class($form),
        ]);
        PHP);

    expect($result['sameRules'])->toBeTrue()
        ->and($result['properties'])->toBe(['slug' => 'string', 'title' => 'string', 'status' => 'string', 'note' => '?string', 'views' => '?int', 'price' => '?string'])
        ->and($result['nullable'])->toBe(['note', 'views'])
        ->and($result['parent'])->toBe('Livewire\\Form');
});

it('keeps every DTO out of the component and the Form state', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $component = Livewire\Livewire::actingAs(new Illuminate\Auth\GenericUser(['id' => 7]))->test('blog.create-article')->instance();
        $types = [];
        foreach ([$component, $component->form] as $object) {
            foreach ((new ReflectionObject($object))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $type = $property->getType();
                $class = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;
                $types[$property->getName()] = ['class' => $class, 'dto' => $class !== null && is_subclass_of($class, Studeo\Modular\Support\DataObject::class)];
            }
        }
        echo json_encode($types);
        PHP);

    expect($result['form'])->toBe(['class' => 'Studeo\\Blog\\Livewire\\Forms\\CreateArticleForm', 'dto' => false])
        ->and(array_filter($result, fn (array $type): bool => $type['dto']))->toBe([])
        ->and(array_filter($result, fn (array $type, string $name): bool => $type['class'] !== null && $name !== 'form', ARRAY_FILTER_USE_BOTH))->toBe([]);
});

it('turns an empty string into null for the nullable fields only, and passes the context', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        foreach (['slug' => 'a', 'title' => 't', 'status' => 'published', 'note' => '', 'views' => null, 'price' => '9.99'] as $name => $value) { $form->{$name} = $value; }
        $input = $form->toInput(['author_id' => 7]);
        $form->title = '';
        echo json_encode([
            'array' => $input->toArray(),
            'blankTitle' => $outcome(fn () => $form->toInput(['author_id' => 7])),
        ]);
        PHP);

    expect($result['array'])->toBe(['slug' => 'a', 'title' => 't', 'author_id' => 7, 'status' => 'published', 'note' => null, 'views' => null, 'price' => '9.99'])
        ->and($result['blankTitle'][0])->toBe('Illuminate\\Validation\\ValidationException');
});

it('saves through the Action with the right Input and actor, then resets the form', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $calls = [];
        $app->bind(Studeo\Blog\Actions\CreateArticleAction::class, function () use (&$calls) { return new class($calls) { public function __construct(private array &$calls) {} public function __invoke($input, $actor) { $this->calls[] = [$input->toArray(), $actor->getAuthIdentifier()]; } }; });
        $user = new Illuminate\Auth\GenericUser(['id' => 7]);
        $testable = $fill(Livewire\Livewire::actingAs($user)->test('blog.create-article'), $valid)->set('form.views', 3)->call('save')->assertHasNoErrors();
        echo json_encode(['calls' => $calls, 'slug' => $testable->get('form.slug')]);
        PHP);

    expect($result['calls'])->toBe([[['slug' => 'a', 'title' => 't', 'author_id' => 7, 'status' => 'published', 'note' => null, 'views' => 3, 'price' => '9.99'], 7]])
        ->and($result['slug'])->toBe('');
});
