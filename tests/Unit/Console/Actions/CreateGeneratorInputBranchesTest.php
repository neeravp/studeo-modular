<?php

use Illuminate\Support\Facades\File;
use Studeo\Modular\Console\Actions\CreateCommandAction;
use Studeo\Modular\Console\Actions\CreateControllerAction;
use Studeo\Modular\Console\Actions\CreateFactoryAction;
use Studeo\Modular\Console\Actions\CreateListenerAction;
use Studeo\Modular\Console\Actions\CreateModuleAction;
use Studeo\Modular\Console\Actions\CreateNotificationAction;
use Studeo\Modular\Console\Actions\CreateResourceAction;
use Studeo\Modular\Console\Actions\CreateSeederAction;
use Studeo\Modular\DataObjects\CommandGenerationOptions;
use Studeo\Modular\DataObjects\ListenerGenerationOptions;
use Studeo\Modular\DataObjects\ModuleDefinition;
use Studeo\Modular\DataObjects\NotificationGenerationOptions;
use Studeo\Modular\Tests\Support\SignatureReader;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->rootModulesPath = config('modular.root_path');
    File::deleteDirectory($this->rootModulesPath);
    File::ensureDirectoryExists($this->rootModulesPath);

    $this->module = new ModuleDefinition(name: 'Blog', namespace: 'Studeo\\Blog', path: $this->rootModulesPath.'/Blog');

    app(CreateModuleAction::class)->execute($this->module);
});

afterEach(function () {
    File::deleteDirectory($this->rootModulesPath);
});

it('reads the blank and filled helpers the way the old null-or-trimmed-empty checks did', function (?string $value) {
    expect(blank($value))->toBe($value === null || trim($value) === '')
        ->and(filled($value))->toBe($value !== null && trim($value) !== '');
})->with([null, '', '   ', "\t\n", '0', ' 0 ', 'x', ' x ']);

it('uses an explicit command signature trimmed, and derives one for null, empty and whitespace', function (?string $given, string $signature) {
    $path = app(CreateCommandAction::class)->execute($this->module, 'DoIt', new CommandGenerationOptions(command: $given));

    expect(SignatureReader::of($path))->toBe($signature);
})->with([
    'null' => [null, 'blog:do-it'],
    'empty' => ['', 'blog:do-it'],
    'whitespace' => ['   ', 'blog:do-it'],
    'zero' => ['0', '0'],
    'padded' => ['  blog:custom  ', 'blog:custom'],
]);

it('derives the signature name by stripping a trailing Command only when something is left', function (string $name, string $signature) {
    $path = app(CreateCommandAction::class)->execute($this->module, $name);

    expect(SignatureReader::of($path))->toBe($signature);
})->with([
    ['Sync', 'blog:sync'],
    ['SyncCommand', 'blog:sync'],
    ['Command', 'blog:command'],
    ['CommandFoo', 'blog:command-foo'],
    ['FooCommandBar', 'blog:foo-command-bar'],
    ['CommandCommand', 'blog:command'],
    ['do_it', 'blog:do-it'],
]);

it('refuses a controller that is only the suffix before it looks at the module, and names the problem', function (string $name) {
    $ghost = new ModuleDefinition(name: 'Ghost', namespace: 'Studeo\\Ghost', path: $this->rootModulesPath.'/Ghost');

    expect(fn () => app(CreateControllerAction::class)->ensureCreatable($ghost, $name))
        ->toThrow(RuntimeException::class, "Controller name [{$name}] is only Controller; give it a name, e.g. Flight or FlightController.")
        ->and(fn () => app(CreateControllerAction::class)->execute($this->module, $name))
        ->toThrow(RuntimeException::class, "Controller name [{$name}] is only Controller;");
})->with(['Controller', 'controller', ' controller ']);

it('returns the controller name once the module exists and the controller does not, and checks them in that order', function () {
    $action = app(CreateControllerAction::class);
    $ghost = new ModuleDefinition(name: 'Ghost', namespace: 'Studeo\\Ghost', path: $this->rootModulesPath.'/Ghost');

    expect($action->ensureCreatable($this->module, 'Flight'))->toBe('FlightController')
        ->and(fn () => $action->ensureCreatable($ghost, 'Flight'))->toThrow(RuntimeException::class, 'Ghost')
        ->and(fn () => $action->ensureCreatable($this->module, 'Flight'))->not->toThrow(RuntimeException::class);

    $action->execute($this->module, 'Flight');

    expect(fn () => $action->ensureCreatable($this->module, 'Flight'))->toThrow(RuntimeException::class, 'Controller [FlightController] already exists in module [Blog].');
});

it('binds the factory model from an explicit name, and guesses it from the factory name when the model is blank', function (?string $model, string $bound) {
    $path = app(CreateFactoryAction::class)->execute($this->module, 'PostFactory', $model);

    expect(File::get($path))->toContain("use Studeo\\Blog\\Models\\{$bound};")->toContain("protected \$model = {$bound}::class;");
})->with([
    'null' => [null, 'Post'],
    'empty' => ['', 'Post'],
    'whitespace' => ['   ', 'Post'],
    'padded' => [' Comment ', 'Comment'],
]);

it('seeds the model through its factory only for a named model, and leaves run() empty for a blank one', function (?string $model, string $body) {
    File::ensureDirectoryExists($this->module->path.'/Models');
    File::ensureDirectoryExists($this->module->path.'/database/factories');
    File::put($this->module->path.'/Models/Post.php', "<?php\n");
    File::put($this->module->path.'/database/factories/PostFactory.php', "<?php\n");

    $path = app(CreateSeederAction::class)->execute($this->module, 'PostSeeder', $model);

    expect(File::get($path))->toContain($body);
})->with([
    'null' => [null, 'public function run(): void {}'],
    'empty' => ['', 'public function run(): void {}'],
    'whitespace' => ['   ', 'public function run(): void {}'],
    'padded' => [' Post ', 'Post::factory()->count(10)->create();'],
]);

it('types the listener event as object for a null, empty or whitespace event, and resolves anything else', function (?string $event, string $parameter) {
    $path = app(CreateListenerAction::class)->execute($this->module, 'Notify', new ListenerGenerationOptions(event: $event));

    expect(File::get($path))->toContain("public function handle({$parameter} \$event): void {}");
})->with([
    'null' => [null, 'object'],
    'empty' => ['', 'object'],
    'whitespace' => ['   ', 'object'],
    'padded' => [' Happened ', 'Happened'],
    'external' => ['App\\Events\\Out', 'Out'],
]);

it('treats a zero event or model as given, so it is refused for starting with a digit instead of read as blank', function (Closure $run, string $message) {
    expect(fn () => $run($this->module))->toThrow(RuntimeException::class, $message);
})->with([
    'factory model' => [fn (ModuleDefinition $module) => app(CreateFactoryAction::class)->execute($module, 'PostFactory', '0'), 'Invalid model name [0].'],
    'seeder model' => [fn (ModuleDefinition $module) => app(CreateSeederAction::class)->execute($module, 'PostSeeder', '0'), 'Invalid model name [0].'],
    'listener event' => [fn (ModuleDefinition $module) => app(CreateListenerAction::class)->execute($module, 'Notify', new ListenerGenerationOptions(event: '0')), 'Invalid event name [0].'],
]);

it('picks the markdown view from the option: none for null, derived for blank, trimmed otherwise', function (?string $markdown, ?string $view) {
    $result = app(CreateNotificationAction::class)->execute($this->module, 'InvoicePaid', new NotificationGenerationOptions(markdown: $markdown));
    $expected = $view === null ? null : $this->module->path.'/resources/views/'.str_replace('.', '/', $view).'.blade.php';

    expect($result->viewPath)->toBe($expected);
})->with([
    'null' => [null, null],
    'empty' => ['', 'mail.invoice-paid'],
    'whitespace' => ['   ', 'mail.invoice-paid'],
    'zero' => ['0', '0'],
    'padded' => [' mail.custom ', 'mail.custom'],
]);

it('derives the markdown view name by stripping a trailing Notification only when something is left', function (string $name, string $view) {
    $result = app(CreateNotificationAction::class)->execute($this->module, $name, new NotificationGenerationOptions(markdown: ''));

    expect($result->viewPath)->toBe($this->module->path."/resources/views/mail/{$view}.blade.php");
})->with([
    ['InvoicePaid', 'invoice-paid'],
    ['InvoicePaidNotification', 'invoice-paid'],
    ['Notification', 'notification'],
    ['NotificationFoo', 'notification-foo'],
    ['FooNotificationBar', 'foo-notification-bar'],
    ['NotificationNotification', 'notification'],
]);

it('makes a collection from the flag or from a name ending in Collection, and a resource otherwise', function (string $name, bool $flag, string $file, string $stubMarker) {
    $path = app(CreateResourceAction::class)->execute($this->module, $name, $flag);

    expect($path)->toBe($this->module->path."/ApiResources/{$file}.php")
        ->and(File::get($path))->toContain($stubMarker);
})->with([
    ['Post', false, 'PostResource', 'extends JsonResource'],
    ['Post', true, 'PostCollection', 'extends ResourceCollection'],
    ['PostCollection', false, 'PostCollection', 'extends ResourceCollection'],
    ['post_collection', false, 'PostCollection', 'extends ResourceCollection'],
    ['Collection', false, 'Collection', 'extends ResourceCollection'],
    ['PostResource', false, 'PostResource', 'extends JsonResource'],
    ['PostResource', true, 'PostResourceCollection', 'extends ResourceCollection'],
]);
