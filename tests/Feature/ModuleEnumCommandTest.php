<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\ResolveEnumCasesAction;
use Studeo\Modular\Console\ModuleEnumCommand;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->rootAppBefore = $this->rootAppSnapshot();
    $this->file = $this->module->path.'/Enums/PostStatus.php';
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function enumCommand(array $arguments): array
{
    $exitCode = Artisan::call('module:enum', ['--module' => 'Blog', ...$arguments]);

    return [$exitCode, Artisan::output()];
}

it('writes a string-backed enum with one case per value and nothing else', function () {
    [$exitCode, $output] = enumCommand(['name' => 'PostStatus', '--cases' => 'draft, in_review,published']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Enum [PostStatus] created successfully in module [Blog].')
        ->and(File::get($this->file))->toBe("<?php\n\nnamespace Studeo\\Blog\\Enums;\n\nenum PostStatus: string\n{\n    case Draft = 'draft';\n    case InReview = 'in_review';\n    case Published = 'published';\n}\n")
        ->and($this->rootAppSnapshot())->toBe($this->rootAppBefore);
});

it('normalises the name like the other generators', function (string $given) {
    expect(enumCommand(['name' => $given, '--cases' => 'draft'])[0])->toBe(0)->and($this->file)->toBeFile();
})->with(['spaced' => 'post status', 'camel' => 'postStatus', 'snake' => 'post_status', 'already studly' => 'PostStatus']);

it('refuses a nested name, because a schema reference resolves to Enums/{Name}.php', function (string $given) {
    [$exitCode, $output] = enumCommand(['name' => $given, '--cases' => 'draft']);

    expect($exitCode)->toBe(1)->and($output)->toContain('no subfolders')->and(File::exists($this->module->path.'/Enums'))->toBeFalse();
})->with(['slash' => 'Posts/PostStatus', 'backslash' => 'Posts\\PostStatus']);

it('refuses a name that is not an identifier', function (string $given) {
    expect(enumCommand(['name' => $given, '--cases' => 'draft'])[0])->toBe(1)->and(File::exists($this->module->path.'/Enums'))->toBeFalse();
})->with(['digit first' => '1Status', 'punctuation' => 'Status!']);

it('explains each case error, lists the valid form and writes nothing', function (string $cases, string $reason) {
    [$exitCode, $output] = enumCommand(['name' => 'PostStatus', '--cases' => $cases]);

    expect($exitCode)->toBe(1)->and($output)->toContain($reason)->toContain('Valid: lower snake case')->toContain('--cases=draft,published')
        ->and(File::exists($this->module->path.'/Enums'))->toBeFalse();
})->with([
    'empty list' => [',', 'no values'],
    'upper case' => ['Draft', '[Draft]'],
    'leading digit' => ['1draft', '[1draft]'],
    'space inside' => ['in review', '[in review]'],
    'too long' => [str_repeat('a', 31), 'at most 30'],
    'duplicate value' => ['draft,draft', 'twice'],
    'duplicate case name' => ['a_b,a__b', 'same case name'],
    'reserved name' => ['class', 'class'],
]);

it('asks for the cases when they are missing and the terminal is interactive', function () {
    $this->artisan('module:enum', ['name' => 'PostStatus', '--module' => 'Blog'])
        ->expectsQuestion('What are the cases?', 'draft,published')
        ->expectsOutputToContain('Enum [PostStatus] created successfully')
        ->assertSuccessful();

    expect(File::get($this->file))->toContain("case Published = 'published';");
});

it('fails with an example when the cases are missing and the command is not interactive', function () {
    [$exitCode, $output] = enumCommand(['name' => 'PostStatus', '--no-interaction' => true]);

    expect($exitCode)->toBe(1)->and($output)->toContain('--cases=draft,published')->and($this->file)->not->toBeFile();
});

it('never overwrites an existing enum', function () {
    enumCommand(['name' => 'PostStatus', '--cases' => 'draft']);
    $before = File::get($this->file);

    [$exitCode, $output] = enumCommand(['name' => 'PostStatus', '--cases' => 'other']);

    expect($exitCode)->toBe(1)->and($output)->toContain('already exists')->and(File::get($this->file))->toBe($before);
});

it('fails for a module that does not exist', function () {
    expect(Artisan::call('module:enum', ['name' => 'PostStatus', '--module' => 'Ghost', '--cases' => 'draft']))->toBe(1);
});

it('gives the prompt the same messages as the option path, and accepts a valid answer', function (string $answer) {
    $command = app(ModuleEnumCommand::class);
    $cases = app(ResolveEnumCasesAction::class);
    $validator = (new ReflectionMethod($command, 'promptValidator'))->invoke($command, $cases->execute(...));

    $expected = null;

    try {
        $cases->execute($answer);
    } catch (RuntimeException $exception) {
        $expected = $exception->getMessage();
    }

    expect($validator($answer))->toBe($expected);
})->with(['invalid value' => 'Draft', 'duplicate' => 'draft,draft', 'empty list' => ',', 'valid' => 'draft,published']);

it('has a prompt validator that really rejects the invalid answers', function () {
    $command = app(ModuleEnumCommand::class);
    $validator = (new ReflectionMethod($command, 'promptValidator'))->invoke($command, app(ResolveEnumCasesAction::class)->execute(...));

    expect($validator('Draft'))->toContain('[Draft]')->and($validator('draft,draft'))->toContain('twice')->and($validator(','))->toContain('no values')->and($validator('draft,published'))->toBeNull();
});
