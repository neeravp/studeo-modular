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

it('shows form validation errors under form.{field} and never calls the Action', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $called = 0;
        $app->bind(Studeo\Blog\Actions\CreateArticleAction::class, function () use (&$called) { return new class($called) { public function __construct(private int &$called) {} public function __invoke($input, $actor) { $this->called++; } }; });
        $testable = $fill(Livewire\Livewire::actingAs(new Illuminate\Auth\GenericUser(['id' => 7]))->test('blog.create-article'), [...$valid, 'form.slug' => ''])->call('save');
        echo json_encode(['keys' => array_keys($testable->errors()->toArray()), 'called' => $called]);
        PHP);

    expect($result)->toBe(['keys' => ['form.slug'], 'called' => 0]);
});

it('aborts with 401, showing no form error, when nobody is authenticated', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $unauthenticated = $fill(Livewire\Livewire::test('blog.create-article'), $valid)->call('save');
        echo json_encode($outcome(fn () => $unauthenticated->assertStatus(401)->assertHasNoErrors()));
        PHP);

    expect($result)->toBe('ok');
});

it('shows the Action precondition errors under the form keys, keeps the state, and lets an authorization failure propagate as a 403 instead of a form error', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $user = new Illuminate\Auth\GenericUser(['id' => 7]);
        $saved = $fill(Livewire\Livewire::actingAs($user)->test('blog.create-article'), $valid)->call('save')->assertHasNoErrors();
        Illuminate\Support\Facades\DB::table('articles')->insert(['slug' => 'a', 'title' => 't', 'status' => 'draft', 'price' => '1.00', 'author_id' => 7]);
        $taken = $fill(Livewire\Livewire::actingAs($user)->test('blog.create-article'), $valid)->call('save');
        $missing = $fill(Livewire\Livewire::actingAs(new Illuminate\Auth\GenericUser(['id' => 999]))->test('blog.create-article'), [...$valid, 'form.slug' => 'b'])->call('save');
        Illuminate\Support\Facades\Gate::define('create', fn () => false);
        $denied = $fill(Livewire\Livewire::actingAs($user)->test('blog.create-article'), [...$valid, 'form.slug' => 'c'])->call('save');
        echo json_encode([
            'taken' => $taken->errors()->toArray(),
            'takenSlug' => $taken->get('form.slug'),
            'missing' => $missing->errors()->toArray(),
            'denied' => $outcome(fn () => $denied->assertStatus(403)->assertHasNoErrors()),
        ]);
        PHP);

    expect($result['taken'])->toBe(['form.slug' => ['The slug has already been taken.']])
        ->and($result['takenSlug'])->toBe('a')
        ->and($result['missing'])->toBe(['form.author_id' => ['The selected author id does not exist.']])
        ->and($result['denied'])->toBe('ok');
});

it('re-keys every error of a multi-field exception to the Form state', function () {
    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $form->addActionErrors(Illuminate\Validation\ValidationException::withMessages(['slug' => ['one', 'two'], 'title' => 'three']));
        echo json_encode($form->getComponent()->getErrorBag()->toArray());
        PHP);

    expect($result)->toBe(['form.slug' => ['one', 'two'], 'form.title' => ['three']]);
});

it('handles an emptied numeric input: nullable fields reach the Action as null, a required one is a validation error, and nothing throws', function () {
    $this->artisan('module:schema', ['name' => 'Metric', '--module' => 'Blog', '--fields' => 'label:string,views:integer:nullable,count:integer,ratio:decimal(8,2):nullable'])->assertSuccessful();
    $this->artisan('module:livewire', ['name' => 'EditMetric', '--module' => 'Blog', '--schema' => '@metrics', '--action' => 'CreateMetric'])->assertSuccessful();

    $result = (new LivewireFormProcess)->run($this->modulesPath, <<<'PHP'
        $calls = [];
        $app->bind(Studeo\Blog\Actions\CreateMetricAction::class, function () use (&$calls) { return new class($calls) { public function __construct(private array &$calls) {} public function __invoke($input, $actor) { $this->calls[] = $input->toArray(); } }; });
        $user = new Illuminate\Auth\GenericUser(['id' => 7]);
        $emptied = fn () => Livewire\Livewire::actingAs($user)->test('blog.edit-metric')->set('form.label', 'l');
        $nullable = $outcome(function () use ($emptied, &$state) {
            $testable = $emptied()->set('form.count', 2)->set('form.views', '')->set('form.ratio', '')->call('save')->assertHasNoErrors();
            $state = $testable->get('form.views');
        });
        $required = null;
        $requiredOutcome = $outcome(function () use ($emptied, &$required) {
            $testable = $emptied()->set('form.count', '')->call('save');
            $required = ['keys' => array_keys($testable->errors()->toArray()), 'count' => $testable->get('form.count')];
        });
        $held = $emptied()->set('form.views', '')->set('form.count', '')->set('form.ratio', '');
        echo json_encode(['held' => [$held->get('form.views'), $held->get('form.count'), $held->get('form.ratio')], 'nullable' => $nullable, 'state' => $state, 'requiredOutcome' => $requiredOutcome, 'required' => $required, 'calls' => $calls]);
        PHP);

    expect($result['nullable'])->toBe('ok')
        ->and($result['requiredOutcome'])->toBe('ok')
        ->and($result['held'])->toBe([null, null, ''])
        ->and($result['required']['keys'])->toBe(['form.count'])
        ->and($result['calls'])->toBe([['label' => 'l', 'count' => 2, 'views' => null, 'ratio' => null]]);
});

it('writes only inside the module directory', function () {
    expect($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});
