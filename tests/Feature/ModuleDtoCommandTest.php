<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->blogModule = new ModuleDefinition(
        name: 'Blog',
        namespace: 'Studeo\\Blog',
        path: $this->rootModulesPath.'/Blog',
    );

    app(CreateModuleAction::class)->execute($this->blogModule);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('creates a DTO with name argument successfully', function () {
    $this->artisan('module:dto', [
        'name' => 'PostData',
        '--module' => 'Blog',
    ])
        ->expectsOutput('DTO [PostData] created successfully in module [Blog].')
        ->assertSuccessful();

    $path = $this->blogModule->path.'/Data/PostData.php';
    expect($path)->toBeFile();

    $content = File::get($path);
    expect($content)
        ->toContain('namespace Studeo\Blog\Data;')
        ->toContain('use Studeo\Modular\Support\OutputData;')
        ->toContain('final readonly class PostData extends OutputData')
        ->toContain('public function __construct(');
});

it('creates a DTO via module:data alias', function () {
    $this->artisan('module:data', [
        'name' => 'UserData',
        '--module' => 'Blog',
    ])
        ->expectsOutput('DTO [UserData] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Data/UserData.php')->toBeFile();
});

it('prompts for DTO name when omitted', function () {
    $this->artisan('module:dto', [
        '--module' => 'Blog',
    ])
        ->expectsQuestion('What should the DTO be named?', 'TagData')
        ->expectsOutput('DTO [TagData] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Data/TagData.php')->toBeFile();
});

it('prompts for module when --module is omitted', function () {
    $this->writeModuleManifest($this->rootModulesPath.'/Zeta', 'Zeta', 'Studeo\\Zeta');

    $this->artisan('module:dto', [
        'name' => 'CategoryData',
    ])
        ->expectsChoice('Which module should this belong to?', 'Blog', ['Blog', 'Zeta'])
        ->expectsOutput('DTO [CategoryData] created successfully in module [Blog].')
        ->assertSuccessful();

    expect($this->blogModule->path.'/Data/CategoryData.php')->toBeFile();
});

it('fails when module does not exist', function () {
    $this->artisan('module:dto', [
        'name' => 'GhostData',
        '--module' => 'NonExistent',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});

it('fails when DTO already exists', function () {
    $this->artisan('module:dto', [
        'name' => 'DuplicateData',
        '--module' => 'Blog',
    ])->assertSuccessful();

    $this->artisan('module:dto', [
        'name' => 'DuplicateData',
        '--module' => 'Blog',
    ])
        ->expectsOutputToContain('DTO [DuplicateData] already exists in module [Blog].')
        ->assertFailed();
});

it('decides the type by suffix: Input is an InputData, Data is an OutputData', function (string $name, string $parent) {
    $this->artisan('module:dto', ['name' => $name, '--module' => 'Blog'])->assertSuccessful();

    expect(File::get($this->blogModule->path."/Data/{$name}.php"))
        ->toContain('final readonly class '.basename($name)." extends {$parent}");
})->with([
    'input' => ['PostInput', 'InputData'],
    'nested input' => ['Comments/CreateCommentInput', 'InputData'],
    'output' => ['PostData', 'OutputData'],
]);

it('fails a bare name, listing the valid forms with examples, and writes nothing', function (string $name) {
    $before = File::allFiles($this->blogModule->path);

    $exitCode = Artisan::call('module:dto', ['name' => $name, '--module' => 'Blog']);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())
        ->toContain('must end in Input or Data')
        ->toContain('CreatePostInput')
        ->toContain('PostData');

    expect(File::allFiles($this->blogModule->path))->toEqual($before);
})->with(['Post', 'UserDto', 'Input', 'Data', 'Comments/Post']);

it('never appends a suffix to a name', function () {
    $this->artisan('module:dto', ['name' => 'Post', '--module' => 'Blog'])->assertFailed();

    expect(File::exists($this->blogModule->path.'/Data/PostData.php'))->toBeFalse()
        ->and(File::exists($this->blogModule->path.'/Data/PostInput.php'))->toBeFalse();
});

it('has no --type option', function () {
    expect(fn () => $this->artisan('module:dto', ['name' => 'PostData', '--module' => 'Blog', '--type' => 'input'])->run())
        ->toThrow(InvalidOptionException::class, '--type');

    expect($this->blogModule->path.'/Data/PostData.php')->not->toBeFile();
});

it('normalises casing and spacing before checking the suffix', function (string $given, string $file, string $parent) {
    $this->artisan('module:dto', ['name' => $given, '--module' => 'Blog'])->assertSuccessful();

    expect(File::get($this->blogModule->path."/Data/{$file}.php"))
        ->toContain('extends '.$parent);
})->with([
    'lower input' => ['create post input', 'CreatePostInput', 'InputData'],
    'snake data' => ['post_data', 'PostData', 'OutputData'],
    'kebab nested' => ['comments/comment-data', 'Comments/CommentData', 'OutputData'],
]);

it('rejects a lower-case suffix that normalising does not repair', function () {
    $this->artisan('module:dto', ['name' => 'POSTDATA', '--module' => 'Blog'])
        ->expectsOutputToContain('must end in Input or Data')
        ->assertFailed();
});

it('fails with a clear message when the name is missing and the command is not interactive', function () {
    $exitCode = Artisan::call('module:dto', ['--module' => 'Blog', '--no-interaction' => true]);

    expect($exitCode)->not->toBe(0)
        ->and(Artisan::output())->toContain('name is required')->toContain('must end in Input or Data');

    expect(File::allFiles($this->blogModule->path.'/Data'))->toBe([]);
});

it('applies the same suffix rule to a prompted name', function () {
    $this->artisan('module:dto', ['--module' => 'Blog'])
        ->expectsQuestion('What should the DTO be named?', 'Tag')
        ->expectsOutputToContain('must end in Input or Data')
        ->assertFailed();

    $this->artisan('module:dto', ['--module' => 'Blog'])
        ->expectsQuestion('What should the DTO be named?', 'create tag input')
        ->expectsOutput('DTO [CreateTagInput] created successfully in module [Blog].')
        ->assertSuccessful();
});

it('writes only inside the module directory', function () {
    $before = $this->rootAppSnapshot();

    $this->artisan('module:dto', ['name' => 'PostInput', '--module' => 'Blog'])->assertSuccessful();
    $this->artisan('module:dto', ['name' => 'PostData', '--module' => 'Blog'])->assertSuccessful();

    expect($this->rootAppSnapshot())->toBe($before)
        ->and(array_map(fn ($file) => $file->getFilename(), File::allFiles($this->blogModule->path.'/Data')))
        ->toEqualCanonicalizing(['PostInput.php', 'PostData.php']);
});
