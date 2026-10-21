<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

uses(TestCase::class);

beforeEach(function () {
    $this->rootPath = config('modular.root_path');
    File::deleteDirectory($this->rootPath);
    File::ensureDirectoryExists($this->rootPath);
    app(CreateModuleAction::class)->execute(new ModuleDefinition('Blog', 'Studeo\\Blog', $this->rootPath.'/Blog'));
    $this->skeleton = skeletonFrontendSnapshot();
    Process::fake();
    fakeLivewireInstalled();
    fakeInertiaVueInstalled();

    $this->run = function (string $command, array $input): array {
        $tester = new CommandTester(Artisan::all()[$command]);
        $status = $tester->execute(['--module' => 'Blog', ...$input], ['interactive' => false, 'capture_stderr_separately' => true]);

        return [$status, $tester->getDisplay()];
    };
    $this->written = fn (string $path): string => File::get($this->rootPath.'/Blog/'.$path);
});

afterEach(function () {
    expect(skeletonFrontendSnapshot())->toBe($this->skeleton);
    File::deleteDirectory($this->rootPath);
    cleanInstallerRoots();
});

it('keeps writing the names that a class cannot carry but a file or a view can', function (string $command, array $input, string $pattern) {
    [$status] = ($this->run)($command, $input);

    expect($status)->toBe(0)->and(glob($this->rootPath.'/Blog/'.$pattern))->toHaveCount(1);
})->with([
    'a Vue page named 404' => ['module:vue', ['name' => '404', '--page' => true], 'resources/js/Pages/404.vue'],
    'a Vue component named 0' => ['module:vue', ['name' => '0'], 'resources/js/Components/0.vue'],
    'a Vue page named 0' => ['module:vue', ['name' => '0', '--page' => true], 'resources/js/Pages/0.vue'],
    'a single-file Livewire component named 404' => ['module:livewire', ['name' => '404', '--sfc' => true], 'Livewire/⚡404.blade.php'],
    'a single-file Livewire component named 0' => ['module:livewire', ['name' => '0', '--sfc' => true], 'Livewire/⚡0.blade.php'],
    'a multi-file Livewire component named 0' => ['module:livewire', ['name' => '0', '--mfc' => true], 'Livewire/⚡0/0.php'],
    'a migration named 2fa_codes' => ['module:migration', ['name' => '2fa_codes'], 'database/migrations/*_2fa_codes.php'],
    'a migration named create_2fa_codes_table' => ['module:migration', ['name' => 'create_2fa_codes_table'], 'database/migrations/*_create_2fa_codes_table.php'],
    'a migration named 0' => ['module:migration', ['name' => '0'], 'database/migrations/*_0.php'],
    'a mail view named 404' => ['module:mail', ['name' => 'Welcome', '--view' => '404'], 'resources/views/404.blade.php'],
    'a mail view named 0' => ['module:mail', ['name' => 'Welcome', '--view' => '0'], 'resources/views/0.blade.php'],
    'a mail markdown view named 0' => ['module:mail', ['name' => 'Welcome', '--markdown' => '0'], 'resources/views/0.blade.php'],
    'a notification markdown view named 0' => ['module:notification', ['name' => 'Welcome', '--markdown' => '0'], 'resources/views/0.blade.php'],
]);

it('passes an option value of 0 on instead of dropping it', function (string $command, array $input, int $status, string $output, ?string $pattern, ?string $content) {
    File::put($this->rootPath.'/Blog/database/factories/0Factory.php', "<?php\n");
    File::ensureDirectoryExists($this->rootPath.'/Blog/schema');
    File::put($this->rootPath.'/Blog/schema/posts.yaml', "table: posts\nfields:\n  title: string\n");
    File::put($this->rootPath.'/Blog/schema/comments.yaml', "table: comments\nfields:\n  body: string\nrelationships:\n  post:\n    type: belongsTo\n    model: Post\n    foreign_key: '0'\n");

    [$actual, $display] = ($this->run)($command, $input);
    $written = $pattern === null ? [] : glob($this->rootPath.'/Blog/'.$pattern);

    expect($actual)->toBe($status)
        ->and($display)->toContain($output)
        ->and($written)->toHaveCount($pattern === null ? 0 : 1)
        ->and($pattern === null || str_contains(File::get($written[0]), $content))->toBeTrue();
})->with([
    'the pivot table' => ['module:pivot', ['model1' => 'Flight', 'model2' => 'Passenger', '--table' => '0'], 1, 'Cannot determine pivot table name for migration [create_0_table].', null, null],
    'the migration --create' => ['module:migration', ['name' => 'make_things', '--create' => '0'], 0, 'created successfully', 'database/migrations/*_make_things.php', "Schema::create('0'"],
    'the migration --table' => ['module:migration', ['name' => 'make_things', '--table' => '0'], 0, 'created successfully', 'database/migrations/*_make_things.php', "Schema::table('0'"],
    'the command --command' => ['module:command', ['name' => 'Welcome', '--command' => '0'], 0, 'created successfully', 'Commands/Welcome.php', "\$signature = '0';"],
    'the Livewire --title' => ['module:livewire', ['name' => 'Welcome', '--page' => true, '--title' => '0'], 0, 'created successfully', 'Livewire/Pages/Welcome.php', "Title('0')"],
    'the Livewire --schema' => ['module:livewire', ['name' => 'Welcome', '--schema' => '0'], 1, 'Invalid schema: 1 problem found.', null, null],
    'the Livewire --action' => ['module:livewire', ['name' => 'Welcome', '--schema' => '@posts', '--action' => '0'], 1, 'Invalid action name [0].', null, null],
    'the Vue --schema' => ['module:vue', ['name' => 'Welcome', '--schema' => '0'], 1, 'Invalid schema: 1 problem found.', null, null],
    'the Vue --action' => ['module:vue', ['name' => 'Welcome', '--action' => '0'], 1, 'Only a form takes --action.', null, null],
    'the Vue --url' => ['module:vue', ['name' => 'Welcome', '--url' => '0'], 1, 'Only a form takes --url.', null, null],
    'the schema check --except' => ['module:schema:check', ['--except' => '0'], 1, 'Unknown --except value [0]. Valid: model, migration, factory, dto.', null, null],
    'a relationship key in a schema' => ['module:dto', ['name' => 'CommentData', '--schema' => '@comments'], 1, 'keys must be lower snake case column names.', null, null],
]);
