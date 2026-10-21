<?php

use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Studeo\Modular\Console\Actions\CreateVueComponentAction;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\VueComponentOptions;
use Studeo\Modular\Enums\VueComponentType;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(usesComposer: false);
    $this->fixture->driver();
    $this->fixture->modules('Blog');
    $blog = new ModuleDefinition('Blog', 'Studeo\\Blog', $this->fixture->rootPath.'/Blog');
    app(CreateVueComponentAction::class)->execute($blog, 'Card');
    app(CreateVueComponentAction::class)->execute($blog, 'Card', new VueComponentOptions(type: VueComponentType::Page));

    $this->filesUnder = function (string $base, string $relative): array {
        $path = $base.'/'.$relative;

        if (! is_dir($path)) {
            return [];
        }

        $found = [];

        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $found = [...$found, ...(is_dir($path.'/'.$entry) ? ($this->filesUnder)($base, $relative.'/'.$entry) : [$relative.'/'.$entry])];
        }

        return $found;
    };
    $this->vueFiles = fn (): array => [...($this->filesUnder)($blog->path.'/resources/js', 'Components'), ...($this->filesUnder)($blog->path.'/resources/js', 'Pages')];
});

afterEach(function () {
    $this->fixture->tearDown();
    $prompt = new ReflectionClass(Prompt::class);
    $prompt->getProperty('terminal')->setValue(null, new Terminal);
});

dataset('same base name', [
    'delete, select the component' => ['module:vue:delete', ['--module' => 'Blog', '--force' => true], 'Components/Card', ['Pages/Card.vue']],
    'delete, select the page' => ['module:vue:delete', ['--module' => 'Blog', '--force' => true], 'Pages/Card', ['Components/Card.vue']],
    'delete, name with the Components/ prefix' => ['module:vue:delete', ['name' => 'Components/Card', '--module' => 'Blog', '--force' => true], null, ['Pages/Card.vue']],
    'delete, bare name' => ['module:vue:delete', ['name' => 'Card', '--module' => 'Blog', '--force' => true], null, ['Components/Card.vue']],
    'delete, bare name with --page' => ['module:vue:delete', ['name' => 'Card', '--module' => 'Blog', '--page' => true, '--force' => true], null, ['Components/Card.vue']],
    'delete, name with the Pages/ prefix' => ['module:vue:delete', ['name' => 'Pages/Card', '--module' => 'Blog', '--force' => true], null, ['Components/Card.vue']],
    'move, select the component' => ['module:vue:move', ['new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], 'Components/Card', ['Components/Renamed.vue', 'Pages/Card.vue']],
    'move, select the page' => ['module:vue:move', ['new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], 'Pages/Card', ['Components/Card.vue', 'Pages/Renamed.vue']],
    'move, name with the Components/ prefix' => ['module:vue:move', ['name' => 'Components/Card', 'new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], null, ['Components/Renamed.vue', 'Pages/Card.vue']],
    'move, bare name' => ['module:vue:move', ['name' => 'Card', 'new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], null, ['Components/Card.vue', 'Pages/Renamed.vue']],
    'move, bare name with --page' => ['module:vue:move', ['name' => 'Card', 'new-name' => 'Renamed', '--module' => 'Blog', '--page' => true, '--force' => true], null, ['Components/Card.vue', 'Pages/Renamed.vue']],
    'move, name with the Pages/ prefix' => ['module:vue:move', ['name' => 'Pages/Card', 'new-name' => 'Renamed', '--module' => 'Blog', '--force' => true], null, ['Components/Card.vue', 'Pages/Renamed.vue']],
    'move the page to a target name with the Components/ prefix' => ['module:vue:move', ['name' => 'Card', 'new-name' => 'Components/Renamed', '--module' => 'Blog', '--force' => true], null, ['Components/Card.vue', 'Pages/Components/Renamed.vue']],
    'move the component to a target name with the Pages/ prefix' => ['module:vue:move', ['name' => 'Components/Card', 'new-name' => 'Pages/Renamed', '--module' => 'Blog', '--force' => true], null, ['Components/Pages/Renamed.vue', 'Pages/Card.vue']],
]);

it('acts on the file that was chosen when a component and a page share a base name', function (string $command, array $input, ?string $answer, array $remaining) {
    $call = $this->artisan($command, $input);

    if ($answer !== null) {
        $call->expectsQuestion(str_contains($command, 'delete') ? 'Which Vue component would you like to delete from [Blog]?' : 'Which Vue component would you like to move from [Blog]?', $answer);
    }

    $call->assertExitCode(0)->run();

    expect(($this->vueFiles)())->toBe($remaining);
})->with('same base name');
