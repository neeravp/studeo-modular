<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootModulesPath.'/Blog');
    app(CreateModuleAction::class)->execute($this->module);
    $this->writeEnumFixture($this->module, 'PostStatus');

    Artisan::call('module:schema', ['name' => 'Post', '--module' => 'Blog', '--fields' => 'slug:string,title:string:nullable,status:enum(PostStatus):default(draft),views:integer:default(0)']);
    Artisan::call('module:dto', ['name' => 'PostInput', '--module' => 'Blog', '--schema' => '@posts']);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

function defaultsRun(string $snippet): array
{
    $bootstrap = "require \$argv[1]; require \$argv[2].'/Enums/PostStatus.php'; require \$argv[2].'/Data/PostInput.php';\n"
        ."Illuminate\\Support\\Facades\\Facade::setFacadeApplication(\$app = new Illuminate\\Container\\Container);\n"
        .'$app->instance("validator", new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, "en")));'."\n"
        .'$class = "Studeo\Blog\Data\PostInput";';
    $process = new Process([PHP_BINARY, '-r', $bootstrap."\n".$snippet, dirname(__DIR__, 2).'/vendor/autoload.php', test()->module->path]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('makes a defaulted, non-nullable field optional in the rules, with the type rules kept', function () {
    $rules = defaultsRun('echo json_encode(array_map(fn ($rule) => array_map(fn ($r) => is_string($r) ? $r : "rule", $rule), $class::rules()));');

    expect($rules['status'])->toBe(['sometimes', 'rule'])
        ->and($rules['views'])->toBe(['sometimes', 'integer'])
        ->and($rules['title'])->toBe(['nullable', 'string', 'max:255'])
        ->and($rules['slug'])->toBe(['required', 'string', 'max:255']);
});

it('hydrates to the default enum case when the field is absent, and still rejects an invalid one', function () {
    $result = defaultsRun(<<<'PHP'
        $dto = $class::fromValidated(['slug' => 'a']);
        $invalid = [];
        try {
            $class::fromValidated(['slug' => 'a', 'status' => 'nope']);
        } catch (Illuminate\Validation\ValidationException $exception) {
            $invalid = array_keys($exception->errors());
        }
        echo json_encode(['status' => $dto->status === Studeo\Blog\Enums\PostStatus::Draft, 'views' => $dto->views, 'invalid' => $invalid]);
        PHP);

    expect($result)->toBe(['status' => true, 'views' => 0, 'invalid' => ['status']]);
});
