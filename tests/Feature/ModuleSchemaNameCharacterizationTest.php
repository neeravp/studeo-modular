<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateSchemaFileAction;
use Studeo\Modular\Tests\Support\ModulesRootFixture;
use Studeo\Modular\Tests\Support\RecordingSchemaCommand;
use Studeo\Modular\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

uses(TestCase::class);

beforeEach(function () {
    $this->fixture = new ModulesRootFixture(['resolve:CreateSchemaFileAction', 'resolve:ResolveModuleAction'], usesComposer: false);
    $this->rootPath = $this->fixture->rootPath;
    $this->fixture->modules('Account');
    $this->driver = $this->fixture->driver([CreateSchemaFileAction::class]);
    $this->prompt = "\n  What is the model name?\n❯ \n";
    $this->missing = "The model name is required when the command is not interactive, e.g. Post.\n";
    $this->created = "Schema [posts] created successfully in module [Account].\nPath: <modules>/Account/schema/posts.yaml\n";
    $this->outcome = $this->fixture->outcome(...);
    $this->recording = function (array $input, array $answers = [], bool $interactive = true): array {
        $command = app()->make(RecordingSchemaCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $tester->setInputs($answers);
        $status = $tester->execute(['--module' => 'Account', ...$input], ['interactive' => $interactive, 'capture_stderr_separately' => true]);

        return ['status' => $status, 'stdout' => $this->driver->normalise($tester->getDisplay()), 'log' => $command->log, 'command' => $command];
    };
});

afterEach(fn () => $this->fixture->tearDown());

it('uses the name it is given without asking', function () {
    expect($this->driver->run('module:schema', ['name' => 'Post', '--module' => 'Account']))->toBe(($this->outcome)(0, $this->created))
        ->and(File::get($this->rootPath.'/Account/schema/posts.yaml'))->toBe("table: posts\nfields:\n  name: string\n");
});

it('writes the fields when they are given', function () {
    $run = $this->driver->run('module:schema', ['name' => 'Post', '--module' => 'Account', '--fields' => 'title:string,slug:string:unique']);

    expect($run)->toBe(($this->outcome)(0, $this->created))
        ->and(File::get($this->rootPath.'/Account/schema/posts.yaml'))->toBe("table: posts\nfields:\n  title: string\n  slug: 'string:unique'\n");
});

it('asks for the name when it is omitted, empty or only whitespace, with or without --fields', function (array $input) {
    $run = $this->driver->run('module:schema', ['--module' => 'Account', ...$input], ['Post']);

    expect($run)->toBe(($this->outcome)(0, $this->prompt.$this->created, 5));
})->with([
    'omitted' => [[]],
    'empty' => [['name' => '']],
    'spaces' => [['name' => '   ']],
    'a tab' => [['name' => "\t"]],
    'omitted, with fields' => [['--fields' => 'title:string']],
    'spaces, with fields' => [['name' => ' ', '--fields' => 'title:string']],
]);

it('names the missing name and exits 1 when nothing can be answered', function (array $input) {
    expect($this->driver->run('module:schema', ['--module' => 'Account', ...$input], [], false))->toBe(($this->outcome)(1, $this->missing));
})->with([
    'omitted' => [[]],
    'empty' => [['name' => '']],
    'spaces' => [['name' => '   ']],
    'omitted, with fields' => [['--fields' => 'title:string']],
]);

it('fails with Required when the prompt is answered with nothing or only whitespace', function (array $answers, int $answered) {
    $run = $this->driver->run('module:schema', ['--module' => 'Account'], $answers);

    expect($run)->toBe(($this->outcome)(1, $this->prompt."   ERROR  Required.  \n\n\n", $answered))
        ->and(File::exists($this->rootPath.'/Account/schema'))->toBeFalse();
})->with([
    'nothing' => [[''], 1],
    'spaces' => [['   '], 4],
]);

it('keeps a name of 0 as a given name and lets the schema reject its table', function () {
    $run = $this->driver->run('module:schema', ['name' => '0', '--module' => 'Account']);

    expect($run)->toBe(($this->outcome)(1, "Invalid schema: 1 problem found.\n  - table: The schema table must be a lower snake case table name, e.g. blog_posts.\n"));
});

it('builds the check once, only when it prompts, and runs it on the answer', function () {
    $run = ($this->recording)([], ['Post']);

    expect($run['status'])->toBe(0)->and($run['log'])->toBe(['validator built', ['validated', 'Post']]);
});

it('never builds the check for a given name or when nothing can be answered', function (array $input, bool $interactive, int $status) {
    $run = ($this->recording)($input, [], $interactive);

    expect($run['status'])->toBe($status)->and($run['log'])->toBe([]);
})->with([
    'given' => [['name' => 'Post'], true, 0],
    'given, no terminal' => [['name' => 'Post'], false, 0],
    'omitted, no terminal' => [[], false, 1],
    'blank, no terminal' => [['name' => ' '], false, 1],
]);

it('hands over a check that accepts a real name and returns true', function (string $name) {
    ($this->recording)([], ['Post']);
    $command = ($this->recording)([], ['Post'])['command'];

    expect(($command->check)($name))->toBe(true);
})->with(['Post', '0', ' x ', 'Admin/Post']);

it('hands over a check that throws the same exception for an empty name', function (string $name) {
    $command = ($this->recording)([], ['Post'])['command'];

    expect(fn () => ($command->check)($name))->toThrow(RuntimeException::class, 'The model name cannot be empty.');
})->with(['empty' => [''], 'spaces' => ['   '], 'tab and newline' => ["\t\n"]]);

it('turns the check into the prompt error text through the validator', function () {
    $command = ($this->recording)([], ['Post'])['command'];
    $validator = (new ReflectionMethod($command, 'promptValidator'))->invoke($command, $command->check);

    expect($validator(' '))->toBe('The model name cannot be empty.')->and($validator('Post'))->toBeNull();
});
