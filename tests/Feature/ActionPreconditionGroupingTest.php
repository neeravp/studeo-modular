<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Process\Process;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);
    blogModule($this, 'Studeo');
    $this->module = (object) ['path' => $this->rootModulesPath.'/Blog'];
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

/**
 * @return array<int, string> alternating foreign keys (odd) and unique strings (even)
 */
function groupingMixedFields(int $count): array
{
    return array_map(fn (int $number): string => $number % 2 === 0 ? "code{$number}:string:unique" : "ref{$number}_id:foreignId:constrained(users)", range(1, $count));
}

function groupingCreatePostAction(object $test): string
{
    return File::get($test->module->path.'/Actions/CreatePostAction.php');
}

function groupingMethodBody(string $source, string $method): string
{
    preg_match('/function '.$method.'\(.*?\n    }\n/s', $source, $match);

    return $match[0] ?? '';
}

it('keeps the guard calls in the closure with eight checks', function () {
    expect(Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => implode(',', groupingMixedFields(8))]))->toBe(0);
    $source = groupingCreatePostAction($this);

    expect($source)->not->toContain('ensureUniqueValues')->not->toContain('ensureReferencesExist')
        ->and(substr_count($source, '$this->ensure'))->toBe(8)
        ->and(substr_count(groupingMethodBody($source, '__invoke'), '$this->ensure'))->toBe(8);
});

it('groups nine mixed checks by kind, in field order, and passes module:check', function () {
    expect(Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => implode(',', groupingMixedFields(9))]))->toBe(0);
    $source = groupingCreatePostAction($this);

    expect(groupingMethodBody($source, '__invoke'))->toContain('$this->ensureUniqueValues($input);')->toContain('$this->ensureReferencesExist($input);')->not->toContain('ensureCode')->not->toContain('ensureRef1')
        ->and(groupingMethodBody($source, 'ensureUniqueValues'))->toMatch('/Code2IsUnique.*Code4IsUnique.*Code6IsUnique.*Code8IsUnique/s')->not->toContain('Exists(')
        ->and(groupingMethodBody($source, 'ensureReferencesExist'))->toMatch('/Ref1IdExists.*Ref3IdExists.*Ref5IdExists.*Ref7IdExists.*Ref9IdExists/s')->not->toContain('IsUnique(')
        ->and(strpos($source, '$this->ensureUniqueValues'))->toBeLessThan(strpos($source, '$this->ensureReferencesExist'));

    Artisan::call('module:check', ['module' => 'Blog', '--format' => 'json']);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);
});

it('gives a field that is both unique and a foreign key one guard in each group', function () {
    $fields = [...groupingMixedFields(8), 'owner_id:foreignId:unique:constrained(users)'];

    expect(Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => implode(',', $fields)]))->toBe(0);
    $source = groupingCreatePostAction($this);

    expect(groupingMethodBody($source, 'ensureUniqueValues'))->toContain('$this->ensureOwnerIdIsUnique($input);')
        ->and(groupingMethodBody($source, 'ensureReferencesExist'))->toContain('$this->ensureOwnerIdExists($input);')
        ->and(substr_count($source, 'private function ensureOwnerId'))->toBe(2);
});

function groupingNineOfAKind(string $kind): string
{
    return implode(',', array_map(fn (int $number): string => $kind === 'unique' ? "code{$number}:string:unique" : "ref{$number}_id:foreignId:constrained(users)", range(1, 9)));
}

function groupingModuleFiles(object $test): array
{
    return collect(File::allFiles($test->module->path))->map->getPathname()->sort()->values()->all();
}

it('refuses nine checks of one kind through module:action, naming the count and kind, and writes nothing', function (string $kind, string $label) {
    $before = groupingModuleFiles($this);

    $exit = Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => groupingNineOfAKind($kind)]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("9 {$label} checks")->toContain('Split the schema')->toContain('by hand')
        ->and(groupingModuleFiles($this))->toBe($before);
})->with([['unique', 'unique'], ['foreign key', 'foreign key']]);

it('refuses nine checks of one kind through a Livewire form and a Vue form, and writes nothing', function () {
    fakeInertiaVueInstalled();
    $before = groupingModuleFiles($this);

    $livewire = Artisan::call('module:livewire', ['name' => 'PostEditor', '--module' => 'Blog', '--sfc' => true, '--schema' => groupingNineOfAKind('unique'), '--action' => 'CreatePost']);
    $livewireOutput = Artisan::output();
    $vue = Artisan::call('module:vue', ['name' => 'Posts/Create', '--module' => 'Blog', '--schema' => groupingNineOfAKind('foreign key'), '--action' => 'CreatePost', '--url' => '/posts']);

    expect($livewire)->toBe(1)->and($livewireOutput)->toContain('9 unique checks')
        ->and($vue)->toBe(1)->and(Artisan::output())->toContain('9 foreign key checks')
        ->and(groupingModuleFiles($this))->toBe($before);
});

it('refuses through the controller path', function () {
    $before = groupingModuleFiles($this);

    $exit = Artisan::call('module:controller', ['--module' => 'Blog', '--action' => 'CreatePost', '--schema' => groupingNineOfAKind('unique')]);

    expect($exit)->toBe(1)->and(groupingModuleFiles($this))->toBe($before);
});

it('does not refuse a form that reuses an existing Action', function () {
    fakeInertiaVueInstalled();
    Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--schema' => 'title:string']);

    expect(Artisan::call('module:livewire', ['name' => 'PostEditor', '--module' => 'Blog', '--sfc' => true, '--schema' => groupingNineOfAKind('unique'), '--action' => 'CreatePost']))->toBe(0);
});

it('runs the grouped guards: duplicates and missing references raise ValidationException keyed by the column', function () {
    Artisan::call('module:model', ['name' => 'Post', '--module' => 'Blog', '--no-seeder' => true, '--no-factory' => true, '--schema' => implode(',', groupingMixedFields(9))]);
    expect(Artisan::call('module:action', ['name' => 'CreatePost', '--module' => 'Blog', '--system' => true, '--schema' => implode(',', groupingMixedFields(9))]))->toBe(0);

    $script = <<<'PHP'
        require $argv[1];
        foreach (['Data/CreatePostInput.php', 'Actions/CreatePostAction.php'] as $file) {
            require $argv[2].'/'.$file;
        }
        $capsule = new Illuminate\Database\Capsule\Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $app = new Illuminate\Container\Container;
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('db.schema', $capsule->schema());
        $app->instance('validator', new Illuminate\Validation\Factory(new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en')));
        $capsule->schema()->create('users', fn ($t) => $t->id());
        $capsule->table('users')->insert(['id' => 1]);
        (require glob($argv[2].'/database/migrations/*_create_posts_table.php')[0])->up();
        $valid = ['ref1_id' => 1, 'code2' => 'a', 'ref3_id' => 1, 'code4' => 'b', 'ref5_id' => 1, 'code6' => 'c', 'ref7_id' => 1, 'code8' => 'd', 'ref9_id' => 1];
        $run = function (array $payload) use ($valid) {
            try {
                (new Studeo\Blog\Actions\CreatePostAction)(Studeo\Blog\Data\CreatePostInput::fromValidated([...$valid, ...$payload]));
            } catch (Illuminate\Validation\ValidationException $exception) {
                return array_keys($exception->errors());
            }

            return 'ok';
        };
        $first = $run([]);
        $capsule->table('posts')->insert($valid);
        echo json_encode(['first' => $first, 'duplicate' => $run([]), 'missing' => $run(['code2' => 'z', 'code4' => 'y', 'code6' => 'x', 'code8' => 'w', 'ref7_id' => 99]), 'fresh' => $run(['code2' => 'z', 'code4' => 'y', 'code6' => 'x', 'code8' => 'w'])]);
        PHP;
    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $this->module->path]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result['first'])->toBe('ok')
        ->and($result['duplicate'])->toBe(['code2'])
        ->and($result['missing'])->toBe(['ref7_id'])
        ->and($result['fresh'])->toBe('ok');
});
