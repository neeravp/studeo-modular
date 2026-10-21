<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    $this->pintPath = sys_get_temp_dir().'/modular-pint-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
    File::deleteDirectory($this->pintPath);
});

it('generates a bare model with its migration, factory and seeder Pint-clean', function (string $namespace) {
    blogModule($this, $namespace);

    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog']))->toBe(0)
        ->and(pintChanges($this, ['Models/Post.php', 'database/factories/PostFactory.php', 'database/seeders/PostSeeder.php', ...moduleFilesIn($this, 'database/migrations')]))->toBe([]);
})->with('namespaces');

it('generates a model from a schema Pint-clean, with an enum, a User relationship, a decimal and a json field', function (string $namespace) {
    blogModule($this, $namespace);

    $schema = 'title:string:unique,price:decimal(8,2),status:enum(PostStatus):default(draft),meta:json:nullable,author_id:foreignId:constrained(users),author:belongsTo(User):author_id,category_id:foreignId:constrained,category:belongsTo(Category)';

    expect(Artisan::call('module:enum', ['name' => 'PostStatus', '--module' => 'Blog', '--cases' => 'draft,published']))->toBe(0)
        ->and(Artisan::call('module:model', ['name' => 'Category', '--module' => 'Blog', '--no-migration' => true, '--no-seeder' => true]))->toBe(0)
        ->and(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--schema' => $schema]))->toBe(0)
        ->and(pintChanges($this, ['Models/Post.php', 'database/factories/PostFactory.php', 'database/seeders/PostSeeder.php', ...moduleFilesIn($this, 'database/migrations')]))->toBe([]);
})->with('namespaces');

it('generates a --no-factory model and its seeder Pint-clean', function (string $namespace) {
    blogModule($this, $namespace);

    expect(Artisan::call('module:model', ['name' => 'Note', '--module' => 'Blog', '--no-factory' => true]))->toBe(0)
        ->and(pintChanges($this, ['Models/Note.php', 'database/seeders/NoteSeeder.php', ...moduleFilesIn($this, 'database/migrations')]))->toBe([]);
})->with('namespaces');

it('generates standalone migrations, factories and seeders Pint-clean', function (string $namespace) {
    blogModule($this, $namespace);

    $migrations = [
        ['name' => 'create_tags_table', '--create' => 'tags'],
        ['name' => 'add_status_to_posts_table', '--table' => 'posts'],
        ['name' => 'tidy_up', '--blank' => true],
        ['name' => 'create_flight_passenger_table', '--pivot' => true],
        ['name' => 'create_post_tag_table', '--pivot' => true, '--morph' => true],
    ];

    foreach ($migrations as $options) {
        expect(Artisan::call('module:migration', ['--module' => 'Blog', ...$options]))->toBe(0);
    }

    foreach (moduleFilesIn($this, 'database/migrations') as $migration) {
        expect(File::get($this->rootModulesPath.'/Blog/'.$migration))->not->toContain('//');
    }

    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0)
        ->and(Artisan::call('module:factory', ['name' => 'PostFactory', '--module' => 'Blog']))->toBe(0)
        ->and(Artisan::call('module:factory', ['name' => 'DraftFactory', '--module' => 'Blog', '--model' => 'Post']))->toBe(0)
        ->and(Artisan::call('module:seeder', ['name' => 'PostSeeder', '--module' => 'Blog', '--model' => 'Post']))->toBe(0)
        ->and(Artisan::call('module:seeder', ['name' => 'TagSeeder', '--module' => 'Blog']))->toBe(0)
        ->and(Artisan::call('module:seeder', ['name' => 'CommentSeeder', '--module' => 'Blog', '--model' => 'Comment']))->toBe(0)
        ->and(pintChanges($this, [...moduleFilesIn($this, 'database/migrations'), ...moduleFilesIn($this, 'database/factories'), ...moduleFilesIn($this, 'database/seeders')]))->toBe([]);
})->with('namespaces');

it('generates every controller kind Pint-clean', function (string $namespace) {
    blogModule($this, $namespace);

    $controllers = [
        'StatusController' => [],
        'ArticleController' => ['--resource' => true],
        'PostController' => ['--resource' => true, '--model' => 'Post'],
        'FeedController' => ['--api' => true, '--model' => 'Post'],
        'HealthController' => ['--standard' => true],
        'HomeController' => ['--inertia' => true],
        'PageController' => ['--resource' => true, '--inertia' => true],
    ];

    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0);

    foreach ($controllers as $name => $options) {
        expect(Artisan::call('module:controller', ['name' => $name, '--module' => 'Blog', ...$options]))->toBe(0);
    }

    expect(pintChanges($this, array_map(fn (string $name): string => "Controllers/{$name}.php", array_keys($controllers))))->toBe([]);
})->with('namespaces');

it('generates the Action controller Pint-clean, with and without context fields', function (string $namespace) {
    blogModule($this, $namespace);

    expect(Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => 'title:string,author_id:foreignId:constrained(users):context,editor_id:foreignId:constrained(users):context']))->toBe(0)
        ->and(Artisan::call('module:action', ['name' => 'Posts/PublishPost', '--module' => 'Blog', '--schema' => 'note:string']))->toBe(0)
        ->and(Artisan::call('module:controller', ['--module' => 'Blog', '--action' => 'CreatePost', '--context' => ['author_id=actor', 'editor_id=actor']]))->toBe(0)
        ->and(Artisan::call('module:controller', ['--module' => 'Blog', '--action' => 'Posts/PublishPost']))->toBe(0)
        ->and(pintChanges($this, ['Controllers/CreatePostController.php', 'Controllers/PublishPostController.php']))->toBe([]);
})->with('namespaces');

it('generates the plain and the model policy Pint-clean', function (string $namespace) {
    blogModule($this, $namespace);

    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0)
        ->and(Artisan::call('module:policy', ['name' => 'GeneralPolicy', '--module' => 'Blog']))->toBe(0)
        ->and(Artisan::call('module:policy', ['name' => 'PostPolicy', '--module' => 'Blog', '--model' => 'Post']))->toBe(0)
        ->and(pintChanges($this, ['Policies/GeneralPolicy.php', 'Policies/PostPolicy.php']))->toBe([]);
})->with('namespaces');

it('generates the Livewire Form Pint-clean, with and without json fields', function (string $namespace) {
    blogModule($this, $namespace);

    expect(Artisan::call('module:livewire', ['name' => 'CreateNote', '--module' => 'Blog', '--schema' => 'title:string,meta:json:nullable,extra:jsonb', '--action' => 'CreateNote']))->toBe(0)
        ->and(Artisan::call('module:livewire', ['name' => 'CreateTag', '--module' => 'Blog', '--schema' => 'label:string,note:string:nullable', '--action' => 'CreateTag']))->toBe(0)
        ->and(pintChanges($this, ['Livewire/Forms/CreateNoteForm.php', 'Livewire/Forms/CreateTagForm.php']))->toBe([]);
})->with('namespaces');

it('generates the queued listener with its event Pint-clean', function (string $namespace) {
    blogModule($this, $namespace);

    expect(Artisan::call('module:event', ['name' => 'PostPublished', '--module' => 'Blog']))->toBe(0)
        ->and(Artisan::call('module:listener', ['name' => 'SendNotice', '--module' => 'Blog', '--event' => 'PostPublished', '--queued' => true]))->toBe(0)
        ->and(Artisan::call('module:listener', ['name' => 'LogNotice', '--module' => 'Blog', '--event' => 'PostPublished']))->toBe(0)
        ->and(pintChanges($this, ['Listeners/SendNotice.php', 'Listeners/LogNotice.php']))->toBe([]);
})->with('namespaces');

it('generates a Create Action and its Input Pint-clean, authorizing against its model and not', function (string $namespace) {
    blogModule($this, $namespace);

    expect(Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]))->toBe(0)
        ->and(Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => 'title:string,author_id:foreignId:constrained(users):context']))->toBe(0)
        ->and(Artisan::call('module:action', ['name' => 'Posts/CreateTag', '--module' => 'Blog', '--schema' => 'label:string:unique']))->toBe(0)
        ->and(pintChanges($this, ['Actions/CreatePostAction.php', 'Actions/Posts/CreateTagAction.php', 'Data/CreatePostInput.php', 'Data/Posts/CreateTagInput.php']))->toBe([]);
})->with('namespaces');
