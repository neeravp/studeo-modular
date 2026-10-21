<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * The Blog module's generators, one entry per generator and each with the commands that exercise
 * every variant of it and the module paths they write: a trailing slash is a directory (its PHP
 * files), anything else is one file, where a .blade.php file is checked as its PHP block.
 *
 * @return array<string, array{0: array<int, array{0: string, 1: array<string, mixed>}>, 1: array<int, string>}>
 */
function generatorCases(): array
{
    $model = ['module:model', ['name' => 'Post', '--no-migration' => true, '--no-factory' => true, '--no-seeder' => true]];

    return [
        'module scaffold' => [[], ['BlogServiceProvider.php', 'config/blog.php', 'routes/web.php']],
        'actions without a schema, system and nested' => [[['module:action', ['name' => 'Ping']], ['module:action', ['name' => 'Sweep', '--system' => true]], ['module:action', ['name' => 'Posts/Nested']], ['module:action', ['name' => 'Posts/NestedSys', '--system' => true]]], ['Actions/', 'Data/']],
        'action with a schema and no checks' => [[['module:action', ['name' => 'CreateThing', '--schema' => 'title:string']]], ['Actions/', 'Data/']],
        'action with three unique or foreign-key checks' => [[['module:model', ['name' => 'Post', '--no-factory' => true, '--no-seeder' => true, '--schema' => 'slug:string:unique,author_id:foreignId:constrained(users),handle:string:nullable:unique']], ['module:action', ['name' => 'CreatePost', '--schema' => 'slug:string:unique,author_id:foreignId:constrained(users),handle:string:nullable:unique']]], ['Actions/', 'Data/']],
        'action with nine grouped checks' => [[['module:action', ['name' => 'CreateGrouped', '--schema' => 'ref1_id:foreignId:constrained(users),code2:string:unique,ref3_id:foreignId:constrained(users),code4:string:unique,ref5_id:foreignId:constrained(users),code6:string:unique,ref7_id:foreignId:constrained(users),code8:string:unique,ref9_id:foreignId:nullable:constrained(users)']]], ['Actions/', 'Data/']],
        'controllers of every kind' => [[$model, ['module:controller', ['name' => 'Status']], ['module:controller', ['name' => 'Article', '--resource' => true]], ['module:controller', ['name' => 'Post', '--resource' => true, '--model' => 'Post']], ['module:controller', ['name' => 'Feed', '--api' => true, '--model' => 'Post']], ['module:controller', ['name' => 'Health', '--standard' => true]], ['module:controller', ['name' => 'Home', '--inertia' => true]], ['module:controller', ['name' => 'Page', '--resource' => true, '--inertia' => true]]], ['Controllers/']],
        'Action controllers' => [[['module:action', ['name' => 'CreatePost', '--schema' => 'title:string,author_id:foreignId:constrained(users):context']], ['module:action', ['name' => 'Posts/PublishPost', '--schema' => 'note:string']], ['module:controller', ['--action' => 'CreatePost', '--context' => ['author_id=actor']]], ['module:controller', ['--action' => 'Posts/PublishPost']]], ['Controllers/', 'Actions/', 'Data/']],
        'Livewire classic components' => [[['module:livewire', ['name' => 'Plain', '--classic' => true]], ['module:livewire', ['name' => 'Nested/Deep', '--classic' => true]], ['module:livewire', ['name' => 'Inline', '--inline' => true]], ['module:livewire', ['name' => 'Paged', '--page' => true]]], ['Livewire/', 'tests/']],
        'Livewire multi-file components' => [[['module:livewire', ['name' => 'Plain', '--mfc' => true]], ['module:livewire', ['name' => 'Assets', '--mfc' => true, '--assets' => true]], ['module:livewire', ['name' => 'Paged', '--mfc' => true, '--page' => true]]], ['Livewire/', 'tests/']],
        'Livewire single-file components' => [[['module:livewire', ['name' => 'Plain', '--sfc' => true]], ['module:livewire', ['name' => 'Paged', '--sfc' => true, '--page' => true]]], ['Livewire/⚡plain.blade.php', 'Livewire/Pages/⚡paged.blade.php', 'tests/']],
        'Livewire single-file form' => [[['module:livewire', ['name' => 'Note', '--schema' => 'title:string', '--action' => 'CreateNote']]], ['Livewire/⚡note.blade.php', 'Livewire/Forms/', 'Actions/', 'Data/']],
        'mail' => [[['module:mail', ['name' => 'Plain']], ['module:mail', ['name' => 'Queued', '--queued' => true]], ['module:mail', ['name' => 'Marked', '--markdown' => 'mail.marked']], ['module:mail', ['name' => 'Viewed', '--view' => 'mail.viewed']]], ['Mail/']],
        'notification' => [[['module:notification', ['name' => 'Plain']], ['module:notification', ['name' => 'Queued', '--queued' => true]], ['module:notification', ['name' => 'Marked', '--markdown' => 'mail.marked']]], ['Notifications/']],
        'middleware' => [[['module:middleware', ['name' => 'EnsureActive']]], ['Middleware/']],
        'data objects' => [[['module:dto', ['name' => 'FooInput']], ['module:dto', ['name' => 'FooData']], ['module:data', ['name' => 'BarInput', '--schema' => 'title:string,n:integer:nullable']], ['module:dto', ['name' => 'BarData', '--schema' => 'title:string,n:integer:nullable']]], ['Data/']],
        'enum' => [[['module:enum', ['name' => 'Status', '--cases' => 'draft,published']]], ['Enums/']],
        'event' => [[['module:event', ['name' => 'Happened']]], ['Events/']],
        'jobs' => [[['module:job', ['name' => 'Queued']], ['module:job', ['name' => 'Synced', '--sync' => true]], ['module:job', ['name' => 'Batched', '--batched' => true]]], ['Jobs/']],
        'listeners' => [[['module:event', ['name' => 'Happened']], ['module:listener', ['name' => 'Bare']], ['module:listener', ['name' => 'Plain', '--event' => 'Happened']], ['module:listener', ['name' => 'Queued', '--event' => 'Happened', '--queued' => true]]], ['Listeners/', 'Events/']],
        'rules' => [[['module:rule', ['name' => 'Plain']], ['module:rule', ['name' => 'Implicit', '--implicit' => true]]], ['Rules/']],
        'commands' => [[['module:command', ['name' => 'DoIt']], ['module:command', ['name' => 'DoMore', '--command' => 'blog:more']]], ['Commands/']],
        'API resources' => [[['module:resource', ['name' => 'PostResource']], ['module:resource', ['name' => 'PostCollection', '--collection' => true]]], ['ApiResources/']],
        'pivot models' => [[['module:model', ['name' => 'Tag', '--no-migration' => true]], $model, ['module:pivot', ['model1' => 'Post', 'model2' => 'Tag']], ['module:pivot', ['model1' => 'Post', 'model2' => 'Cat', '--morph' => true]]], ['Models/', 'database/']],
        'policies, plain and for a model' => [[$model, ['module:policy', ['name' => 'GeneralPolicy']], ['module:policy', ['name' => 'PostPolicy', '--model' => 'Post']]], ['Policies/', 'Models/']],
        'model with its migration, factory and seeder' => [[['module:model', ['name' => 'Post']], ['module:model', ['name' => 'Note', '--no-factory' => true]], ['module:model', ['name' => 'Bare', '--no-migration' => true, '--no-seeder' => true]]], ['Models/', 'database/']],
        'model from a schema' => [[['module:enum', ['name' => 'PostStatus', '--cases' => 'draft,published']], ['module:model', ['name' => 'Category', '--no-migration' => true, '--no-seeder' => true]], ['module:model', ['name' => 'Post', '--schema' => 'title:string:unique,price:decimal(8,2),status:enum(PostStatus):default(draft),meta:json:nullable,author_id:foreignId:constrained(users),author:belongsTo(User):author_id,category_id:foreignId:constrained,category:belongsTo(Category)']]], ['Models/', 'Enums/', 'database/']],
        'standalone factories' => [[$model, ['module:factory', ['name' => 'PostFactory']], ['module:factory', ['name' => 'DraftFactory', '--model' => 'Post']], ['module:factory', ['name' => 'GhostFactory', '--model' => 'Ghost']]], ['database/factories/']],
        'standalone seeders' => [[$model, ['module:seeder', ['name' => 'TagSeeder']], ['module:seeder', ['name' => 'PostSeeder', '--model' => 'Post']], ['module:factory', ['name' => 'PostFactory', '--model' => 'Post']], ['module:seeder', ['name' => 'PublishedPostSeeder', '--model' => 'Post']], ['module:seeder', ['name' => 'CommentSeeder', '--model' => 'Comment']]], ['database/seeders/']],
        'standalone migrations' => [[['module:migration', ['name' => 'create_tags_table', '--create' => 'tags']], ['module:migration', ['name' => 'add_status_to_posts_table', '--table' => 'posts']], ['module:migration', ['name' => 'tidy_up', '--blank' => true]], ['module:migration', ['name' => 'create_flight_passenger_table', '--pivot' => true]], ['module:migration', ['name' => 'create_post_tag_table', '--pivot' => true, '--morph' => true]]], ['database/migrations/']],
        'Livewire full-page layout and title' => [[['module:livewire', ['name' => 'Classic', '--classic' => true, '--page' => true, '--layout' => 'layouts.shell', '--title' => 'A title']], ['module:livewire', ['name' => 'Multi', '--mfc' => true, '--page' => true, '--layout' => 'layouts.shell', '--title' => 'A title']], ['module:livewire', ['name' => 'Single', '--sfc' => true, '--page' => true, '--layout' => 'layouts.shell', '--title' => 'A title', '--no-test' => true]]], ['Livewire/', 'Livewire/Pages/⚡single.blade.php']],
        'Vue form server parts' => [[['module:vue', ['name' => 'Create', '--form' => true, '--schema' => 'title:string', '--action' => 'CreateVue', '--url' => '/vue']]], ['Actions/', 'Data/']],
    ];
}

/**
 * Runs a case's commands against the Blog module.
 *
 * @param  array<int, array{0: string, 1: array<string, mixed>}>  $calls
 */
function runGenerators(array $calls): void
{
    fakeInertiaVueInstalled();

    foreach ($calls as [$command, $options]) {
        expect(Artisan::call($command, ['--module' => 'Blog', ...$options]))->toBe(0, $command.' '.Artisan::output());
    }
}

/**
 * Expands a case's paths to the files to check: a directory to its PHP files, a file to itself.
 *
 * @param  array<int, string>  $paths
 * @return array<int, string>
 */
function casePaths(object $test, array $paths): array
{
    $files = array_map(fn (string $path): array => str_ends_with($path, '/') ? modulePhpFiles($test, $path) : [$path], $paths);

    return array_merge(...$files);
}

/**
 * Lines of the given module files that are a bare `//`, say TODO or placeholder, or are Laravel's
 * sample notification copy.
 *
 * @param  array<int, string>  $files
 * @return array<int, string>
 */
function placeholderLines(object $test, array $files): array
{
    $found = [];

    foreach ($files as $file) {
        foreach (explode("\n", File::get($test->rootModulesPath.'/Blog/'.$file)) as $number => $line) {
            preg_match('~^\s*//\s*$|TODO|placeholder|introduction to the notification|Notification Action|Thank you for using our application~i', $line) && $found[] = $file.':'.($number + 1).' '.trim($line);
        }
    }

    return $found;
}
